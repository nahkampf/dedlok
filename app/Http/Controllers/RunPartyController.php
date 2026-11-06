<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cyberspace\Party\Parties;
use App\Cyberspace\Party\PartyRefusal;
use App\Cyberspace\Party\ShareMode;
use App\Cyberspace\RunStarter;
use App\Models\Character;
use App\Models\RunParty;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The party forming at a system's door. Every rule is in Parties; this only carries the answer back. */
class RunPartyController extends Controller
{
    public function open(Request $request, Parties $parties): RedirectResponse
    {
        [$credits, $extraction] = $this->modes($request);

        return $this->back($parties->open($this->character($request), $credits, $extraction));
    }

    public function options(Request $request, Parties $parties): RedirectResponse
    {
        [$credits, $extraction] = $this->modes($request);

        return $this->back($parties->options($this->character($request), $credits, $extraction));
    }

    public function invite(Request $request, Parties $parties): RedirectResponse
    {
        $data = $request->validate(['handle' => ['required', 'string', 'max:40']]);

        return $this->back($parties->invite($this->character($request), trim($data['handle'])));
    }

    public function accept(Request $request, Parties $parties): RedirectResponse
    {
        // The setup screen's own loadout form: one icebreaker by radio, the rest by checkbox (`loadout` is the older name).
        $data = $request->validate([
            'loadout' => ['nullable', 'array', 'max:20'], 'loadout.*' => ['integer', 'distinct'],
            'icebreaker' => ['nullable', 'integer'],
            'programs' => ['nullable', 'array', 'max:20'], 'programs.*' => ['integer', 'distinct'],
            'scripts' => ['nullable', 'array', 'max:20'], 'scripts.*' => ['integer', 'distinct'],
        ]);

        $ids = array_values(array_unique(array_map('intval', array_filter([
            ...($data['loadout'] ?? []), $data['icebreaker'] ?? null, ...($data['programs'] ?? []), ...($data['scripts'] ?? []),
        ]))));

        return $this->back($parties->accept($this->character($request), $ids));
    }

    /** Accept by id, from a `run:<id>` link in chat; only for somebody invited. JSON for the page's script, a redirect otherwise. */
    public function join(Request $request, RunParty $party, Parties $parties): JsonResponse|RedirectResponse
    {
        $refusal = $parties->acceptInto($this->character($request), $party);

        if ($request->expectsJson()) {
            // Invited but not at the system: hand back its address to copy, to paste into Jump.
            $away = $refusal === PartyRefusal::NotAtTheDoor;

            return response()->json([
                'ok' => $refusal === null,
                'message' => $away
                    ? Text::get('cyberspace.party.address_copied', ['address' => $party->address])
                    : ($refusal?->message() ?? Text::get('cyberspace.party.joined_now')),
                'url' => route('run.setup'),
                'copy' => $away ? $party->address : null,
            ], $refusal === null || $away ? 200 : 422);
        }

        return $this->back($refusal);
    }

    /**
     * The party panel as it stands now, for the setup page's poll: the member lines (or the member's own), and whether
     * the owner could start. `gone` and `started` tell the page to reload, since the whole screen is then different.
     */
    public function status(Request $request, Parties $parties, RunStarter $starter): JsonResponse
    {
        $character = $this->character($request);
        $party = $parties->partyOf($character)?->load('members.character', 'owner');

        if ($party === null) {
            return response()->json(['state' => 'gone']);
        }

        if ($party->started_at !== null) {
            return response()->json(['state' => 'started']);
        }

        $obstacles = $parties->obstacles($party, $starter);
        $isOwner = $party->owner_character_id === $character->id;
        $mine = $isOwner ? null : $party->members->firstWhere('character_id', $character->id);

        return response()->json([
            'state' => 'open',
            'role' => $isOwner ? 'owner' : 'member',
            'ready' => $parties->someoneIsReady($party, $starter),
            'html' => view($isOwner ? 'run._party_members' : 'run._party_me', compact('party', 'character', 'obstacles', 'mine'))->render(),
        ]);
    }

    public function leave(Request $request, Parties $parties): RedirectResponse
    {
        $parties->leave($this->character($request));

        return redirect()->route('run.setup');
    }

    /** @return array{0: ShareMode, 1: ShareMode} */
    private function modes(Request $request): array
    {
        $values = array_map(static fn (ShareMode $m): string => $m->value, ShareMode::cases());
        $data = $request->validate([
            'share_credits' => ['required', Rule::in($values)],
            'share_extraction' => ['required', Rule::in($values)],
        ]);

        return [ShareMode::from($data['share_credits']), ShareMode::from($data['share_extraction'])];
    }

    private function back(mixed $result): RedirectResponse
    {
        $response = redirect()->route('run.setup');

        return $result instanceof PartyRefusal ? $response->with('error', $result->message()) : $response;
    }

    private function character(Request $request): Character
    {
        return $request->user()->character()->firstOrFail();
    }
}
