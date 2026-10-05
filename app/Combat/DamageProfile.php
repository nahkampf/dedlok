<?php

declare(strict_types=1);

namespace App\Combat;

use App\Stats\DamageOrigin;

/**
 * How a hit's damage is split across damage types: a map of type to percent share.
 *
 * Pure, so the item editor can judge a draft that is not saved yet. A katana is
 * `{slashing: 100}`; a stun baton `{blunt: 70, electrical: 30}`. The shares must name only
 * damage TYPES (not the older causes like `ice`) and sum to exactly 100, so that "a hit of 10"
 * splits into parts that add back to 10 and the engine never has to renormalise.
 */
final class DamageProfile
{
    /** @return list<string> the eight types' values, in the order the editor lists them */
    public static function types(): array
    {
        return array_map(static fn (DamageOrigin $origin): string => $origin->value, DamageOrigin::types());
    }

    /**
     * What the form posted, cleaned: only types, only positive whole shares, in a fixed order.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, int>
     */
    public static function clean(array $raw): array
    {
        $clean = [];

        foreach (self::types() as $type) {
            $share = $raw[$type] ?? null;

            if (is_numeric($share) && (int) $share > 0) {
                $clean[$type] = (int) $share;
            }
        }

        return $clean;
    }

    /**
     * What is wrong with a profile, or null when it is fine.
     *
     * @param  array<string, mixed>  $raw  as posted, so a typo'd type is caught rather than cleaned away
     */
    public static function problem(array $raw): ?string
    {
        foreach ($raw as $type => $share) {
            if (! in_array($type, self::types(), true) && $share !== null && $share !== '' && (string) $share !== '0') {
                return 'unknown_type';
            }

            if ($share !== null && $share !== '' && (! is_numeric($share) || (int) $share != $share || (int) $share < 0 || (int) $share > 100)) {
                return 'bad_share';
            }
        }

        return array_sum(self::clean($raw)) === 100 ? null : 'sum';
    }
}
