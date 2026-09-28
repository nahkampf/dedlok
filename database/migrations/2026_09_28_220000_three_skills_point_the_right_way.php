<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Three skills whose modifiers pointed the wrong way, from the balance audit.
 *
 * - Mitigation Protocols cost 1% of icebreaker damage a level — the only rank
 *   16 skill that made its owner worse. It gives 2% a level now.
 * - Advanced Routing modified `check.routing`, which nothing rolls, and
 *   carried a bandwidth and a power-draw grant besides. It is the routing
 *   hops and nothing else now.
 * - Power Management is NOT a flipped sign, though the audit read it as one:
 *   it trades temperature for power output, so raising `deck_thermal_load`
 *   alongside `deck_power_supply` is the skill. Levels 4 and 5 now pay the
 *   heat for their flat supply too, rather than getting it for free.
 *
 * Content was authored in the editor, not by a migration, so a database
 * without these skills (a fresh one, the suite) is an ordinary no-op.
 */
return new class extends Migration
{
    private static function each(string $op, int|float $amount, string $target, array $levels = [1, 2, 3, 4, 5]): array
    {
        return array_map(static fn (int $level): array => [
            'op' => $op,
            'level' => $level,
            'amount' => $amount,
            'target' => ['id' => $target],
        ], $levels);
    }

    /** @return list<array<string, mixed>> */
    private static function powerManagement(bool $heatAtTheTop): array
    {
        $modifiers = [];

        foreach ([1, 2, 3] as $level) {
            $modifiers[] = self::each('percentage_of_base', 2, 'deck_power_supply', [$level])[0];
            $modifiers[] = self::each('percentage_of_base', 2, 'deck_thermal_load', [$level])[0];
        }

        $modifiers = [...$modifiers, ...self::each('add', 2, 'deck_power_supply', [4, 5])];

        return $heatAtTheTop
            ? [...$modifiers, ...self::each('add', 2, 'deck_thermal_load', [4, 5])]
            : $modifiers;
    }

    private function write(array $bySlug): void
    {
        foreach ($bySlug as $slug => $modifiers) {
            DB::table('skills')->where('slug', $slug)->update([
                'modifiers' => json_encode($modifiers),
                'updated_at' => now(),
            ]);
        }
    }

    public function up(): void
    {
        $this->write([
            'mitigation_protocols' => self::each('percentage_of_base', 2, 'icebreaker_damage'),
            'advancedrouting' => self::each('add', 1, 'routing_hops'),
            'power_management' => self::powerManagement(true),
        ]);
    }

    public function down(): void
    {
        $this->write([
            'mitigation_protocols' => self::each('percentage_of_base', -1, 'icebreaker_damage'),
            'advancedrouting' => [
                ...self::each('percentage_of_base', 5, 'check.routing', [1]),
                ...self::each('percentage_of_base', 5, 'deck_bandwidth', [3]),
                ...self::each('percentage_of_base', -4, 'deck_power_draw', [5]),
                ...self::each('add', 1, 'routing_hops'),
            ],
            'power_management' => self::powerManagement(false),
        ]);
    }
};
