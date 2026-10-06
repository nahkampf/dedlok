<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Combat\WearSlot;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Support\Text;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What is on a body: armour in four places, a belt, and a weapon in each hand.
 *
 * READ ONLY, like the implants screen. Things go on and come off from the stash card, and each filled slot here
 * carries the same button for taking it off again (the routes that already write: `inventory.wear`,
 * `inventory.wield`, `inventory.belt`).
 */
class EquipmentController extends Controller
{
    public function index(Request $request): View
    {
        $character = $this->character($request);

        return view('equipment.index', [
            'character' => $character,
            'regions' => $this->regions($character),
        ]);
    }

    /**
     * The seven places, in body order, each with what is in it.
     *
     * `rows` are inventory rows (one per piece however many places it covers: a jacket worn on Torso and Arms is
     * one row in both), `route` is what takes one off, `empty` is the word for a bare place.
     *
     * @return list<array{key: string, label: string, hint: ?string, capacity: int, rows: list<InventoryItem>, route: string, empty: string}>
     */
    private function regions(Character $character): array
    {
        $worn = $character->wornArmour()->with('inventoryItem.item')->get();
        $regions = [];

        foreach (WearSlot::cases() as $slot) {
            $rows = $worn
                ->filter(fn ($w): bool => in_array($w->slot, $slot->covers(), true))
                ->map(fn ($w): InventoryItem => $w->inventoryItem)
                ->unique(fn (InventoryItem $row): int => $row->getKey())
                ->values()
                ->all();

            $regions[] = [
                'key' => $slot->value,
                'label' => $slot->label(),
                'hint' => null,
                'capacity' => 1,
                'rows' => $rows,
                'route' => 'inventory.wear',
                'empty' => 'combat.armour.bare',
            ];
        }

        $regions[] = [
            'key' => 'belt',
            'label' => Text::get('equipment.regions.belt'),
            'hint' => null,
            'capacity' => (int) config('equipment.belt_slots'),
            'rows' => InventoryItem::query()
                ->where('character_id', $character->getKey())
                ->whereNotNull('belt_slot')
                ->with('item')
                ->orderBy('belt_slot')
                ->get()
                ->all(),
            'route' => 'inventory.belt',
            'empty' => 'equipment.belt.empty',
        ];

        // The right hand holds the ranged weapon and the left the close one: the class of a weapon already
        // says which hand it fills, so this is a name for what the engine already does.
        foreach ([
            ['primary', 'equippedRanged', Text::get('equipment.regions.right_hand'), 'combat.weapons.none'],
            ['secondary', 'equippedMelee', Text::get('equipment.regions.left_hand'), 'combat.weapons.bare_hands'],
        ] as [$key, $relation, $hint, $empty]) {
            $row = $character->{$relation};

            $regions[] = [
                'key' => $key,
                'label' => Text::get("equipment.regions.{$key}"),
                'hint' => $hint,
                'capacity' => 1,
                'rows' => $row === null ? [] : [$row->loadMissing('item')],
                'route' => 'inventory.wield',
                'empty' => $empty,
            ];
        }

        return $regions;
    }

    private function character(Request $request): Character
    {
        // RequireCharacter guarantees one on every route in this group.
        return $request->user()->character()->firstOrFail();
    }
}
