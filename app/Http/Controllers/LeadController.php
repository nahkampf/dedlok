<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\City\ActivityKind;
use App\City\CityClock;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\SafehouseLead;
use App\Safehouses\Leads;
use App\Safehouses\Raid;
use App\Safehouses\Refusal;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** A place that feels off: the screen, and what can be done at it. `App\Safehouses\Leads` decides everything. */
class LeadController extends Controller
{
    public function show(Request $request, SafehouseLead $lead, Leads $leads, CityClock $clock): View|RedirectResponse
    {
        $character = $this->character($request);

        if ($character->isJackedIn()) {
            return redirect()->route('cyberspace.index')->with('error', Text::get('city.jack_out_first'));
        }

        $said = array_map(static fn (array $m): string => Text::get("notifications.messages.{$m[0]}", $m[1]), $clock->settle($character));

        if ($said !== []) {
            session()->now('status', implode(' ', $said));
            $character->refresh();
        }

        $lead = $this->own($character, $lead, $leads);
        abort_if($lead->safehouse->district_id !== $character->district_id, 404);

        $activity = CityActivity::query()->whereKey($character->getKey())->where('lead_id', $lead->getKey())->first();

        return view('safehouse.lead', [
            'lead' => $lead,
            'known' => $lead->isKnown(),
            'cleared' => $lead->isCleared(),
            'activity' => $activity,
            'busy' => CityActivity::query()->whereKey($character->getKey())->exists(),
            'remaining' => $activity === null ? 0 : max(0, (int) now()->diffInSeconds($activity->pass_ends_at, false)),
        ]);
    }

    public function ask(Request $request, SafehouseLead $lead, Leads $leads): RedirectResponse
    {
        $character = $this->character($request);

        return $this->answer($leads->askAround($character, $this->own($character, $lead, $leads)));
    }

    public function watch(Request $request, SafehouseLead $lead, string $kind, Leads $leads): RedirectResponse
    {
        $activity = match ($kind) {
            'stakeout' => ActivityKind::Stakeout,
            'scope-out' => ActivityKind::ScopeOut,
            default => abort(404),
        };
        $character = $this->character($request);
        $result = $leads->begin($character, $this->own($character, $lead, $leads), $activity);

        return $result instanceof Refusal ? back()->with('error', $result->message()) : back();
    }

    public function stop(Request $request, Leads $leads, CityClock $clock): RedirectResponse
    {
        $character = $this->character($request);
        $clock->settle($character);
        $result = $leads->stop($character);

        return $result instanceof Refusal ? back()->with('error', $result->message()) : back();
    }

    public function breakIn(Request $request, SafehouseLead $lead, Leads $leads): RedirectResponse
    {
        $character = $this->character($request);

        return $this->answer($leads->breakIn($character, $this->own($character, $lead, $leads)));
    }

    public function rob(Request $request, SafehouseLead $lead, Leads $leads): RedirectResponse
    {
        $character = $this->character($request);

        return $this->answer($leads->rob($character, $this->own($character, $lead, $leads)));
    }

    public function burn(Request $request, SafehouseLead $lead, Leads $leads): RedirectResponse
    {
        $character = $this->character($request);
        $result = $leads->burn($character, $this->own($character, $lead, $leads));

        // The place is gone, so there is nothing to go back to.
        return $result instanceof Raid ? redirect()->route('city.index')->with('status', $result->message) : back()->with('error', $result->message());
    }

    public function leave(Request $request, SafehouseLead $lead, Leads $leads): RedirectResponse
    {
        $character = $this->character($request);
        $leads->leave($character, $this->own($character, $lead, $leads));

        return back();
    }

    public function forget(Request $request, SafehouseLead $lead, Leads $leads): RedirectResponse
    {
        $character = $this->character($request);
        $leads->forget($character, $this->own($character, $lead, $leads));

        return back()->with('status', Text::get('safehouse.lead.forgotten'));
    }

    private function answer(Raid|Refusal $result): RedirectResponse
    {
        return $result instanceof Refusal
            ? back()->with('error', $result->message())
            : back()->with($result->won ? 'status' : 'error', $result->message);
    }

    /** The character's own lead, or a 404: somebody else's id is simply not there. */
    private function own(Character $character, SafehouseLead $lead, Leads $leads): SafehouseLead
    {
        $leads->prune($character);

        return SafehouseLead::query()->where('character_id', $character->getKey())->whereKey($lead->getKey())->with('safehouse.district', 'target')->firstOrFail();
    }

    private function character(Request $request): Character
    {
        return $request->user()->character()->firstOrFail();
    }
}
