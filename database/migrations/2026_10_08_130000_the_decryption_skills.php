<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;

/**
 * Skills that make decryption faster and likelier.
 *
 * - **Cryptology** (rank 8): +2% decryption chance a level, for EVERY encryption — a modifier on the
 *   `decryption_chance` tag set.
 * - **Precomputation** (rank 8): -2% decryption cycle time a level, for every encryption —
 *   `decryption_cycle_seconds` is one stat for all of them.
 * - Each of the four encryption skills grants +2% chance a level for its OWN encryption
 *   (`decryption_chance.<slug>`).
 *
 * The first two sit in a category of their own, `Decryption`: the `Encryption` category is the ladder a file's
 * lock is drawn from, and a file must never be sealed with a skill that merely helps.
 *
 * A percentage "chance" is a MULTIPLIER on a cycle's chance (the roller's own), not percentage points: +2% turns
 * 50% into 51%.
 *
 * Keyed by slug and upserted, like the other content migrations, so running it where the rows exist changes only
 * their effects.
 */
return new class extends Migration
{
    private const ENCRYPTIONS = ['cinder', 'halcyon3', 'blackglass', 'ouroboros'];

    public function up(): void
    {
        $this->skill('cryptology', [
            'title' => 'Cryptology',
            'description' => 'The study of how locks are made, and so of where they give. Every cipher breaks a little '
                .'sooner for someone who has learned to think like the person who built it.',
            'category' => 'Decryption',
            'rank' => 8,
            'primary_attribute' => 'intellect',
            'secondary_attribute' => 'memory',
            'base_cost' => 800,
        ], static fn (int $level): array => [
            'op' => 'add', 'level' => $level, 'amount' => 2, 'target' => ['all_of' => ['decryption_chance']],
        ]);

        $this->skill('precomputation', [
            'title' => 'Precomputation',
            'description' => 'Doing the hard part before it is needed: tables of partial work, laid down in advance, so '
                .'that each attempt on a lock starts further along than the last.',
            'category' => 'Decryption',
            'rank' => 8,
            'primary_attribute' => 'intellect',
            'secondary_attribute' => 'memory',
            'base_cost' => 950,
        ], static fn (int $level): array => [
            'op' => 'percentage_of_base', 'level' => $level, 'amount' => -2, 'target' => ['id' => 'decryption_cycle_seconds'],
        ]);

        foreach (self::ENCRYPTIONS as $slug) {
            Skill::query()->where('slug', $slug)->first()?->update([
                'modifiers' => array_map(
                    static fn (int $level): array => [
                        'op' => 'add', 'level' => $level, 'amount' => 2, 'target' => ['id' => "decryption_chance.{$slug}"],
                    ],
                    range(1, 5),
                ),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['cryptology', 'precomputation'] as $slug) {
            $skill = Skill::query()->where('slug', $slug)->first();
            $skill?->imprint()->delete();
            $skill?->delete();
        }

        Skill::query()->whereIn('slug', self::ENCRYPTIONS)->update(['modifiers' => null]);
    }

    /** A skill on the market with five equal grants, one per level, and its imprint. */
    private function skill(string $slug, array $columns, Closure $grant): void
    {
        $skill = Skill::query()->updateOrCreate(['slug' => $slug], [
            ...$columns,
            'on_market' => true,
            'modifiers' => array_map($grant, range(1, 5)),
        ]);

        Imprint::ensureFor($skill);
        Imprint::syncFor($skill);
    }
};
