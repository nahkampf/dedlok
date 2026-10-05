<?php

declare(strict_types=1);

namespace App\Combat;

use App\Support\Text;

/**
 * What KIND of round an ammunition item is. COMBAT.md.
 *
 * A fixed vocabulary, like `WeaponClass`: it names the round on the card and in the editor, and
 * is what the engine and NPCs will ask about by name. What a round DOES is still its own numbers
 * (`damage`, `penetration`, `damage_profile`) — the type never carries mechanics, so an
 * "armour piercing" round that nobody tuned is just a label. A new type is a case and a string.
 */
enum AmmoType: string
{
    case Standard = 'standard';
    case FullMetalJacket = 'full_metal_jacket';
    case HollowPoint = 'hollow_point';
    case ArmourPiercing = 'armour_piercing';
    case Incendiary = 'incendiary';
    case Shock = 'shock';

    public function label(): string
    {
        return Text::get("combat.ammo_types.{$this->value}");
    }
}
