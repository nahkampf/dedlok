<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Missions\MissionBoard;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Mission;
use App\Models\System;
use App\Standing\Band;
use App\Standing\StandingLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Missions & Loyalty: an entity's front desk, seen from in front of it.
 *
 * REACHED BY STANDING ON IT, the way a run is planned from the cell it will be
 * run against. There is no id in the route and no way to open somebody else's
 * desk from a list: being there is the whole of the permission, which is what
 * makes walking to a grade 5 exchange worth doing.
 *
 * Nothing here can be done TO the system. An Exchange is not scanned, run
 * against or burned — `SystemType` answers all three — so this screen is the
 * only thing that happens on one.
 */
class ExchangeController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $character = $this->character($request);

        $system = $character->isJackedIn()
            ? System::query()->at($character->gridPosition())->with('owner', 'exchange')->first()
            : null;

        // Standing somewhere else is not an error worth a sentence: the map is
        // where a desk is found, so the map is where somebody is sent back to.
        if ($system?->exchange === null || ! $system->type->isExchange()) {
            return redirect()->route('cyberspace.index');
        }

        $entity = $system->owner;
        $standing = $entity !== null
            ? (new StandingLedger)->standingFor($character, $entity)
            : 0.0;

        $welcome = $system->exchange->willDealWith($standing);

        return view('exchange.show', [
            'character' => $character,
            'system' => $system,
            'desk' => $system->exchange,
            'entity' => $entity,
            'standing' => $standing,
            'band' => Band::for($standing),
            'loyalty' => $entity !== null
                ? (new StandingLedger)->loyaltyFor($character, $entity)
                : 0,
            // ONE GATE, NOT TWO. The work and the shelf read the same column:
            // an agent who will not deal with somebody will not sell to them
            // either, and a gruff desk is a row with a number in it.
            'welcome' => $system->exchange->willDealWith($standing),

            /*
             * THE BOARD IS ROLLED ON THE WAY PAST, lazily, the shape
             * `Probe::settle()` takes. It is only asked for when the desk will
             * deal with somebody — an agent who will not employ you has
             * nothing to offer, and rolling a board nobody may take would be
             * work for a screen that cannot use it.
             */
            'board' => $welcome && $entity !== null
                ? MissionBoard::fromConfig()->forCharacter($character, $system->exchange)
                : collect(),

            // Work in progress is NOT part of a board and survives every
            // refresh: a job somebody took is theirs until it ends.
            'active' => Mission::query()
                ->where('character_id', $character->id)
                ->where('exchange_id', $system->exchange->getKey())
                ->accepted()
                ->with('targets.system', 'entity')
                ->first(),

            // Counted once here rather than per card: only a supply job reads
            // it, but the card should not be running a query to draw a meter.
            'files' => $this->completeFiles($character),
        ]);
    }

    /**
     * How many complete, unexpired scan files this character is holding.
     *
     * The same test the hand-in makes, asked for a meter. A file inside a
     * container is not to hand, so `loose()` decides here exactly as it does
     * everywhere else.
     */
    private function completeFiles(Character $character): int
    {
        return InventoryItem::query()
            ->where('character_id', $character->id)
            ->whereNotNull('scan_file')
            ->loose()
            ->get()
            ->filter(function (InventoryItem $row): bool {
                $file = $row->scanFile();

                return $file !== null && ! $file->hasExpired() && $file->toScan()->isComplete();
            })
            ->count();
    }

    private function character(Request $request): Character
    {
        return $request->user()->character()->firstOrFail();
    }
}
