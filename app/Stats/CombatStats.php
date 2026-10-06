<?php

declare(strict_types=1);

namespace App\Stats;

use App\Combat\WeaponClass;

/**
 * The numbers a fight reads, generated from the weapon classes the way `DamageStats` is generated
 * from the damage vocabularies. COMBAT.md, *The numbers are stats*.
 *
 *   aim.<class>           a POOL: how well somebody puts a shot where they mean to. Read through a
 *                         Sheet (`Character::stat()`); outside one a pool is zero.
 *   weapon_damage.<class> what a hit of that class does. Its BASE ARRIVES WITH THE EVENT (the weapon's
 *                         damage, as the round loaded changes it), so — like `damage.*` and unlike a
 *                         pool — it is resolved directly and a Sheet has no base for it.
 *
 * Both carry `combat` and the class as tags, and aim carries `aim`, damage `weapon_damage`, so an
 * implant is one modifier on `aim.pistol` and a stimulant one on `all_of: ['aim']`. A class is the
 * only thing that varies: a new `WeaponClass` case brings both stats with it.
 *
 * `defence` is NOT generated and not here: it is one declared stat (`stats.defence`, base in
 * `stats.bases`), the number an attack rolls against (there is no dodge).
 */
final class CombatStats
{
    public const AIM = 'aim';

    public const DAMAGE = 'weapon_damage';

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        $definitions = [];

        foreach (WeaponClass::cases() as $class) {
            $definitions[self::aimId($class)] = [
                'tags' => ['combat', self::AIM, $class->value],
                'min' => 0,
                'rounding' => 'tenth',
            ];

            // Unrounded: a hit's damage is scaled by its tier and split by its profile before it becomes
            // a whole number, and rounding the base first would round twice.
            $definitions[self::damageId($class)] = [
                'tags' => ['combat', self::DAMAGE, $class->value],
                'min' => 0,
                'rounding' => 'none',
            ];
        }

        return $definitions;
    }

    public static function aimId(WeaponClass $class): string
    {
        return self::AIM.'.'.$class->value;
    }

    public static function damageId(WeaponClass $class): string
    {
        return self::DAMAGE.'.'.$class->value;
    }

    /**
     * Which `stats.pools` entry a stat reads, so every `aim.<class>` shares the one `aim` pool and a
     * pooled stat with its own entry (`stealth`) reads that. The id itself when there is no sharing.
     */
    public static function poolKey(string $statId): string
    {
        return str_starts_with($statId, self::AIM.'.') ? self::AIM : $statId;
    }

    /**
     * What each `aim.<class>` reads, for the stat graph: the attributes of the shared pool.
     *
     * @return array<string, list<string>>
     */
    public static function dependencies(): array
    {
        $attributes = array_values((array) config('stats.pools.'.self::AIM.'.attributes', []));
        $dependencies = [];

        foreach (WeaponClass::cases() as $class) {
            $dependencies[self::aimId($class)] = $attributes;
        }

        return $dependencies;
    }
}
