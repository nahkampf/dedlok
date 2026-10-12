<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\City\Encounters;
use App\City\EncounterStart;
use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Cyberspace\ChainBuilder;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Cyberspace\TurnResult;
use App\Decks\DeckTotals;
use App\Decks\ThermalExposure;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Run;
use App\Models\System;
use App\Models\SystemScan;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Standing\StandingLedger;
use App\Stats\Resolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->whereHas('item.program', static fn ($q) => $q->where('background_process', false))
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

        return view('run.setup', [
            // What the system's owner makes of you, on the screen where the
            // run is decided rather than after it.
            'standing' => $target->owner?->interactable
                ? (new StandingLedger)->standingFor($character, $target->owner)
                : 0.0,
            'character' => $character,
            'target' => $target,
            'scan' => $scan,
            // The same rows the scanner window shows, every fact either way: an unread one says so.
            'overview' => ($scan ?? new SystemScan)->facts($character),
            'chain' => $chain,
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
            'chosen' => $loadout
                ->filter(static fn (InventoryItem $row): bool => in_array($row->item_id, self::rememberedLoadout($request), true))
                ->unique('item_id')
                ->pluck('id')
                ->all(),
            'totals' => $totals,
            // Why each run-only program would not start beside what is ALREADY running, by row id (the runner's own
            // question, asked as a run would ask it); absent means it would.
            'loadoutRefusals' => $loadout
                ->mapWithKeys(fn (InventoryItem $row): array => [$row->id => app(ProgramRunner::class)->refusalFor($character, $row, duringRun: true)])
                ->filter(),
            'scripts' => $scripts,
            // Why each script would not start right now, by row id; absent means it would.
            'scriptRefusals' => $scripts
                ->mapWithKeys(fn (InventoryItem $row): array => [$row->id => app(ProgramRunner::class)->refusalFor($character, $row)])
                ->filter(),
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

        $result = $this->runs->start(
            $character,
            RunPurpose::from($data['purpose']),
            ($data['backdoor_kind'] ?? null) === null ? null : BackdoorKind::from($data['backdoor_kind']),
            $rows->concat($scriptRows)->all(),
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
    public function show(Request $request, Simulator $simulator): View|RedirectResponse
    {
        $character = $this->character($request);
        $run = $this->liveRun($character);

        if ($run === null) {
            return redirect()->route('cyberspace.index');
        }

        $system = $run->system();

        return view('run.show', [
            'character' => $character,
            'run' => $run,
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
        ]);

        return $this->resolve(
            $character,
            $run,
            $simulator->take($character, $run, RunAction::from($data['action'])),
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
        TurnResult $result,
        Simulator $simulator,
    ): RedirectResponse {
        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        if (! $result->ended()) {
            // Between turns: the run door. Opened on the locked character, in its own write.
            $opened = null;
            $system = $run->system();

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

        // A defeat ejects and puts them on the floor; an egress just ends. Both
        // go through the one place that knows the order.
        if ($result->ending !== RunEnding::Egress) {
            $simulator->resolveEnding($character, $run, $result->ending);
        }

        // WHAT THE FINAL TURN HAS TO SAY, ahead of the ending: the run screen is gone by now, so a line the game wrote to explain
        // the ending (an auto-egress naming its threshold) would otherwise be read by nobody.
        $notices = array_column(array_filter($result->log, static fn (array $line): bool => ! empty($line['notice'])), 'text');

        return redirect()->route('cyberspace.index')
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

        return $this->resolve($character, $run, $simulator->take($character, $run, RunAction::Egress), $simulator);
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
