<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The five skills authored on 2026-09-28, brought to scale.
 *
 * They filled the stats nothing reached (docs/TODO.md, "Author skills until every
 * value has one behind it"), and review found the same trap in most of them:
 * nearly every stat here FLOORS, so a small percentage on a small base rounds
 * away and a flat amount on a small base swamps everything.
 *
 *  - Noise Seeding added 20 heat_decay a level from level 3, meant as 0.2:
 *    some 85 heat gone a night, which made heat meaningless. Now 0.2, so a
 *    maxed skill cools (0.5 + 0.6) x 1.4 = 1.5 a night, three times the base.
 *  - Attack Vectors' first four levels rounded away for eight icebreakers of
 *    twelve, and level 5's flat +2.5 was the whole skill, tripling the bottom
 *    of the ladder. The flat part is spread over levels 1, 3 and 5.
 *  - Memory Optimization's +12 QB flat took an 8 QB deck to 20. Percentages
 *    only: +4% a level.
 *  - Fractional Siphoning: +4% a level, a real 20% at the top.
 *  - Power Management's description promised heat and paid none. Overclocking's
 *    rule applies: the heat comes with the gain, +2% thermal load on each level
 *    that raises the supply by a percentage.
 *
 * And their books are RARE, not the ultra-rare that rank derived at creation —
 * ultra-rare is weight 0 in loot below grade 8, which made them near-absent.
 *
 * Content was authored in the editor, not by a migration, so a database
 * without these skills (a fresh one, the suite) is an ordinary no-op.
 */
return new class extends Migration
{
    private const SKILLS = ['noise_seeding', 'attack_vectors', 'memory_optimization', 'fractional_siphoning', 'power_management'];

    private function grants(): array
    {
        return [
            'noise_seeding' => [
                $this->grant(1, 'percentage_of_base', 20, 'heat_decay'),
                $this->grant(2, 'percentage_of_base', 20, 'heat_decay'),
                $this->grant(3, 'add', 0.2, 'heat_decay'),
                $this->grant(4, 'add', 0.2, 'heat_decay'),
                $this->grant(5, 'add', 0.2, 'heat_decay'),
            ],

            // Every level does something: the floor keeps +5% silent on small
            // bites, so the flat point carries levels 1, 3 and 5.
            'attack_vectors' => [
                $this->grant(1, 'add', 1, 'icebreaker_damage'),
                $this->grant(2, 'percentage_of_base', 5, 'icebreaker_damage'),
                $this->grant(3, 'add', 1, 'icebreaker_damage'),
                $this->grant(4, 'percentage_of_base', 5, 'icebreaker_damage'),
                $this->grant(5, 'add', 1, 'icebreaker_damage'),
            ],

            'memory_optimization' => $this->perLevel('percentage_of_base', 4, 'deck_quantabits'),

            'fractional_siphoning' => $this->perLevel('percentage_of_base', 4, 'siphon_yield'),

            'power_management' => [
                $this->grant(1, 'percentage_of_base', 2, 'deck_power_supply'),
                $this->grant(1, 'percentage_of_base', 2, 'deck_thermal_load'),
                $this->grant(2, 'percentage_of_base', 2, 'deck_power_supply'),
                $this->grant(2, 'percentage_of_base', 2, 'deck_thermal_load'),
                $this->grant(3, 'percentage_of_base', 2, 'deck_power_supply'),
                $this->grant(3, 'percentage_of_base', 2, 'deck_thermal_load'),
                $this->grant(4, 'add', 2, 'deck_power_supply'),
                $this->grant(5, 'add', 2, 'deck_power_supply'),
            ],
        ];
    }

    /** The grants as authored, for down(). */
    private function authored(): array
    {
        return [
            'noise_seeding' => [
                $this->grant(1, 'percentage_of_base', 20, 'heat_decay'),
                $this->grant(2, 'percentage_of_base', 20, 'heat_decay'),
                $this->grant(3, 'add', 20, 'heat_decay'),
                $this->grant(4, 'add', 20, 'heat_decay'),
                $this->grant(5, 'add', 20, 'heat_decay'),
            ],
            'attack_vectors' => [
                $this->grant(1, 'percentage_of_base', 2.5, 'icebreaker_damage'),
                $this->grant(2, 'percentage_of_base', 2.5, 'icebreaker_damage'),
                $this->grant(3, 'percentage_of_base', 2.5, 'icebreaker_damage'),
                $this->grant(4, 'percentage_of_base', 2.5, 'icebreaker_damage'),
                $this->grant(5, 'add', 2.5, 'icebreaker_damage'),
            ],
            'memory_optimization' => [
                $this->grant(1, 'percentage_of_base', 2, 'deck_quantabits'),
                $this->grant(2, 'percentage_of_base', 2, 'deck_quantabits'),
                $this->grant(3, 'add', 3, 'deck_quantabits'),
                $this->grant(4, 'add', 4, 'deck_quantabits'),
                $this->grant(5, 'add', 5, 'deck_quantabits'),
            ],
            'fractional_siphoning' => [
                $this->grant(1, 'percentage_of_base', 2, 'siphon_yield'),
                $this->grant(2, 'percentage_of_base', 2, 'siphon_yield'),
                $this->grant(3, 'percentage_of_base', 2, 'siphon_yield'),
                $this->grant(4, 'add', 2, 'siphon_yield'),
                $this->grant(5, 'add', 2, 'siphon_yield'),
            ],
            'power_management' => [
                $this->grant(1, 'percentage_of_base', 2, 'deck_power_supply'),
                $this->grant(2, 'percentage_of_base', 2, 'deck_power_supply'),
                $this->grant(3, 'percentage_of_base', 2, 'deck_power_supply'),
                $this->grant(4, 'add', 2, 'deck_power_supply'),
                $this->grant(5, 'add', 2, 'deck_power_supply'),
            ],
        ];
    }

    private function grant(int $level, string $op, int|float $amount, string $target): array
    {
        return ['level' => $level, 'op' => $op, 'amount' => $amount, 'target' => ['id' => $target]];
    }

    private function perLevel(string $op, int|float $amount, string $target): array
    {
        return array_map(fn (int $level): array => $this->grant($level, $op, $amount, $target), range(1, 5));
    }

    private function write(array $grants, string $rarity): void
    {
        foreach ($grants as $slug => $modifiers) {
            DB::table('skills')->where('slug', $slug)->update([
                'modifiers' => json_encode($modifiers),
                'updated_at' => now(),
            ]);
        }

        DB::table('items')
            ->whereIn('use_skill_id', DB::table('skills')->whereIn('slug', self::SKILLS)->select('id'))
            ->update(['rarity' => $rarity, 'updated_at' => now()]);
    }

    public function up(): void
    {
        $this->write($this->grants(), 'rare');
    }

    public function down(): void
    {
        $this->write($this->authored(), 'ultra_rare');
    }
};
