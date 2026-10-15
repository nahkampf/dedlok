<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cyberspace\ContentKind;
use App\Cyberspace\FortressAccess;
use App\Cyberspace\FortressAreas;
use App\Cyberspace\FortressCloak;
use App\Cyberspace\FortressDefence;
use App\Cyberspace\FortressHost;
use App\Cyberspace\FortressMarket;
use App\Cyberspace\FortressProduction;
use App\Cyberspace\FortressVault;
use App\Cyberspace\Leeches;
use App\Models\Entity;
use App\Models\FortressArea;
use App\Models\FortressJob;
use App\Models\FortressListing;
use App\Models\InventoryItem;
use App\Models\System;
use App\Models\SystemContent;
use App\Programs\ProgramKind;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** The owner's view of a Data Fortress: what it runs on, what is installed, what runs. Every rule is in FortressHost. */
class FortressController extends Controller
{
    public function show(Request $request, System $system, FortressHost $host, Leeches $leeches): View
    {
        $character = $request->user()->character()->firstOrFail();
        abort_unless(FortressAccess::admitted($character, $system), 404);

        $host->settle($system);
        $system->refresh();

        return view('fortress.show', [
            'fortress' => $system,
            'totals' => $leeches->totals($system),
            'used' => $host->used($system),
            'online' => $host->online($system),
            'role' => FortressAccess::roleOf($character, $system),
            'character' => $character,
            'entities' => Entity::query()->orderBy('name')->get(['id', 'name']),
            'listings' => FortressListing::query()->where('system_id', $system->id)->get()->keyBy('content_id'),
            'marketOpen' => FortressMarket::isOpen($system),
            'members' => DB::table('fortress_members')->join('characters', 'characters.id', '=', 'fortress_members.character_id')
                ->where('system_id', $system->id)->get(['characters.id', 'characters.handle', 'fortress_members.role']),
            'hosted' => $host->hosted($system),
            'areas' => $areas = FortressArea::query()->where('system_id', $system->id)->orderBy('id')->get(),
            'roleNames' => array_keys(FortressAccess::definitions($system)),
            'canAudit' => FortressAccess::can($character, $system, 'audit'),
            'log' => FortressAccess::can($character, $system, 'audit')
                ? DB::table('fortress_log')->leftJoin('characters', 'characters.id', '=', 'fortress_log.character_id')
                    ->where('system_id', $system->id)->orderByDesc('fortress_log.id')->limit(50)->get(['fortress_log.*', 'characters.handle'])
                : collect(),
            // Only what this person may see: the owner's storage (area null) to the owner, and each area they may see.
            'jobs' => FortressJob::query()->where('system_id', $system->id)->orderBy('id')->get(),
            'designs' => SystemContent::query()->where('system_id', $system->id)->where('kind', ContentKind::Item->value)
                ->whereHas('item.design')->with('item')->get(),
            'vault' => SystemContent::query()->where('system_id', $system->id)->whereIn('kind', [ContentKind::Data->value, ContentKind::Item->value])->with('item')->get()
                ->filter(fn ($c): bool => FortressAccess::areaCan($character, $system, $c->area_id === null ? null : $areas->firstWhere('id', $c->area_id), 'see'))->values(),
            'files' => $character->inventory()->loose()->whereHas('item', fn ($q) => $q->where('container', 'data'))->with('item')->get(),
            // What could go on it: hosted kinds in the stash (a Leecher is not installed here).
            'stash' => $character->inventory()->loose()
                ->whereHas('item.program', fn ($q) => $q->whereIn('kind', array_map(fn (ProgramKind $k): string => $k->value, array_filter(ProgramKind::cases(), fn (ProgramKind $k): bool => $k->isHosted()))))
                ->with('item.program')->get(),
        ]);
    }

    public function install(Request $request, System $system, InventoryItem $row, FortressHost $host): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();

        return $this->back($system, $host->install($character, $row, $system));
    }

    public function uninstall(Request $request, System $system, InventoryItem $row, FortressHost $host): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();

        return $this->back($system, $host->uninstall($character, $row));
    }

    public function start(Request $request, System $system, InventoryItem $row, FortressHost $host): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();

        return $this->back($system, $host->start($character, $row));
    }

    public function stop(Request $request, System $system, InventoryItem $row, FortressHost $host): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();

        return $this->back($system, $host->stop($character, $row));
    }

    public function deposit(Request $request, System $system, InventoryItem $row, FortressVault $vault, FortressHost $host, Leeches $leeches): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $area = $request->validate(['area' => ['nullable', 'integer']])['area'] ?? null;

        return $this->back($system, $vault->deposit($character, $row, $system, $host, $leeches, $area === null ? null : (int) $area));
    }

    public function withdraw(Request $request, System $system, SystemContent $content, FortressVault $vault): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();

        return $this->back($system, $vault->withdraw($character, $content, $system));
    }

    public function maintain(Request $request, System $system, InventoryItem $row): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();

        return $this->back($system, FortressDefence::maintain($character, $row, $system));
    }

    public function settings(Request $request, System $system, FortressAccess $access): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $data = $request->validate(['access' => ['required', 'string'], 'name' => ['nullable', 'string', 'max:48']]);

        return $this->back($system, $access->setAccess($character, $system, $data['access'], $data['name'] ?? null));
    }

    public function inviteMember(Request $request, System $system, FortressAccess $access): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $data = $request->validate(['handle' => ['required', 'string', 'max:40'], 'role' => ['required', 'string']]);

        return $this->back($system, $access->invite($character, $system, trim($data['handle']), $data['role']));
    }

    public function revokeMember(Request $request, System $system, int $member, FortressAccess $access): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();

        return $this->back($system, $access->revoke($character, $system, $member));
    }

    public function createArea(Request $request, System $system, FortressAreas $areas): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $data = $request->validate(['name' => ['required', 'string', 'max:40']]);
        $result = $areas->create($character, $system, $data['name']);

        return $this->back($system, is_string($result) ? $result : null);
    }

    public function allowArea(Request $request, System $system, FortressArea $area, FortressAreas $areas): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $data = $request->validate(['audience' => ['required', 'string', 'max:40'], 'perms' => ['nullable', 'array'], 'perms.*' => ['string']]);

        return $this->back($system, $areas->allow($character, $system, $area, $data['audience'], $data['perms'] ?? []));
    }

    public function deleteArea(Request $request, System $system, FortressArea $area, FortressAreas $areas): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();

        return $this->back($system, $areas->delete($character, $system, $area));
    }

    public function role(Request $request, System $system, FortressAccess $access): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $data = $request->validate(['name' => ['required', 'string', 'max:30'], 'properties' => ['nullable', 'array'], 'properties.*' => ['string']]);

        return $this->back($system, $access->defineRole($character, $system, $data['name'], $data['properties'] ?? []));
    }

    public function memberRoles(Request $request, System $system, int $member, FortressAccess $access): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $data = $request->validate(['roles' => ['required', 'array'], 'roles.*' => ['string']]);

        return $this->back($system, $access->setRoles($character, $system, $member, $data['roles']));
    }

    public function market(Request $request, System $system): View
    {
        $character = $request->user()->character()->firstOrFail();
        abort_unless($system->type->isFortress() && FortressMarket::mayVisit($character, $system), 404);

        $at = $character->gridPosition();
        $here = $at !== null && $at->x === $system->x && $at->y === $system->y;

        return view('fortress.market', [
            'fortress' => $system,
            'open' => FortressMarket::isOpen($system),
            'here' => $here,
            'character' => $character,
            'listings' => FortressListing::query()->where('fortress_listings.system_id', $system->id)
                ->join('system_contents', 'system_contents.id', '=', 'fortress_listings.content_id')
                ->get(['fortress_listings.*', 'system_contents.label', 'system_contents.size_qb']),
        ]);
    }

    public function buy(Request $request, System $system, FortressListing $listing, FortressMarket $market): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $refusal = $market->buy($character, $system, $listing);

        return $refusal === null
            ? redirect()->route('fortress.market', $system)->with('status', Text::get('cyberspace.fortress.host.bought'))
            : redirect()->route('fortress.market', $system)->with('error', Text::get("cyberspace.fortress.host.refused.{$refusal}"));
    }

    public function configureMarket(Request $request, System $system, FortressMarket $market): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $data = $request->validate(['area' => ['nullable', 'integer'], 'tax' => ['required', 'integer', 'min:0', 'max:50']]);

        return $this->back($system, $market->configure($character, $system, isset($data['area']) ? (int) $data['area'] : null, (int) $data['tax']));
    }

    public function listFile(Request $request, System $system, SystemContent $content, FortressMarket $market): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $data = $request->validate(['price' => ['required', 'integer', 'min:1', 'max:100000000']]);

        return $this->back($system, $market->list($character, $system, $content, (int) $data['price']));
    }

    public function unlistFile(Request $request, System $system, FortressListing $listing, FortressMarket $market): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();

        return $this->back($system, $market->unlist($character, $listing));
    }

    public function withdrawWallet(Request $request, System $system, FortressMarket $market): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1']]);

        return $this->back($system, $market->withdraw($character, $system, (int) $data['amount']));
    }

    public function cloak(Request $request, System $system, FortressCloak $cloak): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $data = $request->validate(['grade' => ['required', 'integer', 'min:1', 'max:9'], 'type' => ['required', 'string'], 'entity' => ['nullable', 'integer']]);

        return $this->back($system, $cloak->set($character, $system, (int) $data['grade'], $data['type'], isset($data['entity']) ? (int) $data['entity'] : null));
    }

    public function queueJob(Request $request, System $system, FortressProduction $production): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $data = $request->validate(['kind' => ['required', 'in:components,program'], 'design' => ['nullable', 'integer'], 'count' => ['nullable', 'integer', 'min:1', 'max:20']]);

        return $this->back($system, $production->enqueue($character, $system, $data['kind'], isset($data['design']) ? (int) $data['design'] : null, (int) ($data['count'] ?? 1)));
    }

    public function cancelJob(Request $request, System $system, FortressJob $job, FortressProduction $production): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();

        return $this->back($system, $production->cancel($character, $system, $job));
    }

    public function outputArea(Request $request, System $system, FortressProduction $production): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $data = $request->validate(['area' => ['nullable', 'integer']]);

        return $this->back($system, $production->setOutput($character, $system, isset($data['area']) ? (int) $data['area'] : null));
    }

    private function back(System $system, ?string $refusal): RedirectResponse
    {
        $response = redirect()->route('fortress.show', $system);

        return $refusal === null ? $response : $response->with('error', Text::get("cyberspace.fortress.host.refused.{$refusal}"));
    }
}
