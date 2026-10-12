<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prerequisites: what a character must HOLD before an encounter can be drawn for them. A list per encounter, and
 * an empty list is today's behaviour. Judged where the pool is built, held and never spent (a cost is an outcome).
 *
 * No CHECK on kind-specific columns: `item_id` and `entity_id` cascade, and MySQL refuses a CHECK on a cascading
 * column (error 3823). "A flag needs a slug, an item an item" lives in `EncounterRequest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encounter_prerequisites', function (Blueprint $table): void {
            $table->comment('One condition an encounter needs a character to hold before it can be drawn. All of an encounter\'s rows must hold; none is the same as no condition.');

            $table->id()->comment('Primary key.');
            $table->foreignId('encounter_id')->comment('The encounter it guards. Cascades.')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0)->comment('Order on the form, ascending. Nothing reads it.');
            $table->string('kind', 16)->comment('PrerequisiteKind: flag, item, scan_files, creds, skill, standing or district.');
            $table->string('subject', 100)->nullable()->comment('A flag slug (flag), a skill slug (skill) or a district slug (district). NULL for the rest.');
            $table->decimal('amount', 10, 2)->nullable()->comment('How many (item, scan_files), how much (creds), what level (skill) or what standing (standing). NULL for flag and district.');
            $table->foreignId('item_id')->nullable()->comment('For kind item. Cascades: a deleted item removes the condition.')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->comment('For kind standing. Cascades with the entity.')->constrained()->cascadeOnDelete();
            $table->boolean('negate')->default(false)->comment('True flips the answer: the flag is NOT held, fewer than amount, standing below amount.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encounter_prerequisites');
    }
};
