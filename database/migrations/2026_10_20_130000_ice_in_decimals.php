<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ICE and icebreakers deal and take hundredths, as Hit Points and Synaptic Integrity do, so a skill's few percent is not
 * rounded away. MySQL only: SQLite stores a fraction in any numeric column, and the models cast through PointsCast.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string, 2: string}> table.column => [null?, default, comment] */
    private const COLUMNS = [
        'ice.integrity' => ['NOT NULL', '', 'Its hit points: what an icebreaker grinds down, to two decimals. A BASE like every deck measure, resolved rather than read, and the number most likely to move once something can actually fight.'],
        'ice.attack' => ['NOT NULL', '', 'Damage to Synaptic Integrity on a landed hit, to two decimals. A base. Resolves through App\\Stats\\DamageResolver when anything fights, so a resistance is a modifier rather than code.'],
        'runs.ice_integrity' => ['NULL', '', 'What is LEFT of the ICE, ground down turn by turn, to two decimals. Carried here rather than read off the catalogue because the catalogue says what an ICE is and this says what this fight has done to it. NULL when nothing is fighting.'],
        'runs.ice_attack' => ['NULL', '', "The attack of the ICE in play when it is a fortress's own crafted one, to two decimals; NULL uses the catalogue row's attack."],
        'runs.ice_damage' => ['NOT NULL', 'DEFAULT 0', 'Integrity this runner has taken off the ICE so far, to two decimals; the ICE targets the one who has hurt it most.'],
        'hunts.ice_integrity' => ['NOT NULL', '', 'What is left of it, to two decimals. Starts at the drawn ICE row\'s own integrity and counts down as the fight is fought.'],
    ];

    public function up(): void
    {
        $this->change('DECIMAL(8,2) UNSIGNED');
    }

    public function down(): void
    {
        // Fractions are rounded to a whole figure on the way back.
        $this->change('INT UNSIGNED', round: true);
    }

    private function change(string $type, bool $round = false): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::COLUMNS as $path => [$null, $default, $comment]) {
            [$table, $column] = explode('.', $path);

            if ($round) {
                DB::statement("UPDATE `{$table}` SET `{$column}` = ROUND(`{$column}`) WHERE `{$column}` IS NOT NULL");
            }

            DB::statement(sprintf('ALTER TABLE `%s` MODIFY `%s` %s %s %s COMMENT %s', $table, $column, $type, $null, $default, DB::getPdo()->quote($comment)));
        }
    }
};
