<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a machine is holding, and how many times it has been robbed.
 *
 * A NODE'S INVENTORY IS ITS OWN MODEL, not the character inventory with a
 * different owner column, and CYBERSPACE.md says why: what is in a node is
 * raided rather than carried, it is safe from the losses a character takes when
 * ICE beats them, and it restocks on a clock. That is a different set of rules
 * from a stash, so it gets a different table rather than a flag on the same
 * one.
 *
 * THREE KINDS IN ONE TABLE, because they are one question — what is in there? —
 * and a screen listing a node's contents wants one query rather than three.
 * Which columns mean anything depends on the kind, and a CHECK per kind says
 * so, the same shape `deck_modules` uses for its one-measure rule.
 *
 *   creds  an amount, and nothing else
 *   item   a pointer at the catalogue and a quantity
 *   data   a FILE, which is the interesting one
 *
 * A FILE IS NOT A REWARD, IT IS AN OBJECT. Every one has a size in QB, so
 * carrying it out costs turns and a bigger haul is an exposed one; an
 * encryption type, which is a skill somebody either has or does not; and TWO
 * values — what a buyer pays for it sealed, and what they pay once it is
 * broken. That gap is where a sell-it-now player and a sit-on-it player
 * diverge, and it is the whole reason decryption is a mechanic rather than a
 * button.
 *
 * Files are generated rather than authored, which is why they are rows here
 * with their own numbers and not `items` rows: there is no catalogue of files
 * and there should not be one.
 *
 * `systems.raids` is the other half. **The counter is on the SYSTEM and not on
 * the character** — three successful raids by anybody and the books get
 * reconciled, which is what stops a perfectly executed loop from being a
 * permanent riskless income. Nothing increments it yet, because nothing can
 * raid: extraction is the run, and the run is phase 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_contents', function (Blueprint $table): void {
            $table->comment('What a machine on the grid is holding: creds, catalogue items, and generated data files. Raided rather than carried, and restocked nightly.');

            $table->id()->comment('Surrogate key. Nothing outside this table references it.');

            $table->foreignId('system_id')
                ->comment('The machine holding it. Cascades: contents have no meaning without the node.')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('kind', 16)->comment('creds, item or data. Which of the columns below mean anything depends on it — see the CHECK constraints.');

            $table->unsignedInteger('amount')->nullable()->comment('Creds, for a creds row. NULL on anything else.');

            $table->foreignId('item_id')
                ->nullable()
                ->comment('The catalogue row, for an item. NULL on anything else.')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('quantity')->nullable()->comment('How many of the item. NULL on anything else.');

            // ---- a file -----------------------------------------------------
            $table->string('label', 80)->nullable()->comment('What the file calls itself, for a screen to show. NULL on anything that is not data.');
            $table->unsignedInteger('size_qb')->nullable()->comment('How big the file is, in QB. Carrying it out costs turns in proportion: a greedy haul is an exposed one.');

            $table->foreignId('encryption_skill_id')
                ->nullable()
                ->comment('What it is encrypted with, as the skill that breaks it. Everything comes off a system sealed.')
                ->constrained('skills')
                ->nullOnDelete();

            $table->unsignedInteger('value_sealed')->nullable()->comment('What a buyer pays for it encrypted.');
            $table->unsignedInteger('value_broken')->nullable()->comment('What a buyer pays once it is decrypted. The gap is why decryption is a mechanic rather than a button.');

            $table->timestamps();

            // Everything a node holds, in one read.
            $table->index(['system_id', 'kind'], 'idx_system_contents_system_kind');
        });

        Schema::table('systems', function (Blueprint $table): void {
            $table->unsignedTinyInteger('raids')->default(0)->after('grade')
                ->comment('Successful raids on this machine by anybody. Three and it locks down and relocates; relocation resets it. Nothing increments it yet - extraction is the run.');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `system_contents` ADD CONSTRAINT `chk_system_contents_kind` CHECK (`kind` IN ('creds', 'item', 'data'))");

        // Each kind carries its own columns and none of the others'. A row that
        // is half a file and half a pile of creds is not a thing anything knows
        // how to show.
        DB::statement(
            'ALTER TABLE `system_contents` ADD CONSTRAINT `chk_system_contents_shape` CHECK ('
            ."(`kind` = 'creds' AND `amount` IS NOT NULL AND `item_id` IS NULL AND `size_qb` IS NULL)"
            ." OR (`kind` = 'item' AND `item_id` IS NOT NULL AND `quantity` IS NOT NULL AND `amount` IS NULL AND `size_qb` IS NULL)"
            ." OR (`kind` = 'data' AND `size_qb` IS NOT NULL AND `value_sealed` IS NOT NULL AND `value_broken` IS NOT NULL AND `amount` IS NULL AND `item_id` IS NULL))"
        );

        // Breaking it is worth doing, or the second number is decoration.
        DB::statement(
            'ALTER TABLE `system_contents` ADD CONSTRAINT `chk_system_contents_values` CHECK ('
            .'`value_broken` IS NULL OR `value_sealed` IS NULL OR `value_broken` >= `value_sealed`)'
        );
    }

    public function down(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->dropColumn('raids');
        });

        Schema::dropIfExists('system_contents');
    }
};
