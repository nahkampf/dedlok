<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The IDS table, flattened at the top: grade 9 from 92 to 58.
 *
 * Defences are decided once, at creation, and stored — so the config change
 * reaches only machines made after it, and this is what moves the rest. See
 * `cyberspace.defences.ids` for the curve and why.
 *
 * ONLY A ROW STILL HOLDING ITS GRADE'S OLD VALUE IS REWRITTEN. Zero is a
 * machine with no IDS and stays one, and a machine told to be dangerous (a
 * mission node is a row with different numbers) keeps what it was told.
 * down() is the same walk the other way.
 */
return new class extends Migration
{
    private const OLD = [1 => 10, 2 => 18, 3 => 26, 4 => 34, 5 => 44, 6 => 54, 7 => 66, 8 => 78, 9 => 92];

    private const NEW = [1 => 10, 2 => 17, 3 => 24, 4 => 30, 5 => 36, 6 => 42, 7 => 48, 8 => 53, 9 => 58];

    /** @param  array<int, int>  $from  @param  array<int, int>  $to */
    private function move(array $from, array $to): void
    {
        foreach ($from as $grade => $value) {
            if ($value === $to[$grade]) {
                continue;
            }

            DB::table('systems')
                ->where('grade', $grade)
                ->where('ids_detection', $value)
                ->update(['ids_detection' => $to[$grade]]);
        }
    }

    public function up(): void
    {
        $this->move(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->move(self::NEW, self::OLD);
    }
};
