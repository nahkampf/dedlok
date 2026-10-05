<?php

declare(strict_types=1);

namespace App\Combat;

/**
 * The riders a weapon or a round carries: at most one of each kind, each a chance, a number of
 * turns, and (for damage over time) an amount per turn.
 *
 * Pure, like `DamageProfile`, so the item editor can judge a draft that is not saved yet.
 *
 * ## Stored shape
 *
 * A list in `RiderKind` order: `[{kind, chance, turns, amount}]`, `amount` 0 for a stun.
 *
 * ## A round's riders ADD to the weapon's
 *
 * Unlike a damage profile, which a round REPLACES: an incendiary round adds burning to
 * whatever the gun already does, it does not take the gun's stun away. `combine()` is that
 * rule. Two riders of one kind, one from each, both roll; what stacking two burns means is the
 * fight engine's question, and nothing reads riders until it exists.
 */
final class Riders
{
    public const MAX_TURNS = 10;

    public const MAX_AMOUNT = 100;

    /**
     * What the form posted, cleaned: known kinds with a chance, in a fixed order.
     *
     * @param  array<string, mixed>  $raw  kind => [chance, turns, amount]
     * @return list<array{kind: string, chance: int, turns: int, amount: int}>
     */
    public static function clean(array $raw): array
    {
        $clean = [];

        foreach (RiderKind::cases() as $kind) {
            $row = (array) ($raw[$kind->value] ?? []);

            if (! is_numeric($row['chance'] ?? null) || (int) $row['chance'] < 1) {
                continue;
            }

            $clean[] = [
                'kind' => $kind->value,
                'chance' => (int) $row['chance'],
                'turns' => (int) ($row['turns'] ?? 0),
                'amount' => $kind->damaging() ? (int) ($row['amount'] ?? 0) : 0,
            ];
        }

        return $clean;
    }

    /**
     * What is wrong with a set of riders, or null when it is fine. A blank row is no rider.
     *
     * @param  array<string, mixed>  $raw  as posted, so a typo'd kind is caught rather than cleaned away
     */
    public static function problem(array $raw): ?string
    {
        foreach ($raw as $name => $row) {
            $kind = RiderKind::tryFrom((string) $name);
            $row = (array) $row;

            if (self::blank($row)) {
                continue;
            }

            if ($kind === null) {
                return 'unknown_kind';
            }

            if (! self::whole($row['chance'] ?? null, 1, 100)) {
                return 'bad_chance';
            }

            if (! self::whole($row['turns'] ?? null, 1, self::MAX_TURNS)) {
                return 'bad_turns';
            }

            $amount = $row['amount'] ?? null;

            if (! $kind->damaging()) {
                if ($amount !== null && $amount !== '' && (string) $amount !== '0') {
                    return 'stun_amount';
                }
            } elseif (! self::whole($amount, 1, self::MAX_AMOUNT)) {
                return 'bad_amount';
            }
        }

        return null;
    }

    /**
     * A weapon's riders and its loaded round's together: the round adds, it does not replace.
     *
     * @param  list<array{kind: string, chance: int, turns: int, amount: int}>  $weapon
     * @param  list<array{kind: string, chance: int, turns: int, amount: int}>|null  $round
     * @return list<array{kind: string, chance: int, turns: int, amount: int}>
     */
    public static function combine(array $weapon, ?array $round): array
    {
        return array_values(array_merge($weapon, $round ?? []));
    }

    /** @param  array<string, mixed>  $row */
    private static function blank(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && $value !== '' && (string) $value !== '0') {
                return false;
            }
        }

        return true;
    }

    private static function whole(mixed $value, int $min, int $max): bool
    {
        return is_numeric($value) && (int) $value == $value && (int) $value >= $min && (int) $value <= $max;
    }
}
