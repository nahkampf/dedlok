<?php

declare(strict_types=1);

namespace App\Combat;

use App\Support\Text;

/**
 * The four places a piece of armour is WORN: head, torso, arms, legs. What the Equipment screen draws and what
 * the item editor offers.
 *
 * NOT `ArmourSlot`. That is where a hit LANDS — six locations, a left and a right — and the fight engine, an
 * NPC's per-part resistances and `worn_armour` keep using it. A wear slot is a group of those: wearing a piece
 * on Arms protects both arms, so one arm of armour cannot be worn and the other bare. A piece's stored
 * coverage is expanded to whole groups (`Armour::covers()`), which is why nothing under it had to change.
 */
enum WearSlot: string
{
    case Head = 'head';
    case Torso = 'torso';
    case Arms = 'arms';
    case Legs = 'legs';

    public function label(): string
    {
        return Text::get("combat.wear_slots.{$this->value}");
    }

    /** @return list<ArmourSlot> the hit locations this slot protects */
    public function covers(): array
    {
        return match ($this) {
            self::Head => [ArmourSlot::Head],
            self::Torso => [ArmourSlot::Torso],
            self::Arms => [ArmourSlot::ArmLeft, ArmourSlot::ArmRight],
            self::Legs => [ArmourSlot::LegLeft, ArmourSlot::LegRight],
        };
    }

    public static function of(ArmourSlot $slot): self
    {
        return match ($slot) {
            ArmourSlot::Head => self::Head,
            ArmourSlot::Torso => self::Torso,
            ArmourSlot::ArmLeft, ArmourSlot::ArmRight => self::Arms,
            ArmourSlot::LegLeft, ArmourSlot::LegRight => self::Legs,
        };
    }

    /**
     * Any mix of wear slots and hit locations, as the groups they name, in body order.
     *
     * @param  array<int, string>  $values
     * @return list<self>
     */
    public static function groupsOf(array $values): array
    {
        $groups = [];

        foreach ($values as $value) {
            $group = self::tryFrom((string) $value) ?? (ArmourSlot::tryFrom((string) $value) !== null ? self::of(ArmourSlot::from((string) $value)) : null);

            if ($group !== null) {
                $groups[$group->value] = $group;
            }
        }

        return array_values(array_filter(self::cases(), static fn (self $slot): bool => isset($groups[$slot->value])));
    }

    /**
     * The hit locations those groups protect, as stored values.
     *
     * @param  array<int, string>  $values
     * @return list<string>
     */
    public static function expand(array $values): array
    {
        return array_values(array_map(
            static fn (ArmourSlot $slot): string => $slot->value,
            array_merge(...array_map(static fn (self $group): array => $group->covers(), self::groupsOf($values)) ?: [[]]),
        ));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $slot): string => $slot->value, self::cases());
    }
}
