<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Training System Profiling makes a read quicker.
 *
 * The times in `cyberspace.scan.minutes` are what a machine gives up to
 * somebody with no skill at all: a minute at the bottom of the grid, sixteen at
 * the top. This is the other half of that sentence — a trained profiler reads
 * the same machine in a little over half the time.
 *
 * FOUR STEPS RATHER THAN ONE PER LEVEL, because percentages SUM and apply once:
 * -10 at each of levels 2, 4, 6 and 8 is -40% at level 8, not 0.9^4. The
 * arithmetic is MECHANICS.md's and it is what keeps a resistance or a discount
 * from creeping towards certainty by multiplying against itself.
 *
 * Authored here rather than in the editor only because it is the content that
 * makes a mechanic shipped today mean something. It is a skill's `modifiers`
 * column like any other now, and the skill editor is where it gets tuned.
 */
return new class extends Migration
{
    private const SLUG = 'systemprofiling';

    public function up(): void
    {
        $grants = [];

        foreach ([2, 4, 6, 8] as $level) {
            $grants[] = [
                'level' => $level,
                'op' => 'percentage_of_base',
                'amount' => -10,
                'target' => ['id' => 'scan_duration'],
            ];
        }

        DB::table('skills')->where('slug', self::SLUG)->update([
            'modifiers' => json_encode($grants),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('skills')->where('slug', self::SLUG)->update([
            'modifiers' => null,
            'updated_at' => now(),
        ]);
    }
};
