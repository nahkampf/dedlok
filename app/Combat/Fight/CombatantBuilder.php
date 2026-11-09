<?php

declare(strict_types=1);

namespace App\Combat\Fight;

use App\Combat\Riders;
use App\Combat\WeaponClass;
use App\Models\Ammunition;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Npc;
use App\Models\Weapon;
use App\Stats\CombatStats;
use App\Stats\Resolver;
use App\Stats\Sheet;
use App\Support\Points;
use App\Support\Text;

/**
 * Turns a character, or a plain description of an opponent, into a `Combatant`. Everything a fight
 * needs is worked out HERE, once, from the database — the engine reads none of it. A fight is fought
 * with a snapshot: a modifier that changes mid-fight is ignored by design.
 *
 * A character's weapons come from what they hold (`equippedRanged`, `equippedMelee`) and their
 * ranged weapon carries one `RoundSpec` per compatible round in the stash, plus the one loaded, so
 * Load can switch without a query. Bare hands are the Close fallback for everybody, from
 * `combat.unarmed`. An NPC never counts ammunition (its `rounds` is NULL).
 */
final class CombatantBuilder
{
    public static function forCharacter(Character $character): Combatant
    {
        $modifiers = $character->modifiers();
        $context = Sheet::contextFor($character);
        $resolver = app(Resolver::class);
        $damage = fn (WeaponClass $class, float $base): float => $resolver->resolve(CombatStats::damageId($class), $base, $modifiers, $context)->value;
        $aim = fn (WeaponClass $class, int $accuracy): float => $character->stat(CombatStats::aimId($class))->value + $accuracy;

        $ranged = $character->equippedRanged;
        $melee = $character->equippedMelee;

        return new Combatant(
            name: $character->handle,
            hp: Points::of($character->current_hitpoints),
            defence: $character->stat('defence')->value,
            pursuit: $character->stat('pursuit')->value,
            ranged: $ranged === null ? null : self::characterRanged($character, $ranged, $damage, $aim),
            melee: $melee === null ? self::bareHands($aim(WeaponClass::Unarmed, 0), $damage(WeaponClass::Unarmed, (float) config('combat.unarmed.damage'))) : self::characterMelee($melee, $damage, $aim),
            si: Points::of($character->current_synaptic_integrity),
            hpMax: Points::of($character->stat('hitpoints_max')->value),
            critChance: $character->stat('critical_chance_physical')->value,
            bleedPerLevel: $character->stat('bleed_per_level')->value,
            fists: self::bareHands($aim(WeaponClass::Unarmed, 0), $damage(WeaponClass::Unarmed, (float) config('combat.unarmed.damage'))),
            // The pool, plus what skills and the like have added to unarmed aim on top of ITS pool.
            takedown: $character->stat('takedown')->value + ($character->stat(CombatStats::aimId(WeaponClass::Unarmed))->value - $character->stat(CombatStats::aimId(WeaponClass::Unarmed))->base),
        );
    }

    /**
     * An opponent from a plain description (what `fight:simulate` takes, and what an NPC row will
     * become). Keys: name, hp, defence, pursuit, flees_at, flee_beyond, aim, and `ranged` / `melee` each
     * `{class, damage, rate?, profile?, riders?, penetration?}`.
     *
     * @param  array<string, mixed>  $spec
     */
    public static function fromSpec(array $spec): Combatant
    {
        $aim = (float) ($spec['aim'] ?? 10);
        $arm = static fn (?array $w): ?Arm => $w === null ? null : self::npcArm($w, $aim);

        return new Combatant(
            name: (string) ($spec['name'] ?? 'Opponent'),
            hp: Points::of($spec['hp'] ?? 40),
            defence: (float) ($spec['defence'] ?? 8),
            pursuit: (float) ($spec['pursuit'] ?? 8),
            ranged: $arm($spec['ranged'] ?? null),
            melee: $arm($spec['melee'] ?? null) ?? self::bareHands($aim, (float) config('combat.unarmed.damage')),
            si: null,
            hpMax: Points::of($spec['hp'] ?? 40),
            fleesAt: isset($spec['flees_at']) ? (float) $spec['flees_at'] : null,
            fleeBeyond: isset($spec['flee_beyond']) ? (int) $spec['flee_beyond'] : null,
            fists: self::bareHands($aim, (float) config('combat.unarmed.damage')),
            // An opponent's skill at it is its aim, unless a spec says otherwise.
            takedown: (float) ($spec['takedown'] ?? $aim),
        );
    }

    /**
     * An NPC from its row: its numbers, and its weapons from the items it points at. The standing
     * round (if any) changes the ranged weapon the way a loaded one changes a character's, and is
     * never counted. Melee falls back to bare hands, as for anybody.
     */
    public static function forNpc(Npc $npc): Combatant
    {
        $weapon = static function (?Item $item, ?Item $round = null): ?array {
            $row = $item?->weapon;

            if ($row === null) {
                return null;
            }

            $ammo = $round?->ammunition;

            // A firearm's damage is its round's. One with no standing round has nothing to fire.
            if ($row->class->firearm() && $ammo === null) {
                return null;
            }

            return [
                'name' => $item->displayName(),
                'class' => $row->class,
                'rate' => $row->rate,
                'accuracy' => $row->accuracy,
                'damage' => $row->class->firearm() ? $ammo->damage : $row->damage,
                'profile' => $row->class->firearm() ? ($ammo->profile() ?? self::defaultProfile($row->class)) : $row->profile(),
                'penetration' => $ammo?->penetration ?? 0,
                'riders' => Riders::combine($row->riders(), $ammo?->riders()),
            ];
        };

        return self::fromSpec([
            'name' => $npc->name, 'hp' => $npc->hitpoints, 'aim' => $npc->aim, 'defence' => $npc->defence,
            'pursuit' => $npc->pursuit, 'flees_at' => $npc->flees_at, 'flee_beyond' => $npc->flee_beyond,
            'ranged' => $weapon($npc->rangedWeapon, $npc->ammo),
            'melee' => $weapon($npc->meleeWeapon),
        ]);
    }

    /** What a weapon of this class does by default, until an editor says otherwise. */
    public static function defaultProfile(WeaponClass $class): array
    {
        return match ($class) {
            WeaponClass::Pistol, WeaponClass::Smg, WeaponClass::Rifle => ['penetrating' => 100],
            WeaponClass::Shotgun => ['penetrating' => 30, 'fragmentation' => 70],
            WeaponClass::Blade => ['slashing' => 100],
            WeaponClass::Blunt, WeaponClass::Unarmed => ['blunt' => 100],
            WeaponClass::Explosive => ['fragmentation' => 100],
        };
    }

    /** @param  callable(WeaponClass, float): float  $damage  @param  callable(WeaponClass, int): float  $aim */
    private static function characterRanged(Character $character, InventoryItem $row, callable $damage, callable $aim): Arm
    {
        /** @var Weapon $weapon */
        $weapon = $row->item->weapon;
        $class = $weapon->class;
        $name = $row->item->displayName();

        // A thrown weapon takes no magazine: one spec, never runs dry.
        if (! $class->firearm()) {
            return new Arm($name, $class, $aim($class, $weapon->accuracy), $weapon->rate, [
                'own' => new RoundSpec('own', $name, $damage($class, (float) $weapon->damage), $weapon->profile(), 0, $weapon->riders()),
            ], 'own', null, 0);
        }

        $piles = InventoryItem::query()
            ->with('item.ammunition')
            ->where('character_id', $character->id)
            ->loose()
            ->whereHas('item.ammunition', fn ($q) => $q->where('calibre', $weapon->calibre))
            ->get()
            ->groupBy('item_id');

        // Everything it could fire: what is in the stash, and the round already in the magazine.
        $items = $piles->map(fn ($rows) => $rows->first()->item);
        if ($row->loaded_ammo_id !== null && ! $items->has($row->loaded_ammo_id) && $row->loadedAmmo?->ammunition !== null) {
            $items->put($row->loaded_ammo_id, $row->loadedAmmo);
        }

        $specs = [];

        foreach ($items as $itemId => $item) {
            /** @var Ammunition $round */
            $round = $item->ammunition;

            $specs[(string) $itemId] = new RoundSpec(
                (string) $itemId,
                $item->displayName(),
                $damage($class, (float) $round->damage),
                $round->profile() ?? self::defaultProfile($class),
                $round->penetration,
                Riders::combine($weapon->riders(), $round->riders()),
                (int) ($piles->get($itemId)?->sum('quantity') ?? 0),
            );
        }

        $rounds = $row->rounds();

        return new Arm(
            $name, $class, $aim($class, $weapon->accuracy), $weapon->rate, $specs,
            $rounds > 0 ? (string) $row->loaded_ammo_id : null, $rounds, (int) $weapon->magazine,
        );
    }

    /** @param  callable(WeaponClass, float): float  $damage  @param  callable(WeaponClass, int): float  $aim */
    private static function characterMelee(InventoryItem $row, callable $damage, callable $aim): Arm
    {
        /** @var Weapon $weapon */
        $weapon = $row->item->weapon;
        $name = $row->item->displayName();

        return new Arm($name, $weapon->class, $aim($weapon->class, $weapon->accuracy), $weapon->rate, [
            'own' => new RoundSpec('own', $name, $damage($weapon->class, (float) $weapon->damage), $weapon->profile(), 0, $weapon->riders()),
        ], 'own');
    }

    public static function bareHands(float $aim, float $damage): Arm
    {
        return new Arm(Text::get('combat.weapons.bare_hands'), WeaponClass::Unarmed, $aim, 1, [
            'own' => new RoundSpec('own', 'Bare hands', $damage, (array) config('combat.unarmed.profile'), 0, []),
        ], 'own');
    }

    /** @param  array<string, mixed>  $w */
    private static function npcArm(array $w, float $aim): Arm
    {
        $class = $w['class'] instanceof WeaponClass ? $w['class'] : WeaponClass::from((string) $w['class']);
        $name = (string) ($w['name'] ?? ucfirst($class->label()));

        return new Arm($name, $class, $aim + (float) ($w['accuracy'] ?? 0), (int) ($w['rate'] ?? 1), [
            'own' => new RoundSpec('own', $name, (float) $w['damage'], $w['profile'] ?? self::defaultProfile($class), (int) ($w['penetration'] ?? 0), $w['riders'] ?? []),
        ], 'own', null, 0);
    }
}
