<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\System;
use App\Programs\ProgramKind;
use App\Stats\Resolver;
use App\Stats\SkillStats;

/**
 * The numbers a crafted fortress ICE or IDS carries on its own inventory row, and how they decay.
 *
 * **They are the MAKER's.** At the moment of crafting the base values are scaled by the maker's `defence_quality` stat
 * (a percent, from their skills), so a skilled maker's product is worth more and two copies differ. **Decay runs only
 * while installed**: `maintained_at` is the clock, set on installing and on maintenance, cleared on taking down (which
 * freezes the then-effective numbers as the new base). Effective = base less a percent per day, never under a floor.
 */
final class FortressDefence
{
    /** Stamp a freshly crafted row. No-op for a program that is not a fortress defence. */
    public static function stamp(Character $maker, InventoryItem $row): void
    {
        $numbers = self::numbersFor($maker, $row->item);

        if ($numbers !== []) {
            $row->forceFill(['defence' => $numbers])->save();
        }
    }

    /** The numbers a copy would carry if `$maker` made it (null maker: the base). Used when a producer makes one. @return array<string, mixed> */
    public static function numbersFor(?Character $maker, Item $item): array
    {
        $key = match ($item->program?->kind) {
            ProgramKind::FortressIce => 'ice',
            ProgramKind::FortressIds => 'ids',
            ProgramKind::Cloak => 'cloak',
            ProgramKind::Booster => 'booster',
            default => null,
        };

        if ($key === null) {
            return [];
        }

        // A Booster is its boost alone: the program's own figure, scaled by the maker. It neither decays nor is maintained.
        if ($key === 'booster') {
            return self::scaled($maker, $key, ['boost' => (float) ($item->program->produces['boost'] ?? 0)]);
        }

        // A Cloak is its stealth alone, scaled by the maker, and it too neither decays nor is maintained.
        if ($key === 'cloak') {
            $stealth = $item->program->produces['strength'] ?? null;

            return self::scaled($maker, $key, $stealth === null ? null : ['strength' => (float) $stealth]);
        }

        $values = self::scaled($maker, $key);

        // `crafted` is what it came out as: maintenance may raise the numbers, but not past a ceiling over this.
        return $values + ['crafted' => $values, 'cut' => 0, 'maintained_at' => null];
    }

    /**
     * The base numbers of one defence ('ice', 'ids', 'cloak') scaled by the maker's quality: the general `defence_quality`
     * plus, for ICE and IDS, the product's own `defence_quality.<key>`. A Booster (whose base is handed in) and a Cloak take
     * only their own. A null maker is the base.
     *
     * @param  array<string, float>|null  $base
     * @return array<string, int|float>
     */
    private static function scaled(?Character $maker, string $key, ?array $base = null): array
    {
        $base ??= (array) config("cyberspace.fortress.defence.{$key}", []);
        $quality = 0.0;

        if ($maker !== null) {
            $resolver = app(Resolver::class);
            $modifiers = $maker->modifiers();
            $quality = in_array($key, ['booster', 'cloak'], true) ? 0.0 : (float) $resolver->resolve('defence_quality', 0.0, $modifiers)->value;

            if (in_array($key, ['ice', 'ids', 'booster', 'cloak'], true)) {
                $quality += (float) $resolver->resolve(SkillStats::QUALITY_PREFIX.$key, 0.0, $modifiers)->value;
            }
        }

        $scale = 1 + max(0.0, $quality) / 100;

        return array_map(fn ($v): int|float => $key === 'booster' ? round($v * $scale, 1) : (int) round($v * $scale), $base);
    }

    /** @return array<string, int> attack/integrity (ICE) or detection (IDS), after decay */
    public static function effective(InventoryItem $row): array
    {
        $stored = (array) $row->defence;
        $at = $stored['maintained_at'] ?? null;
        unset($stored['maintained_at'], $stored['crafted'], $stored['cut'], $stored['carry']);

        if ($at === null) {
            return array_map('intval', $stored);
        }

        $days = max(0.0, (now()->getTimestamp() - (int) $at) / 86400);
        $loss = min(1 - (float) config('cyberspace.fortress.defence.floor', 0.5), $days * (float) config('cyberspace.fortress.defence.decay_per_day', 0.02));

        return array_map(fn ($v): int => (int) floor($v * (1 - $loss)), $stored);
    }

    /** Called on installing: the clock starts. */
    public static function installed(InventoryItem $row): void
    {
        if (isset($row->defence['crafted'])) {
            $row->forceFill(['defence' => array_merge($row->defence, ['maintained_at' => now()->getTimestamp()])])->save();
        }
    }

    /** Called on taking down: settle the decay, then freeze. */
    public static function uninstalled(InventoryItem $row): void
    {
        if (isset($row->defence['crafted'])) {
            $keep = array_intersect_key((array) $row->defence, ['crafted' => 1, 'cut' => 1]);
            $row->forceFill(['defence' => self::effective($row) + $keep + ['maintained_at' => null]])->save();
        }
    }

    /** Maintenance: whoever holds the permission restores the decay clock (and with it the values). */
    public static function maintain(Character $who, InventoryItem $row, System $fortress): ?string
    {
        if (! FortressAccess::can($who, $fortress, 'maintain')) {
            return 'not_yours';
        }

        if (! isset($row->defence['crafted']) || (int) $row->fortress_system_id !== (int) $fortress->getKey()) {
            return 'not_hosted';
        }

        $resolver = app(Resolver::class);
        $boost = max(0.0, (float) $resolver->resolve('maintenance_boost', 0.0, $who->modifiers())->value);
        $efficiency = max(0.0, (float) $resolver->resolve('maintenance_efficiency', 0.0, $who->modifiers())->value);

        $defence = (array) $row->defence;
        $crafted = (array) ($defence['crafted'] ?? []);
        $ceiling = 1 + (float) config('cyberspace.fortress.defence.boost_cap', 0.5);

        // Maintenance restores the decay clock and, with skill, raises each value a little (never past a ceiling over
        // what it was crafted as) and cuts what the copy demands (never past a cap).
        foreach ($crafted as $key => $original) {
            if (isset($defence[$key]) && $boost > 0) {
                $defence[$key] = (int) min(floor($original * $ceiling), ceil($defence[$key] * (1 + $boost / 100)));
            }
        }

        $defence['cut'] = min((float) config('cyberspace.fortress.defence.cut_cap', 50), (float) ($defence['cut'] ?? 0) + $efficiency);
        $defence['maintained_at'] = now()->getTimestamp();
        $row->forceFill(['defence' => $defence])->save();

        return null;
    }
}
