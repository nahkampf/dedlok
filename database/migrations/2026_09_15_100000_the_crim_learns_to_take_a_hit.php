<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Crim's last display-only perk becomes a real one.
 *
 * `damage_taken` has been a number on a card since before the resolver
 * existed: −15%, rendered, changing nothing. It could not be made live because
 * nothing in the game could express "damage taken" as a target — damage was a
 * subtraction a caller did, not a stat anything could modify. Now that a hit
 * resolves through App\Stats\DamageResolver with its resource and its origin
 * as tags, the perk is one modifier:
 *
 *     percentage_of_base -5 against all_of: ['damage', 'hitpoints']
 *
 * **PHYSICAL ONLY, and that is the substance of the change rather than the
 * number.** The Crim is hard to hurt in a fight, and being hard to hurt in a
 * fight has nothing to do with what ICE does to a nervous system — a perk that
 * softened both would make the street fighter quietly better at the thing the
 * Deck Jockey is for. The tag set is what makes that sayable: `hitpoints`
 * without `synaptic_integrity`, and every origin, because a broken rib is a
 * broken rib.
 *
 * −5 rather than the −15 the card used to claim. The old number was never
 * measured against anything, since it never did anything; this one is against
 * the +5% max HP of the Crim's other perk, and against the fact that it now
 * applies to every physical hit for the life of the character.
 *
 * The label changes with it. "Damage taken" named the STAT, which is the
 * legacy shape — a live perk is a thing a character HAS, so it gets a name and
 * the card generates the effect from the modifier. The key stays `damage_taken`:
 * it is the stable identifier, nothing about it is player-visible, and renaming
 * it would be churn for its own sake.
 *
 * **THE DISPLAY-ONLY SHAPE ITSELF IS KEPT.** `display_modifier` and
 * `display_unit` stay on the table, `ArchetypePerk::isLive()` still answers,
 * the card still renders one, and a test now builds a synthetic perk to hold
 * that path down — content may want it again, and a mechanism with no user is
 * cheaper to keep than to rebuild. What ends here is shipped content relying
 * on it, not the ability to.
 */
return new class extends Migration
{
    private const PERK = 'damage_taken';

    public function up(): void
    {
        DB::table('archetype_perks')->where('key', self::PERK)->update([
            'label' => 'Hard knocks',
            'modifiers' => json_encode([[
                'op' => 'percentage_of_base',
                'amount' => -5,
                'target' => ['all_of' => ['damage', 'hitpoints']],
            ]]),
            'display_modifier' => null,
            'display_unit' => null,
        ]);
    }

    public function down(): void
    {
        DB::table('archetype_perks')->where('key', self::PERK)->update([
            'label' => 'Damage taken',
            'modifiers' => null,
            'display_modifier' => -15,
            'display_unit' => 'percent',
        ]);
    }
};
