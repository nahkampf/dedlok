<?php

declare(strict_types=1);

use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;

/**
 * The four operating-system skills stop granting +1% of base per level to every operating-system check.
 *
 * AuraOS levels were scaling the Prism-V check, and the level table is already the whole curve (`cyberspace.os.values`),
 * so the bonus double-counted and would have pushed a maxed AuraOS past the intended 80%. A skill now does nothing for
 * any other operating system. Kernel Exploitation, which reaches every OS check on purpose, is untouched.
 */
return new class extends Migration
{
    private const SLUGS = ['auroos', 'prismv', 'ironclad', 'lattice'];

    public function up(): void
    {
        Skill::query()->whereIn('slug', self::SLUGS)->update(['modifiers' => null]);
    }

    public function down(): void
    {
        foreach (self::SLUGS as $slug) {
            Skill::query()->where('slug', $slug)->first()?->update([
                'modifiers' => array_map(
                    static fn (int $level): array => [
                        'op' => 'percentage_of_base', 'level' => $level, 'amount' => 1,
                        'target' => ['all_of' => ['check', 'operating-systems']],
                    ],
                    range(1, 5),
                ),
            ]);
        }
    }
};
