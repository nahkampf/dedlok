<?php

declare(strict_types=1);

namespace App\Combat;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Skill;

/**
 * Picks a weapon up and puts it down. The only writer of `characters.equipped_ranged_id` and
 * `equipped_melee_id`.
 *
 * A fighter holds one weapon of each kind — a firearm is usable only at Ranged and a blade only
 * at Close — so the weapon's CLASS says which hand it fills (`WeaponClass::ranged()`), and
 * picking one up SWAPS whatever was in that hand. One column holds one value, so there is no
 * state to clear first and nothing that can half-happen. Empty is bare hands.
 *
 * Nothing is destroyed, so nothing is confirmed. Like the equipped deck and worn armour, a
 * wielded weapon stays in the stash grid, tagged.
 */
final class WeaponWielder
{
    public function wield(Character $character, InventoryItem $row): true|WieldRefusal
    {
        if ($row->character_id !== $character->id) {
            return WieldRefusal::NotOwned;
        }

        $class = $row->item?->weapon?->class;

        if ($class === null) {
            return WieldRefusal::NotAWeapon;
        }

        if ($row->isWielded()) {
            return WieldRefusal::AlreadyWielded;
        }

        // Free to hold: not in a container, fitted, running or installed — the one list of ways a
        // row can be spoken for, asked the same way the market, a shop and armour ask it.
        if (! InventoryItem::query()->loose()->whereKey($row->getKey())->exists()) {
            return WieldRefusal::NotFree;
        }

        $gate = $this->gate($class);

        if ($gate !== null && $character->stat("skill.{$gate->slug}")->value < (int) config('combat.gate_level')) {
            return WieldRefusal::NeedsSkill;
        }

        $character->forceFill([$class->ranged() ? 'equipped_ranged_id' : 'equipped_melee_id' => $row->getKey()])->save();

        return true;
    }

    /** The skill that gates a class, if one does and it exists; the answer to "train what?". */
    public function gate(WeaponClass $class): ?Skill
    {
        $slug = config("combat.skill_gates.{$class->value}");

        return is_string($slug) ? Skill::query()->where('slug', $slug)->first() : null;
    }

    public function unwield(Character $character, InventoryItem $row): true|WieldRefusal
    {
        if ($row->character_id !== $character->id) {
            return WieldRefusal::NotOwned;
        }

        $held = [];

        foreach (['equipped_ranged_id', 'equipped_melee_id'] as $column) {
            if ((int) $character->{$column} === (int) $row->getKey()) {
                $held[$column] = null;
            }
        }

        if ($held === []) {
            return WieldRefusal::NotWielded;
        }

        $character->forceFill($held)->save();

        return true;
    }
}
