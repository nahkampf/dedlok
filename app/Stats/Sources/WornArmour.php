<?php

declare(strict_types=1);

namespace App\Stats\Sources;

use App\Models\Character;
use App\Models\WornSlot;
use App\Stats\Condition;
use App\Stats\Modifier;
use App\Stats\StatRegistry;

/**
 * What a character is wearing: the seventh modifier source.
 *
 * An armour piece's effects live on `items.modifiers`, like an implant's, and this reads
 * them for every piece currently worn. THE ONE THING IT ADDS is where a resistance counts.
 *
 * A DAMAGE modifier — one that reaches any `damage.*` stat — is wrapped in the
 * `hit_location_in` condition with the slots the piece COVERS, so a torso plate softens a
 * shot to the torso and does nothing for one to the leg, and a bare slot has only what the
 * character carries everywhere (implants, skills, drugs). Any other modifier on the piece
 * (heavy plating slowing the wearer, say) is unconditional and applies whenever it is worn.
 *
 * WITH NO LOCATION, NOTHING HERE APPLIES to damage: ICE, an overdose, an encounter's damage
 * outcome and a burn strike no particular body part, and the predicate answers false for a
 * hit that names none. That is deliberate and worth knowing — a vest does not help against
 * a bad deck.
 *
 * A modifier that already carries a condition of its own is left exactly as authored, and
 * the item editor refuses to save one on armour, because it would apply on every hit.
 *
 * No time-awareness, like the implants: nothing records when a piece was taken off.
 */
final readonly class WornArmour implements ModifierSource
{
    public function __construct(private Character $character) {}

    /** @return list<Modifier> */
    public function modifiers(): array
    {
        $worn = WornSlot::query()
            ->where('character_id', $this->character->getKey())
            ->with('inventoryItem.item')
            ->get()
            ->groupBy('inventory_item_id');

        if ($worn->isEmpty()) {
            return [];
        }

        $registry = app(StatRegistry::class);
        $modifiers = [];

        foreach ($worn as $rows) {
            $item = $rows->first()->inventoryItem?->item;

            if ($item === null || ! is_array($item->modifiers)) {
                continue;
            }

            $slots = $rows->map(static fn (WornSlot $row): string => $row->slot->value)->values()->all();

            foreach ($item->modifiers as $shape) {
                if (! is_array($shape)) {
                    continue;
                }

                $modifier = Modifier::fromArray($shape + ['source' => $item->displayName()]);

                if ($modifier->condition === null && $this->reachesDamage($modifier, $registry)) {
                    $modifier = new Modifier(
                        $modifier->op,
                        $modifier->amount,
                        $modifier->target,
                        $modifier->source,
                        $modifier->priority,
                        Condition::fromArray(['when' => 'hit_location_in', 'args' => ['slots' => $slots]]),
                    );
                }

                $modifiers[] = $modifier;
            }
        }

        return $modifiers;
    }

    /** Does this modifier touch any damage stat? Asked of the registry, so a tag set like `kinetic` counts. */
    private function reachesDamage(Modifier $modifier, StatRegistry $registry): bool
    {
        foreach ($registry->matching($modifier->target) as $id) {
            if (str_starts_with($id, 'damage.')) {
                return true;
            }
        }

        return false;
    }
}
