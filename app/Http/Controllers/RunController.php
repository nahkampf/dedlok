<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\City\Encounters;
use App\City\EncounterStart;
use App\Cyberspace\Assessor;
use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Cyberspace\ChainBuilder;
use App\Cyberspace\Party\Parties;
use App\Cyberspace\Party\PartyRefusal;
use App\Cyberspace\Party\PartyTurns;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Cyberspace\TurnResult;
use App\Decks\DeckTotals;
use App\Decks\ThermalExposure;
use App\Missions\MissionAction;
use App\Missions\MissionLedger;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Run;
use App\Models\System;
use App\Models\SystemScan;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Standing\StandingLedger;
use App\Stats\Resolver;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The run, which is the only screen a player inside one can reach.
 *
 * `App\Http\Middleware\EnsureRunIsFinished` is what makes that true, and its
 * allow-list names exactly these routes — so a change here that adds a route
 * has to add it there too, or the new route redirects to itself.
 *
 * THIS PHASE IS THE SPINE AND NOT THE FIGHT. A run can be begun, looked at and
 * left. What happens in between — detection, the ICE, extraction, the endings —
 * is what the turns will be, and `act()` is deliberately the one place waiting
 * for them rather than several.
 */
class RunController extends Controller
{
    /** The last loadout that went into a run, as item ids. Per browser. */
    private const LOADOUT_COOKIE = 'run_loadout';

    private const LOADOUT_MINUTES = 60 * 24 * 365;

    public function __construct(private readonly RunStarter $runs) {}

    /**
     * The briefing: everything that is decided before a run, in one place.
     *
     * A RUN IS A CHARACTER IN JEOPARDY and nothing in it can be taken back —
     * the purpose is fixed at the door, a backdoor's kind is permanent once it
     * lands, and the route is snapshotted the moment it begins. So the screen
     * that commits to one has to show what is known and, more importantly,
     * what is NOT: an unread machine, a deck about to cook itself, no
     * icebreaker running against something that may be guarded.
     *
     * It reads the machine LIVE rather than from the contact, because this is
     * the thing about to be attacked and a remembered one may not be there.
     * Everything else — the route, the thermal verdict, what is running — is
     * the same call the run itself will make, so the briefing cannot promise
     * something the run then disagrees with.
     */
    public function setup(Request $request, ChainBuilder $chains, Resolver $resolver): View|RedirectResponse
    {
        $character = $this->character($request);

        if (! $character->isJackedIn()) {
            return redirect()->route('cyberspace.index');
        }

        $target = System::query()->at($character->gridPosition())->first();

        // Nothing here, or nothing that can be entered. The map says so
        // already and this screen has nothing to add.
        if ($target === null || ! $target->type->isInteractable()) {
            return redirect()->route('cyberspace.index');
        }

        $deck = $character->equippedDeck;
        $totals = $deck === null ? null : DeckTotals::forCharacter($character, $deck, $resolver);

        $loadout = InventoryItem::query()
            ->where('character_id', $character->id)
            ->whereNull('running_on_deck_id')
            ->notElsewhere()
            ->whereDoesntHave('offer')
            ->whereHas('item.program', static fn ($q) => $q->where('background_process', false)->whereNotIn('kind', array_map(static fn (ProgramKind $k): string => $k->value, array_filter(ProgramKind::cases(), static fn (ProgramKind $k): bool => $k->neverRunsOnADeck()))))
            ->with('item.program')
            ->get();

        // Scripts: spent by running, so they are offered here and started with the loadout. Each is asked the
        // runner's own question now, against what is already running; the page then does the sum for the ticked
        // ones, and `start()` is the guard.
        $scripts = $deck === null ? collect() : InventoryItem::query()
            ->where('character_id', $character->id)
            ->loose()
            ->whereHas('item.program', static fn ($q) => $q->where('kind', ProgramKind::Script->value)->where('background_process', true))
            ->with('item.program')
            ->get()
            ->sortBy(static fn (InventoryItem $row): string => (string) $row->item->displayName())
            ->values();

        // What they have read out of it, and what they have not. A file with no answers in it is the
        // same as no file for this purpose: both mean going in blind.
        $scan = SystemScan::query()
            ->where('character_id', $character->id)
            ->where('address', $target->address)
            ->live()
            ->first();

        $chain = $chains->for($character, $target);

        // The Assessor's overall for the loadout as it is ticked now. The page asks `assess()` for a new one on every tick.
        $ticked = $loadout
            ->filter(static fn (InventoryItem $row): bool => in_array($row->item_id, self::rememberedLoadout($request), true))
            ->unique('item_id');
        $assess = ($step = Assessor::step($character)) === null ? null : Assessor::tag($this->assessLoadout($character, $scan, $ticked), $step);

        $parties = app(Parties::class);
        $partyHere = $parties->partyOf($character)?->load('members.character', 'owner');

        return view('run.setup', [
            'assess' => $assess,
            // What the system's owner makes of you, on the screen where the
            // run is decided rather than after it.
            'standing' => $target->owner?->interactable
                ? (new StandingLedger)->standingFor($character, $target->owner)
                : 0.0,
            'character' => $character,
            'target' => $target,
            // An accepted Placement job still waiting on this system: the backdoor button then says what it is for.
            'placement' => app(MissionLedger::class)->hasOpenAgainst($character, $target, MissionAction::Plant),
            'scan' => $scan,
            // The same rows the scanner window shows, every fact either way: an unread one says so.
            'overview' => ($scan ?? new SystemScan)->facts($character),
            'chain' => $chain,
            // The party forming at this door, if the character owns or is in one; and how many they may call.
            'party' => $partyHere,
            'coopSlots' => $character->coopSlots(),
            // Who of the party could go in if the owner pressed Start now, and what stops the rest: {character id: reason}.
            'obstacles' => $partyHere === null ? [] : $parties->obstacles($partyHere, $this->runs),
            // What the player has read of each system the trace has to come through, by address. A
            // relay is their own backdoor, but a backdoor says nothing about what is on the machine.
            'hopScans' => SystemScan::query()
                ->where('character_id', $character->id)
                ->whereIn('address', $chain->pluck('address'))
                ->live()
                ->get()
                ->keyBy('address'),
            // What the running programs are spending, against what the deck resolves.
            'spent' => $deck === null ? ['qflops' => 0.0, 'memory' => 0.0] : app(ProgramRunner::class)->spentOn($character, (int) $deck->id),
            'chainCap' => $chains->hopsAllowed($character),
            // What is running, with what each thing IS — an icebreaker and a
            // decryptor are both "a program" until the moment one of them is
            // the only thing standing between you and an ICE.
            'processes' => $character->runningProcesses(),
            // What the run could start: run-only software they hold and are
            // not running. Icebreakers apart, since only one may go in.
            'loadout' => $loadout->partition(static fn (InventoryItem $row): bool => $row->item->program->kind === ProgramKind::Icebreaker),
            // The last loadout that went in, ticked again. Remembered as ITEMS,
            // one row each, because a row id does not survive its stack being
            // split and merged back.
            // A member who has already accepted sees their own choice ticked.
            'chosen' => $partyHere?->members->firstWhere('character_id', $character->id)?->joined_at !== null && $partyHere !== null
                ? ($partyHere->members->firstWhere('character_id', $character->id)->loadout ?? [])
                : $ticked->pluck('id')->all(),
            'totals' => $totals,
            // Why each run-only program would not start beside what is ALREADY running, by row id (the runner's own
            // question, asked as a run would ask it); absent means it would.
            // Asked with everything Suspend could stop counted as stopped: the page's own sum (`data-run-budget`) takes
            // it from there, and a row refused for what is running NOW could never be enabled by ticking a box.
            'loadoutRefusals' => $this->withSuspendable($character, fn () => $loadout
                ->mapWithKeys(fn (InventoryItem $row): array => [$row->id => app(ProgramRunner::class)->refusalFor($character, $row, duringRun: true)])
                ->filter()),
            // The background processes that may be switched off for this run, and which of them cannot be because the
            // deck would then hold less than is stored on it.
            'suspendable' => $suspendable = app(ProgramRunner::class)->suspendable($character),
            'suspendBlocked' => $suspendable
                ->filter(fn (InventoryItem $row): bool => app(ProgramRunner::class)->wouldOverfill($character, [$row->id]))
                ->pluck('id')->all(),
            'scripts' => $scripts,
            // Why each script would not start right now, by row id; absent means it would.
            'scriptRefusals' => $this->withSuspendable($character, fn () => $scripts
                ->mapWithKeys(fn (InventoryItem $row): array => [$row->id => app(ProgramRunner::class)->refusalFor($character, $row)])
                ->filter()),
            // Standing below the SI mark they left for themselves: the first turn pulls them out whatever they press.
            'autoEgress' => app(Simulator::class)->siBelowThreshold($character),
            'ready' => $totals?->isReady() ?? false,
            // A deck's tolerance is its weakest part, not the sum of its
            // parts, so this is asked separately from the totals.
            'thermal' => $deck === null ? null : ThermalExposure::of(
                $totals->netThermalLoad(),
                $deck->fittings,
                $character->modifiers(),
                $resolver,
            ),
            'lockedDown' => $target->isLockedDown(),
            'backdoor' => (new Backdoors)->heldOn($character, $target),
        ]);
    }

    /**
     * The Assessor's overall for a loadout as ticked on the briefing, as JSON: `{overall: "60%"}`. The page asks on every
     * tick; all the arithmetic is the server's. 404 unless an Assessor runs, the same gate the figure itself has.
     */
    public function assess(Request $request): JsonResponse
    {
        $character = $this->character($request);
        $step = Assessor::step($character);
        $target = $character->isJackedIn() ? System::query()->at($character->gridPosition())->first() : null;

        abort_if($step === null || $target === null, 404);

        $ids = array_map('intval', array_filter([$request->query('icebreaker'), ...(array) $request->query('programs', [])], 'is_numeric'));
        $stopped = array_map('intval', array_filter((array) $request->query('suspend', []), 'is_numeric'));
        $rows = InventoryItem::query()
            ->where('character_id', $character->id)
            ->whereNull('running_on_deck_id')
            ->whereIn('id', $ids)
            ->whereHas('item.program')
            ->with('item.program')
            ->get();

        $scan = SystemScan::query()->where('character_id', $character->id)->where('address', $target->address)->live()->first();

        return response()->json(['overall' => Assessor::tag($this->assessLoadout($character, $scan, $rows, $stopped), $step)]);
    }

    /**
     * The overall chance with these stash rows counted as running. They are assumed on the in-memory character only: nothing
     * is started or saved, and the stacking, the pools and every modifier go through the same sources as a real run.
     *
     * @param  Collection<int, InventoryItem>  $rows
     * @param  list<int>  $stopped  running rows to count as switched off
     */
    private function assessLoadout(Character $character, ?SystemScan $scan, Collection $rows, array $stopped = []): ?float
    {
        $character->assumedRunning = $rows->values();
        $character->assumedStopped = $stopped === [] ? null : $stopped;

        // Cleared afterwards: the page goes on to read what is really spent from this same character.
        try {
            return app(Assessor::class)->overall($character, $scan);
        } finally {
            $character->assumedRunning = null;
            $character->assumedStopped = null;
        }
    }

    /** Runs `$ask` with every background process the Suspend box offers assumed stopped, on the in-memory character only. */
    private function withSuspendable(Character $character, \Closure $ask): mixed
    {
        $character->assumedStopped = app(ProgramRunner::class)->suspendable($character)->pluck('id')->all() ?: null;

        try {
            return $ask();
        } finally {
            $character->assumedStopped = null;
        }
    }

    /** Go in. The purpose is chosen here and never changed. */
    public function start(Request $request): RedirectResponse
    {
        $character = $this->character($request);

        $data = $request->validate([
            'purpose' => ['required', Rule::in(RunPurpose::values())],
            // Only a backdoor run carries one, and `RunStarter` drops it for
            // every other purpose rather than trusting the form.
            'backdoor_kind' => ['nullable', Rule::in(BackdoorKind::values())],
            // The loadout: inventory rows of run-only software. One icebreaker
            // by radio, anything else by checkbox. Ownership and every other
            // rule is `ProgramRunner`'s, asked when the run starts them.
            'icebreaker' => ['nullable', 'integer'],
            'programs' => ['nullable', 'array', 'max:20'],
            'programs.*' => ['integer', 'distinct'],
            // Scripts to run before the run begins: loose rows of a Script, started after the loadout and
            // spent by running. Everything they must fit is `ProgramRunner`'s.
            'scripts' => ['nullable', 'array', 'max:20'],
            'scripts.*' => ['integer', 'distinct'],
            // Running background processes to switch off for the run; the runner takes only what it offers.
            'suspend' => ['nullable', 'array', 'max:50'],
            'suspend.*' => ['integer', 'distinct'],
        ]);

        $ids = array_values(array_filter([$data['icebreaker'] ?? null, ...($data['programs'] ?? [])]));
        $rows = $ids === [] ? collect() : InventoryItem::query()->whereKey($ids)->with('item.program')->get();

        // Only a Script may come in through this field: a posted id of anything else is not a loadout.
        $scriptRows = ($data['scripts'] ?? []) === [] ? collect() : InventoryItem::query()
            ->whereKey($data['scripts'])
            ->loose()
            ->whereHas('item.program', static fn ($q) => $q->where('kind', ProgramKind::Script->value))
            ->with('item.program')
            ->get();

        // A member of somebody else's party does not start a run of their own: the owner starts it for everybody.
        $parties = app(Parties::class);
        $party = $parties->partyOf($character);

        if ($party !== null && $party->owner_character_id !== $character->id) {
            // Only somebody who has joined is committed to the owner's run; a bare invitation is dropped and the run is theirs.
            if ($party->members()->where('character_id', $character->id)->whereNotNull('joined_at')->exists()) {
                return back()->with('error', PartyRefusal::NotTheOwner->message());
            }

            $parties->leave($character);
            $party = null;
        }

        // The party forms at the door: an owner who walked off does not take it with them.
        $at = $character->gridPosition();

        if ($party !== null && $party->started_at === null && ($at === null || $at->x !== $party->x || $at->y !== $party->y)) {
            return back()->with('error', PartyRefusal::NotAtTheDoor->message());
        }

        $result = $parties->launch(
            $character,
            RunPurpose::from($data['purpose']),
            ($data['backdoor_kind'] ?? null) === null ? null : BackdoorKind::from($data['backdoor_kind']),
            $rows->concat($scriptRows)->all(),
            $this->runs,
            array_map('intval', $data['suspend'] ?? []),
        );

        if (! $result->succeeded()) {
            return back()->with('error', $result->message());
        }

        // A loadout that WENT IN is remembered, so the next briefing starts
        // from it — including an empty one, which is a choice too.
        return redirect()->route('run.show')->withCookie(cookie(
            self::LOADOUT_COOKIE,
            $rows->pluck('item_id')->unique()->implode(','),
            self::LOADOUT_MINUTES,
        ));
    }

    /** @return list<int> */
    private static function rememberedLoadout(Request $request): array
    {
        return array_values(array_map('intval', array_filter(
            explode(',', (string) $request->cookie(self::LOADOUT_COOKIE, '')),
            'is_numeric',
        )));
    }

    /**
     * The run, as it stands.
     *
     * A player with no live run is sent back to the map rather than shown an
     * empty screen: the lock only holds somebody who is IN one, so arriving
     * here without one means it ended while they were looking elsewhere.
     */
    public function show(Request $request, Simulator $simulator): Response|RedirectResponse
    {
        $character = $this->character($request);
        $run = $this->liveRun($character);

        // An automatic fortress raid pulls a file every five seconds: catch up what has come due.
        if ($run !== null && $run->pull_at !== null) {
            $simulator->catchUpRaid($character, $run);
            $run->refresh();
        }

        // A party round that is overdue resolves on whoever's request arrives first, this one included.
        if ($run !== null && PartyTurns::shared($run)) {
            // The first round's clock waits for every runner to have seen it.
            if ($run->party_seen_at === null) {
                $run->forceFill(['party_seen_at' => now()])->save();
            }

            app(PartyTurns::class)->settle((int) $run->run_party_id);
            $run = $this->liveRun($character->fresh());
        }

        if ($run === null) {
            return redirect()->route('cyberspace.index');
        }

        // Waiting on the others: the page checks again in a few seconds, since the clock is whoever asks.
        // Anybody's choice counts: the runner who has not chosen yet must see the round move too.
        $waiting = $run->pending_action !== null && PartyTurns::shared($run);
        // A party's page checks again on its own whether or not anybody has chosen: the clock is whoever asks, and a
        // round nobody touches must still run out.
        $shared = $run->run_party_id !== null && PartyTurns::shared($run);
        $refresh = $run->pull_at !== null || $shared;

        $system = $run->system();

        $response = response()->view('run.show', [
            'assess' => $this->assessment($character, $run),
            'character' => $character,
            'run' => $run,
            'waiting' => $waiting,
            'shared' => $shared,
            'roundSeconds' => $shared ? PartyTurns::secondsLeft((int) $run->run_party_id) : null,
            // Install Leecher is offered only with a Leecher in the stash and a fortress to feed.
            'fortresses' => $character->inventory()->loose()->whereHas('item.program', static fn ($q) => $q->where('kind', ProgramKind::Leecher->value))->exists()
                ? System::query()->where('owner_character_id', $character->id)->orderBy('id')->get()
                : collect(),
            'system' => $system,
            // Worked out here rather than in the template: it is the number the
            // egress decision is made on, and a player should not have to do
            // the division while something is hitting them.
            'turnsLeft' => $run->turnsUntilTraced(),
            // THE SAME ASSEMBLY THE ROLL USES, and it carries its own working
            // — the button quotes `percent()`, the card beside it explains
            // where that came from, and the attempt spends the same number.
            // An unexplained collapse in the odds is indistinguishable from a
            // bug, which is the whole reason the explanation is on the screen
            // rather than in a log line.
            'egress' => $simulator->egressReport($character, $run),
            // On or past one of the player's standing instructions: the next turn pulls them out on
            // its own. The game's own test, not a second copy of it.
            'thresholdBreached' => $simulator->thresholdBreached($character, $run),
            /*
             * WHAT THE CORE WILL ANSWER TO, as a percentage, or null when
             * there is nothing to get past.
             *
             * The failure line is silent by design — it cannot say which of
             * three things stopped a core action without handing over a scan
             * fact — so this is the other half of that bargain: the player is
             * shown the odds BEFORE they spend the turn, and a streak of
             * nothing is then a number they were told rather than a bug they
             * have to guess at. It never names the operating system.
             */
            'core' => ($chance = $simulator->coreChance($character, $run, $system)) === null
                ? null
                : (int) round($chance * 100),
        ]);

        return $refresh ? $response->header('Refresh', '5') : $response;
    }

    /**
     * The Assessor's lines for this turn, worded and rounded, or null when none runs. Only what is a roll or a count:
     * the chance the purpose action gets through unnoticed and past the core, and while an ICE fights, its strike and
     * the turns the icebreaker needs. Egress and the core already show their exact figures.
     *
     * @return array{turn?: HtmlString, strike?: HtmlString, breaks?: string}|null
     */
    private function assessment(Character $character, Run $run): ?array
    {
        if (($step = Assessor::step($character)) === null) {
            return null;
        }

        $assessor = app(Assessor::class);
        $scan = SystemScan::query()->where('character_id', $character->id)->where('address', $run->address)->live()->first();
        $out = [];

        if ($run->inAFight()) {
            $out['strike'] = Assessor::line('assessor.strikes', Assessor::tag($assessor->strike($scan), $step));
            $turns = $assessor->turnsToBreak($character, $scan, (float) $run->ice_integrity);
            $out['breaks'] = $turns === null ? Text::get('assessor.unknown') : (string) $turns;
        } elseif (in_array($run->purpose, [RunPurpose::Extraction, RunPurpose::Backdoor, RunPurpose::Burn], true)) {
            $seen = $run->detected ? 1.0 : ($run->extracting_qb !== null ? $assessor->unnoticedPull($character, $scan) : $assessor->unnoticed($character, $scan));
            $core = $run->os_locked ? $assessor->core($character, $scan) : 1.0;
            $out['turn'] = Assessor::line('assessor.this_turn', Assessor::tag($seen === null || $core === null ? null : $seen * $core, $step));
        }

        return $out;
    }

    /**
     * Spend a turn.
     *
     * ONE DOOR FOR EVERY ACTION, so a turn always costs the same thing whatever
     * it was spent doing — which is the economy the whole run rests on.
     */
    public function act(Request $request, Simulator $simulator): RedirectResponse
    {
        $character = $this->character($request);
        $run = $this->liveRun($character);

        if ($run === null) {
            return redirect()->route('cyberspace.index');
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(RunAction::values())],
            // Which of your fortresses a Leecher feeds; blank is the first you own.
            'fortress' => ['nullable', 'integer'],
        ]);

        // A party round: the choice is stored, and the round resolves when all have chosen or the clock runs out.
        if (PartyTurns::shared($run)) {
            app(PartyTurns::class)->submit($run, RunAction::from($data['action']));

            $run->refresh();

            if ($run->isLive()) {
                return redirect()->route('run.show');
            }

            return redirect()->route('cyberspace.index')->with('status', RunEnding::from((string) $run->ending)->message());
        }

        // A run against a Data Fortress takes a turn a fixed number of seconds apart, so its owner has time to react.
        if ($run->system()?->type->isFortress()) {
            $gap = (int) config('cyberspace.fortress.turn_seconds', 30);
            $wait = $run->turn_at === null ? 0 : $gap - (int) $run->turn_at->diffInSeconds(now());

            if ($wait > 0) {
                return back()->with('error', Text::get('cyberspace.run.wait_turn', ['seconds' => $wait]));
            }

            $run->forceFill(['turn_at' => now()])->save();
        }

        return $this->resolve(
            $character,
            $run,
            $run->system(),
            $simulator->take($character, $run, RunAction::from($data['action']), fortressId: isset($data['fortress']) ? (int) $data['fortress'] : null),
            $simulator,
        );
    }

    /**
     * What a turn's outcome means for the response.
     *
     * SHARED BY BOTH DOORS TO LEAVING, so the standalone egress route and the
     * Egress button cannot end a run differently — which is the same reason
     * they now share the roll.
     */
    private function resolve(
        Character $character,
        Run $run,
        // Read BEFORE the turn: a burn deletes the system, and the after-run door filters on what it was.
        ?System $system,
        TurnResult $result,
        Simulator $simulator,
    ): RedirectResponse {
        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        if (! $result->ended()) {
            // Between turns: the run door. Opened on the locked character, in its own write.
            $opened = null;

            if ($system !== null) {
                $opened = DB::transaction(fn () => app(Encounters::class)->maybeOpenFor(
                    Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail(),
                    EncounterStart::Run,
                    $system,
                    $run,
                ));
            }

            return redirect()->route($opened === null ? 'run.show' : 'encounter.show');
        }

        // A BAD ending (a defeat, a burnout) ejects and puts them on the floor, through the one place that knows
        // the order. An egress and a COMPLETED run just end: they got what they came for and are still standing on
        // the grid. This used to ask `!== Egress`, which sent a completed run through the ejection too, so somebody
        // who extracted, or installed a backdoor, and then pulled out was thrown off the grid.
        if ($result->ending->isBad()) {
            $simulator->resolveEnding($character, $run, $result->ending);
        }

        // WHAT THE FINAL TURN HAS TO SAY, ahead of the ending: the run screen is gone by now, so a line the game wrote to explain
        // the ending (an auto-egress naming its threshold) would otherwise be read by nobody.
        $notices = array_column(array_filter($result->log, static fn (array $line): bool => ! empty($line['notice'])), 'text');

        // The after-run door, once the ending has settled. Somebody a bad ending put on the floor is not up to be met (canHappenTo).
        $opened = $system === null ? null : DB::transaction(fn () => app(Encounters::class)->maybeOpenFor(
            Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail(),
            EncounterStart::AfterRun,
            $system,
            $run->refresh(),
        ));

        return redirect()->route($opened === null ? 'cyberspace.index' : 'encounter.show')
            ->with($result->ending->isBad() ? 'error' : 'status', implode(' ', [...$notices, $result->ending->message()]));
    }

    /**
     * Leave.
     *
     * THROUGH THE SIMULATOR, exactly as the turn-based action is. It used to
     * end the run outright, which was correct while there was no roll to make
     * — and became a free exit the moment there was one: a player who found
     * this route could walk out of a fight an Egress button was busy failing
     * at. One door, one roll, one turn spent.
     *
     * The route stays rather than being deleted, because it is what existing
     * forms and links point at, and because "leave" wanting its own URL is
     * reasonable even when it resolves like any other action.
     */
    public function egress(Request $request, Simulator $simulator): RedirectResponse
    {
        $character = $this->character($request);
        $run = $this->liveRun($character);

        if ($run === null) {
            return redirect()->route('cyberspace.index');
        }

        // In a party, leaving is a choice of the round like any other.
        if (PartyTurns::shared($run)) {
            app(PartyTurns::class)->submit($run, RunAction::Egress);

            return redirect()->route($run->refresh()->isLive() ? 'run.show' : 'cyberspace.index');
        }

        return $this->resolve($character, $run, $run->system(), $simulator->take($character, $run, RunAction::Egress), $simulator);
    }

    private function liveRun(Character $character): ?Run
    {
        $run = $character->currentRun()->first();

        return $run?->isLive() === true ? $run : null;
    }

    private function character(Request $request): Character
    {
        return $request->user()->character;
    }
}
