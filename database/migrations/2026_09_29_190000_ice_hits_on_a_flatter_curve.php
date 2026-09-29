<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ICE damage on a flatter curve: attack 7 → 13 and hit chance 15% → 50%,
 * straight lines across the twelve levels.
 *
 * What a fight costs is attack × hit chance × the swings it lasts, and both
 * numbers climbed together, so damage a turn grew about fifty-fold from ICE I
 * to ICE XII while a Synaptic Integrity pool grows about three-fold. An ICE XII
 * fight cost ~866 SI against a pool near 100. Attack alone could not fix it —
 * a survivable XII would have hit weaker than ICE I — so both come down.
 *
 * With the matching icebreaker and Attack Vectors 5, a fight now costs roughly
 * a sixth of a starting pool at ICE I and nearly a whole maxed pool at ICE XII,
 * about halved by every resistance at once. Integrity is untouched, so fights
 * last as long as they did and twenty turns is still never enough. A miss
 * still does nothing.
 *
 * Keyed by level, so it rewrites the catalogue whatever the row ids are.
 */
return new class extends Migration
{
    /** level => [attack, skill (hit chance, per cent)] */
    private const CURVE = [
        1 => [7, 15], 2 => [8, 18], 3 => [8, 21], 4 => [9, 25],
        5 => [9, 28], 6 => [10, 31], 7 => [10, 34], 8 => [11, 37],
        9 => [11, 40], 10 => [12, 44], 11 => [12, 47], 12 => [13, 50],
    ];

    /** What it was, for `down()`. */
    private const BEFORE = [
        1 => [7, 15], 2 => [9, 22], 3 => [11, 29], 4 => [14, 36],
        5 => [17, 44], 6 => [21, 51], 7 => [25, 58], 8 => [30, 66],
        9 => [36, 73], 10 => [42, 80], 11 => [49, 88], 12 => [57, 95],
    ];

    public function up(): void
    {
        $this->write(self::CURVE);
    }

    public function down(): void
    {
        $this->write(self::BEFORE);
    }

    /** @param  array<int, array{int, int}>  $curve */
    private function write(array $curve): void
    {
        foreach ($curve as $level => [$attack, $skill]) {
            DB::table('ice')->where('level', $level)->update(['attack' => $attack, 'skill' => $skill]);
        }
    }
};
