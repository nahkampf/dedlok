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
 * SUPERSEDED, AND IT WAS WRONG TWICE. It granted -10% at levels 2, 4, 6 and 8;
 * `skills.max_level` is FIVE, so half of that could never fire, and the
 * remainder was at twice the scale this game wants. The migration that follows
 * it replaces the list with -4% at each of the five levels a skill actually
 * has. Left here as history rather than edited — see the rule about not
 * rewriting a migration that has run.
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
