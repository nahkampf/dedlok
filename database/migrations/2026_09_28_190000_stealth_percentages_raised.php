<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clandestine Intrusion Methods and Polymorphic Code: 3% of stealth a level
 * to 4%, so the two together are +40% at the top rather than +30%.
 *
 * Part of the grade 9 detection question: every watcher rolls against the
 * same stealth, so the ceiling is what moves all of them at once.
 *
 * Content was authored in the editor, not by a migration, so a database
 * without these skills (a fresh one, the suite) is an ordinary no-op.
 */
return new class extends Migration
{
    private const SKILLS = ['clandestineintrusion', 'polymorphiccode'];

    private function write(int|float $amount): void
    {
        $modifiers = array_map(static fn (int $level): array => [
            'op' => 'percentage_of_base',
            'level' => $level,
            'amount' => $amount,
            'target' => ['id' => 'stealth'],
        ], range(1, 5));

        DB::table('skills')->whereIn('slug', self::SKILLS)->update([
            'modifiers' => json_encode($modifiers),
            'updated_at' => now(),
        ]);
    }

    public function up(): void
    {
        $this->write(4);
    }

    public function down(): void
    {
        $this->write(3);
    }
};
