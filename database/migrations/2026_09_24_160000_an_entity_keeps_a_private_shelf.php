<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The loyalty store: what an entity will hand over to somebody who has earned it.
 *
 * ## A second DOOR onto the catalogue, not a second catalogue
 *
 * The items are ordinary `items` rows, typically with `on_market = false` —
 * which is already how content is withheld from the public shelf. Nothing here
 * describes a thing; it describes who may have one and what it costs them in
 * standing and loyalty.
 *
 * ## A shelf belongs to an (entity, GRADE) pair
 *
 * Not to an exchange row. An entity keeps up to five desks, one per grade, and
 * what a grade 4 desk keeps must not be buyable at the grade 1 desk round the
 * corner — but two grade 3 desks of the same entity are the same shop. So the
 * grade is a column here and `exchanges` is not referenced at all.
 *
 * ## No stock column, on purpose
 *
 * An entity's private shelf is not restocked by anything and cannot run out:
 * loyalty is the scarcity. A count is the column to add the day somebody wants
 * a limited run, and inventing one now would be a second thing for
 * `MarketSeeder` to not restock.
 *
 * ## `min_standing` is the SECOND gate, and usually nothing
 *
 * `exchanges.min_standing` already decides whether an agent deals with
 * somebody at all — the same gate their work reads. This one is per ITEM, for
 * the row an agent will only hand to somebody well past that line. Default 0,
 * CHECK that it cannot be negative: nobody sells at negative standing, and a
 * gruff agent is a row with 25 in this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_offers', function (Blueprint $table): void {
            $table->comment('What an entity will hand over for loyalty. A second door onto the items catalogue, never a second catalogue.');

            $table->id()->comment('Surrogate key. Nothing outside this table references it.');

            $table->foreignId('entity_id')
                ->comment('Whose shelf it is. The loyalty is spent out of this entity\'s books and nobody else\'s.')
                ->constrained('entities')
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('grade')
                ->comment('Which of the entity\'s desks keeps it, 1-5. NOT a foreign key to exchanges: an entity may keep several desks at one grade and they are the same shop.');

            $table->foreignId('item_id')
                ->comment('The catalogue row handed over. An ordinary item, usually with on_market false - this table is a second door onto the catalogue rather than a second catalogue.')
                ->constrained('items')
                ->cascadeOnDelete();

            $table->unsignedInteger('loyalty_price')
                ->comment('What it costs in loyalty. Loyalty never decays and is earned only by finishing work, so this is the whole scarcity - there is no stock column.');

            $table->decimal('min_standing', 6, 3)
                ->default(0)
                ->comment('Standing this particular row demands, on top of the desk\'s own gate in exchanges.min_standing. Almost always 0; a gruff agent keeping something back is a row with 25 here.');

            $table->timestamp('created_at')->nullable()->comment('When the row was written.');
            $table->timestamp('updated_at')->nullable()->comment('When the row was last changed.');

            // One offer per item per shelf: two rows would be two prices for
            // the same thing at the same desk, and nothing could say which.
            $table->unique(['entity_id', 'grade', 'item_id'], 'uq_entity_offers_shelf');

            // "What is on this shelf", which is the only listing this table has.
            $table->index(['entity_id', 'grade'], 'idx_entity_offers_shelf');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Nobody sells at negative standing — the same rule and the same
        // spelling as chk_exchanges_min_standing.
        DB::statement('ALTER TABLE `entity_offers` ADD CONSTRAINT `chk_entity_offers_min_standing` CHECK (`min_standing` >= 0)');

        // A desk is 1-5, like the exchange whose grade it mirrors.
        DB::statement('ALTER TABLE `entity_offers` ADD CONSTRAINT `chk_entity_offers_grade` CHECK (`grade` BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_offers');
    }
};
