<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\City\CityClock;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\InventoryItem;
use App\Models\Safehouse;
use App\Safehouses\Action;
use App\Safehouses\HomeActions;
use App\Safehouses\Refusal;
use App\Safehouses\Renter;
use App\Safehouses\SafehouseStorage;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Where you live: the screen, renting, and what you do there. Every rule is in `Renter` and `HomeActions`. */
class SafehouseController extends Controller
{
    public function show(Request $request, CityClock $clock, HomeActions $actions, Renter $renter, SafehouseStorage $storage): View|RedirectResponse
    {
        $character = $this->character($request);

        if ($character->isJackedIn()) {
            return redirect()->route('cyberspace.index')->with('error', Text::get('city.jack_out_first'));
        }

        $clock->settle($character);
        $character->refresh();

        if ($character->current_encounter_id !== null) {
            return redirect()->route('encounter.show');
        }

        $home = $character->home()->load('district');
        $rows = [];

        foreach (Action::cases() as $action) {
            if ($home->offers($action)) {
                $rows[] = [
                    'action' => $action,
                    'cooldown' => $actions->cooldown($character, $action),
                    'useful' => $actions->useful($character, $action),
                ];
            }
        }

        return view('safehouse.show', [
            'character' => $character,
            'home' => $home,
            'here' => $character->district_id === $home->district_id,
            'rows' => $rows,
            'storedRows' => $storage->rows($character, $home)->whereNull('storage_expires_at')->with('item')->get(),
            'slotsUsed' => $storage->used($character, $home),
            'stash' => InventoryItem::query()->loose()->where('character_id', $character->getKey())->with('item')->get()
                ->reject(fn (InventoryItem $r): bool => $r->isOnPerson() || $character->equipped_deck_id === $r->getKey()),
            'waiting' => $storage->waiting($character),
            'places' => Safehouse::query()->with('district')->get()->keyBy('id'),
            'wired' => $actions->tooWired($character),
            'resting' => CityActivity::query()->whereKey($character->getKey())->where('kind', 'rest')->exists(),
            'paysRent' => $character->safehouse_id !== null && $character->rent_due_at !== null,
        ]);
    }

    public function rent(Request $request, Safehouse $safehouse, Renter $renter, CityClock $clock): RedirectResponse
    {
        $character = $this->character($request);
        $clock->settle($character);
        $character->refresh();

        $result = $renter->rent($character, $safehouse);

        return $result instanceof Refusal
            ? back()->with('error', $result->message())
            : redirect()->route('safehouse.show')->with('status', Text::get('safehouse.done.rented', ['place' => $renter->name($safehouse->load('district'))]));
    }

    public function act(Request $request, string $action, HomeActions $actions, CityClock $clock): RedirectResponse
    {
        $kind = Action::tryFrom($action);
        abort_if($kind === null, 404);

        $character = $this->character($request);
        $clock->settle($character);
        $character->refresh();

        $result = $kind === Action::Rest ? $actions->startRest($character) : $actions->use($character, $kind);

        return $result instanceof Refusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get("safehouse.done.{$kind->value}"));
    }

    public function store(Request $request, InventoryItem $inventoryItem, SafehouseStorage $storage): RedirectResponse
    {
        $result = $storage->deposit($this->character($request), $inventoryItem, max(1, $request->integer('quantity', 1)));

        return $result instanceof Refusal ? back()->with('error', $result->message()) : back()->with('status', Text::get('safehouse.storage.stored'));
    }

    public function take(Request $request, InventoryItem $inventoryItem, SafehouseStorage $storage): RedirectResponse
    {
        $result = $storage->withdraw($this->character($request), $inventoryItem, max(1, $request->integer('quantity', 1)));

        return $result instanceof Refusal ? back()->with('error', $result->message()) : back()->with('status', Text::get('safehouse.storage.taken'));
    }

    public function collect(Request $request, Safehouse $safehouse, SafehouseStorage $storage): RedirectResponse
    {
        $storage->collect($this->character($request), $safehouse);

        return back()->with('status', Text::get('safehouse.storage.collected'));
    }

    public function stopRest(Request $request, HomeActions $actions): RedirectResponse
    {
        $result = $actions->stopRest($this->character($request));

        return $result instanceof Refusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get('safehouse.done.rest_stop'));
    }

    private function character(Request $request): Character
    {
        return $request->user()->character()->firstOrFail();
    }
}
