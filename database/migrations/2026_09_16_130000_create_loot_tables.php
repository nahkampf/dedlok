<?php

declare(strict_types=1);

use App\Cyberspace\SystemType;
use App\Market\Rarity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How good the thing a node drops is, by how hard the node is.
 *
 * Item loot used to be a uniform draw over every listed item in the catalogue,
 * which meant a grade 1 box could hand somebody the best program in the game.
 * Grade decided WHETHER an item appeared and never WHICH, so the number a scan
 * exists to discover said nothing about the prize.
 *
 * A TABLE RATHER THAN A CURVE IN CONFIG, because this is the dial a designer
 * will bend most often and per band: "grade 1 to 3 almost never gives anything
 * rare" and "only the top two grades give ultra-rare" are statements about
 * particular bands, and a formula that produced both would have more shape than
 * the intent it encodes.
 *
 * ROWS ARE WEIGHTS, NOT CHANCES. They are relative within a band, so a band can
 * be re-tuned without every other band's numbers moving. Weight 0 means never —
 * which is how ultra-rare is kept out of the bottom seven grades, rather than
 * by a rule in code.
 *
 * `system_type` IS NULLABLE AND EVERY SHIPPED ROW IS NULL. A null row applies
 * to any machine; a typed row would beat it for that type. Nothing needs typed
 * rows yet — type does not decide what KIND of thing a node holds — and the
 * column is here because that is the next question anybody will ask of this
 * table, and answering it should be an INSERT rather than a migration.
 */
return new class extends Migration
{
    /** grade band => weight per rarity. Relative within the band. */
    private const BANDS = [
        [1, 3, ['common' => 850, 'uncommon' => 140, 'rare' => 10, 'ultra_rare' => 0]],
        [4, 5, ['common' => 650, 'uncommon' => 280, 'rare' => 70, 'ultra_rare' => 0]],
        [6, 7, ['common' => 450, 'uncommon' => 350, 'rare' => 200, 'ultra_rare' => 0]],
        [8, 9, ['common' => 300, 'uncommon' => 330, 'rare' => 300, 'ultra_rare' => 70]],
    ];

    public function up(): void
    {
        Schema::create('loot_tables', function (Blueprint $table): void {
            $table->comment('How likely a machine of a given type and grade is to drop each rarity of item. Weights, relative within a band.');

            $table->id()->comment('Surrogate key. Nothing outside this table references it.');

            $table->string('system_type', 16)->nullable()
                ->comment('Which kind of machine this row is about. NULL applies to any, and every shipped row is NULL: type does not decide what a node holds yet. A typed row beats a NULL one.');

            $table->unsignedTinyInteger('grade_min')->comment('Lowest grade this row covers, inclusive.');
            $table->unsignedTinyInteger('grade_max')->comment('Highest grade this row covers, inclusive.');

            $table->string('rarity', 16)->comment('The rarity being weighted. Same vocabulary as items.rarity and the market.');

            $table->unsignedInteger('weight')->comment('Relative weight within the band. 0 means never - which is how ultra-rare is kept out of the low grades without a rule in code.');

            $table->timestamps();   // commented below: timestamps() returns void.

            $table->unique(['system_type', 'grade_min', 'grade_max', 'rarity'], 'uq_loot_tables_band');
            $table->index(['grade_min', 'grade_max'], 'idx_loot_tables_band');
        });

        $rows = [];
        $now = now();

        foreach (self::BANDS as [$min, $max, $weights]) {
            foreach ($weights as $rarity => $weight) {
                $rows[] = [
                    'system_type' => null,
                    'grade_min' => $min,
                    'grade_max' => $max,
                    'rarity' => $rarity,
                    'weight' => $weight,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('loot_tables')->insert($rows);

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `loot_tables` ADD CONSTRAINT `chk_loot_tables_band` CHECK (`grade_min` BETWEEN 1 AND 9 AND `grade_max` BETWEEN `grade_min` AND 9)');

        DB::statement(sprintf(
            "ALTER TABLE `loot_tables` ADD CONSTRAINT `chk_loot_tables_rarity` CHECK (`rarity` IN ('%s'))",
            implode("', '", array_column(Rarity::cases(), 'value')),
        ));

        DB::statement(sprintf(
            "ALTER TABLE `loot_tables` ADD CONSTRAINT `chk_loot_tables_type` CHECK (`system_type` IS NULL OR `system_type` IN ('%s'))",
            implode("', '", array_column(SystemType::cases(), 'value')),
        ));

        foreach ([
            'created_at' => 'When this row was written. The bands ship with the migration, so every row reads the same moment until somebody tunes one.',
            'updated_at' => 'Last tuned. A band is content: changing a weight changes what the world drops from the next pass.',
        ] as $column => $comment) {
            DB::statement(sprintf(
                'ALTER TABLE `loot_tables` MODIFY COLUMN `%s` TIMESTAMP NULL COMMENT %s',
                $column,
                DB::getPdo()->quote($comment),
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loot_tables');
    }
};
