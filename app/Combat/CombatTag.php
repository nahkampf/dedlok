<?php

declare(strict_types=1);

namespace App\Combat;

use App\Support\Text;

/**
 * What an opponent does to somebody it beats, besides leaving them at zero hit points.
 *
 * ICE's `DefeatTag` shape: a fixed vocabulary, so a new kind of bad night is a case and a string and never
 * a new branch in the fight. The numbers are in `config/combat.php`.
 *
 * ## The default differs from ICE's
 *
 * `npcs.tags` NULL means the DEFAULT, `[Mugged]` — every standard enemy robs. ANY list REPLACES it:
 * `[Maim]` maims and does not rob, `[Mugged, Maim]` does both. ICE treats null and `[]` as one answer;
 * here they are not, because an opponent that takes nothing from you is not a case anybody has asked for.
 */
enum CombatTag: string
{
    /** Takes a share of ALL the creds carried (`combat.mugging`), through the wallet. */
    case Mugged = 'mugged';

    /** Deepens `physical_trauma`: a share of maximum hit points lost until a clinic mends it (`combat.maim`). */
    case Maim = 'maim';

    public function label(): string
    {
        return Text::get("combat.tags.{$this->value}.label");
    }

    public function hint(): string
    {
        return Text::get("combat.tags.{$this->value}.hint");
    }

    /** @return list<self> what an opponent with no tags of its own carries */
    public static function standard(): array
    {
        return [self::Mugged];
    }

    /**
     * The tags a stored list means. NULL is the default; anything else is exactly what it names, in the
     * vocabulary's order, with anything unknown dropped (a case retired since the row was written).
     *
     * @param  list<string>|null  $stored
     * @return list<self>
     */
    public static function fromStored(?array $stored): array
    {
        if ($stored === null) {
            return self::standard();
        }

        return array_values(array_filter(self::cases(), static fn (self $tag): bool => in_array($tag->value, $stored, true)));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $tag): string => $tag->value, self::cases());
    }
}
