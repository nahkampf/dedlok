<?php

declare(strict_types=1);

namespace App\Combat;

use App\Support\Text;

/**
 * Which SKILL aims a weapon, and which hand it fills. COMBAT.md.
 *
 * Not what it DOES: that is its damage profile, a separate question (a stun baton is a
 * `blunt` weapon that deals blunt and electrical damage). The class decides two things — the
 * `aim.<class>` stat a shooter's accuracy is read from, and whether the weapon is usable at
 * Ranged or at Close, which is also why a fighter carries one of each.
 *
 * The class-to-SKILL mapping is parked (the author is leaving skills for now); the cases are
 * the vocabulary. A new class is a case and a string.
 */
enum WeaponClass: string
{
    case Pistol = 'pistol';
    case Smg = 'smg';
    case Rifle = 'rifle';
    case Shotgun = 'shotgun';
    case Blade = 'blade';
    case Blunt = 'blunt';
    case Unarmed = 'unarmed';
    case Explosive = 'explosive';

    public function label(): string
    {
        return Text::get("combat.classes.{$this->value}");
    }

    /** Usable at Ranged and not at Close. The rest are Close weapons. */
    public function ranged(): bool
    {
        return match ($this) {
            self::Pistol, self::Smg, self::Rifle, self::Shotgun, self::Explosive => true,
            self::Blade, self::Blunt, self::Unarmed => false,
        };
    }

    /** Fires a calibre of round from a magazine, so it needs both. A thrown explosive does not. */
    public function firearm(): bool
    {
        return match ($this) {
            self::Pistol, self::Smg, self::Rifle, self::Shotgun => true,
            default => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $class): string => $class->value, self::cases());
    }
}
