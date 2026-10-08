<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Characters\DownedCause;
use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\Decks\DeckTotals;
use App\Missions\MissionAction;
use App\Missions\MissionLedger;
use App\Models\Character;
use App\Models\Ice;
use App\Models\Run;
use App\Models\System;
use App\Programs\ProgramKind;
use App\Standing\StandingLedger;
use App\Standing\StandingReason;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Stats\Resolver;
use App\Stats\SkillStats;
use App\Support\Text;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * One turn of a run, resolved.
 *
 * ONE DOOR FOR EVERY ACTION, so a turn always costs the same thing whatever it
 * was spent doing — which is the economy the whole run rests on. A player
 * choosing to attack, to hold, or to leave spends exactly one turn, and
 * everything the machine does back happens in the same pass.
 *
 * ## The order inside a turn
 *
 * The player acts, then the machine does. That order is not arbitrary: it means
 * a player who lands the blow that breaks an ICE does not then take a hit from
 * something already destroyed, and it means a player who chooses to leave gets
 * their attempt before the thing they are leaving gets another swing.
 *
 * ## What the operating system gate does here
 *
 * Nothing announces itself. A core action rolls against the machine's own
 * operating system — `opensTheCore()` — and a failed roll produces the same
 * line a missed attack gets, because a refusal naming the operating system
 * would hand over a scan fact for free. What the player IS given is the
 * chance, on the screen, before they spend the turn: a streak of nothing has
 * to be a number they were shown rather than a bug they have to guess at.
 * What happens TO the player is unaffected either way: the ICE still swings.
 *
 * Cutting at an ICE does not ask. One program against another needs no
 * vocabulary; only telling the MACHINE to do something does.
 *
 * ## The die is injected
 *
 * Like the market's, the grid's and the probe's, and for the same reason: a
 * probabilistic engine tested against real randomness is a test that passes
 * most of the time.
 */
final readonly class Simulator
{
    /** @param  Closure(int, int): int  $roll */
    public function __construct(
        private Resolver $resolver,
        private RunStarter $runs,
        private WaitingRoom $waitingRoom,
        private CheckRoller $checks,
        private DefeatResolver $defeats,
        private Extractor $extractor,
        private Backdoors $backdoors,
        private Lockdown $lockdown,
        private RaidLedger $raids,
        private Closure $roll,
    ) {}

    public static function fromConfig(
        ?Closure $roll = null,
        ?Resolver $resolver = null,
        ?CheckRoller $checks = null,
    ): self {
        $resolver ??= app(Resolver::class);
        $roll ??= static fn (int $min, int $max): int => random_int($min, $max);

        return new self(
            $resolver,
            new RunStarter($resolver),
            new WaitingRoom($resolver),
            $checks ?? app(CheckRoller::class),
            // Same die as the rest of the turn, so a test that pins Simulator's
            // roll also pins what a defeat does — a Surge or a Flatline is not
            // a second random source to control separately.
            DefeatResolver::fromConfig($roll),
            new Extractor,
            new Backdoors,
            Lockdown::fromConfig($roll),
            // Same die again: a test that pins the turn's roll also pins where
            // a retired machine moves to.
            RaidLedger::fromConfig($roll),
            $roll,
        );
    }

    /**
     * Spend a turn.
     *
     * Everything in here happens in one transaction: a turn that resolved half
     * way would leave a run whose log disagrees with its state, and the log is
     * the only account the player gets.
     */
    public function take(Character $character, Run $run, RunAction $action): TurnResult
    {
        if (! $run->isLive()) {
            return TurnResult::refused(RunRefusal::AlreadyRunning);
        }

        return DB::transaction(function () use ($character, $run, $action): TurnResult {
            $log = [];

            $run->turn++;

            /*
             * THE THRESHOLDS ACT BEFORE ANYTHING ELSE DOES.
             *
             * Checked at the start of the turn, and when one is breached the
             * egress is attempted then and there — whatever the player asked
             * for. That is deliberately not the gentler rule: a threshold that
             * only warned would leave an unresponsive player sitting in a run
             * that never resolves, and it is the standing instruction they left
             * for exactly this moment.
             *
             * It is also what makes `auto-run` possible at all, since a player
             * who hands the whole run to their policy is handing it to this.
             */
            if ($breached = $this->breachedThreshold($character, $run)) {
                // SAYS WHICH ONE, and that the action asked for did not happen: a player who pressed Install and found
                // themselves outside with no backdoor needs the reason in the run's own account.
                // `notice` marks a line the screen must still say when the run ends and the page moves on to the map:
                // the log is kept, but nobody reads a log they were sent away from.
                $log[] = $this->line("cyberspace.run.log.threshold_{$breached}", [
                    'at' => $breached === 'trace' ? (int) $character->egress_trace_at : (int) $character->egress_si_at,
                ], 'warn') + ['notice' => true];
                $action = RunAction::Egress;
            }

            // --- the player acts ---------------------------------------------

            if ($action === RunAction::Egress) {
                /*
                 * ALWAYS POSSIBLE, NEVER CERTAIN. The attempt cannot be taken
                 * away — a run with no exit is a character deleted by a door
                 * they could not see — but it is a roll, and a failed one
                 * costs the turn like any other: the machine still gets its
                 * half of it below, so being held is paid for in trace, in
                 * heat and in one more swing.
                 *
                 * A THRESHOLD-FORCED EGRESS ROLLS TOO, since that path arrives
                 * here by reassigning $action above. CYBERSPACE.md says so in
                 * as many words: a threshold trips and the player egresses,
                 * "which is itself a roll, so retreat can fail and cost a
                 * turn".
                 */
                if ($this->checks->roll(
                    $character->stat('egress')->value,
                    $this->egressDifficulty($run),
                    'egress',
                    $this->egressMultiplier($character, $run),
                )->passed) {
                    $log[] = $this->line('cyberspace.run.log.egress');

                    // COMPLETED IF THEY GOT WHAT THEY CAME FOR, egress
                    // otherwise — "they did what they came to do" is only true
                    // once extraction has actually taken something.
                    $ending = $run->extracted ? RunEnding::Completed : RunEnding::Egress;

                    $run->save();
                    // The turn that ENDS the run is part of its account too: without it a threshold that pulled the
                    // player out at once ("You hit your set threshold, and pull out.") left a run that said only
                    // that it had egressed, and nothing to say why the action they pressed never happened.
                    $this->appendLog($run, $log);
                    $this->runs->end($character, $run, $ending);

                    return TurnResult::resolved($run, $log, $ending);
                }

                // HELD. One line whether the machine merely out-rolled them or
                // something in it has hold of them: which it was is visible on
                // the screen in the chance itself, and does not want saying
                // twice in different words.
                $log[] = $this->line('cyberspace.run.log.egress_held', tone: 'warn');
            } elseif ($action === RunAction::Attack) {
                $log[] = $this->attack($character, $run);
            } elseif ($action === RunAction::Extract) {
                $log = [...$log, ...$this->extract($character, $run)];
            } elseif ($action === RunAction::Install) {
                $log = [...$log, ...$this->install($character, $run)];
            } elseif ($action === RunAction::Burn) {
                [$lines, $burned] = $this->burn($character, $run);
                $log = [...$log, ...$lines];

                /*
                 * THERE IS NOTHING LEFT TO TAKE A TURN AGAINST. The machine is
                 * gone, so it cannot strike back, cannot advance a trace and
                 * cannot be locked down — the early return is the same shape
                 * the last blow against an ICE takes, and for the same reason.
                 *
                 * COMPLETED, because they did what they came to do. That
                 * ending is keyed on `runs.extracted` everywhere else; a burn
                 * is the other way of having finished, and the run is ended
                 * here rather than leaving the player standing in an address
                 * that answers nothing.
                 */
                if ($burned) {
                    $run->save();
                    $this->appendLog($run, $log);
                    $this->runs->end($character, $run, RunEnding::Completed);

                    return TurnResult::resolved($run, $log, RunEnding::Completed);
                }
            } else {
                $log[] = $this->line('cyberspace.run.log.hold');
            }

            // Broken. Nothing is left to hit back, so the machine's half of the
            // turn does not happen — which is what makes the last blow feel
            // like the last blow.
            if ($run->ice_id !== null && $run->ice_integrity <= 0) {
                $log[] = $this->line('cyberspace.run.log.ice_broken', tone: 'ok');
                (new Achievements)->record($character, Metric::IceDefeated);
                $run->forceFill(['ice_id' => null, 'ice_integrity' => null]);
                $run->save();
                $this->appendLog($run, $log);

                return TurnResult::resolved($run, $log);
            }

            // --- and then the machine does ------------------------------------

            $log = [...$log, ...$this->advanceTrace($character, $run)];

            $ending = null;

            if ($run->ice_id !== null) {
                [$line, $ending] = $this->iceStrikes($character, $run);
                $log[] = $line;
            }

            $run->save();
            $this->appendLog($run, $log);

            return TurnResult::resolved($run, $log, $ending);
        });
    }

    /**
     * The trace walks a little further.
     *
     * ONLY ONCE SOMETHING HAS NOTICED. Detection is what wakes the tracer, so a
     * run nothing saw is a run nothing is following — which is the other half
     * of what stealth buys, alongside not having to fight.
     *
     * The rate is read off the machine rather than copied onto the run, unlike
     * the ICE's integrity: integrity is being ground DOWN and so belongs to the
     * fight, while a tracer's speed is a property of the hardware and does not
     * change. A machine that has gone takes its tracer with it, which is the
     * right answer anyway.
     *
     * THE CHAIN DECIDES WHETHER IT MOVES AT ALL. Every backdoored system routed
     * through is asked, by its grade, and the trace only advances if it gets
     * through all of them — so the chances multiply and a long chain is
     * dramatically more protection. An empty chain always advances, which is
     * the no-routing case and the only one that exists today.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function advanceTrace(Character $character, Run $run): array
    {
        if (! $run->detected || $run->trace >= 100) {
            return [];
        }

        $rate = System::query()->where('address', $run->address)->value('tracer_rate');

        if ($rate === null || $rate <= 0) {
            return [];
        }

        if (! $this->traceGetsThrough($character, $run)) {
            return [$this->line('cyberspace.run.log.trace_held')];
        }

        // The guard above already returned on a trace that was at 100, so
        // this is the turn it arrives on or it is not arriving at all.
        $trace = min(100, (int) $run->trace + (int) $rate);
        $run->trace = $trace;

        if ($trace < 100) {
            return [];
        }

        return $this->traceLands($run);
    }

    /**
     * Has either standing instruction come due?
     *
     * The trace passing the mark, or Synaptic Integrity falling below it. Both
     * are percentages and both are the player's own, set once as a play style
     * rather than chosen per run — so this is not the simulator deciding to
     * pull somebody out, it is the simulator doing what it was told.
     *
     * A threshold of zero is never breached, which is how somebody turns one
     * off without needing a second column to say whether it is on.
     *
     * PUBLIC so the run screen can show a warning that is the very test the game acts on: the trace
     * is breached ON the mark, Synaptic Integrity only once it is BELOW it, and a screen with its
     * own copy of that sum would one day disagree with what the next turn actually does.
     */
    public function thresholdBreached(Character $character, Run $run): bool
    {
        return $this->breachedThreshold($character, $run) !== null;
    }

    /** Which standing instruction has come due: 'trace', 'si', or null. THE one test of it. */
    public function breachedThreshold(Character $character, Run $run): ?string
    {
        $trace = (int) $character->egress_trace_at;

        if ($trace > 0 && $run->trace >= $trace) {
            return 'trace';
        }

        $si = (int) $character->egress_si_at;

        if ($si <= 0) {
            return null;
        }

        $max = $character->maximumFor('current_synaptic_integrity');

        return $max > 0
            && ((int) $character->current_synaptic_integrity / $max) * 100 < $si
            ? 'si'
            : null;
    }

    /**
     * Does the trace get past the NEXT hop this turn?
     *
     * A TRACE WALKS THE CHAIN, one system at a time. Each turn is a single
     * attempt at the next one in the route — pass it and the trace moves up and
     * the turn ends, fail and it stays put and the turn ends anyway. **A hop
     * once passed is behind it** and is never rolled again, which is the whole
     * difference between a long chain being slow and a long chain being
     * impassable.
     *
     * The chance is what that system's GRADE allows: a grade 1 relay never
     * holds it up and a grade 9 holds it four turns in five. So the turns a
     * chain buys is the sum of 1/p over its hops — three grade 9s is about
     * fifteen turns of nothing happening, which is what ten successful backdoor
     * runs ought to be worth.
     *
     * Past the end of the chain there is nothing left in the way and the trace
     * comes on unopposed. An EMPTY chain is that case from the first turn,
     * which is the only one that exists today.
     */
    private function traceGetsThrough(Character $character, Run $run): bool
    {
        $grades = $run->routingGrades();

        /*
         * WALK PAST THE GAPS FIRST, and for free.
         *
         * A hop that has gone dark — somebody else's lockdown, a burn, the
         * nightly ageing roll — is not in the way of anything, so it costs the
         * trace nothing and holds it up for nothing. The stored route is never
         * rewritten, so these are gaps in a fixed list rather than a list that
         * shrank: a hop already passed stays passed, and one that vanishes
         * ahead of the trace simply gives it one less to get through.
         *
         * Done in the same turn as the roll that follows, because a gap is not
         * an obstacle and a turn spent on nothing would be the trace being
         * hindered by a machine that is not there.
         */
        // `array_key_exists` and not `??`: a hop that is PRESENT AND NULL is a
        // gap to step over, and one that is absent is the end of the route.
        // Null coalescing cannot tell those apart, which is exactly the bug
        // this loop had first time out.
        while (array_key_exists((int) $run->trace_hop, $grades)
            && $grades[(int) $run->trace_hop] === null) {
            $run->trace_hop = (int) $run->trace_hop + 1;
        }

        $next = $grades[(int) $run->trace_hop] ?? null;

        if ($next === null) {
            return true;
        }

        /** @var array<int, int> $table */
        $table = Config::get('cyberspace.trace.advance', []);

        // The hop's GRADE sets the base; the runner's modifiers move it. A skill
        // that makes a chain hold better is a negative modifier on this stat.
        $chance = $this->resolver
            ->resolve('trace_hop_chance', (float) ($table[$next] ?? 100), $character->modifiers())
            ->value;

        if (($this->roll)(1, 100) > $chance) {
            return false;
        }

        // Through it, and past it for good.
        $run->trace_hop = (int) $run->trace_hop + 1;

        return true;
    }

    /**
     * It reached you.
     *
     * The chain burns now. The HEAT does not land here: it is charged as the
     * run ends, by `RunStarter::chargeTheTrace()`, because a burn that lands
     * later in the same run replaces it rather than adding to it — and that is
     * not known until the run is over.
     *
     * Burning the chain is a no-op with nothing in it: a completed trace takes
     * every backdoor on the route, and there are no backdoors. It is called
     * anyway, because the day there are is not the day this should be
     * remembered.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function traceLands(Run $run): array
    {
        $this->burnTheChain($run);

        return [$this->line('cyberspace.run.log.traced', tone: 'crit')];
    }

    /**
     * Every backdoor on the route is gone.
     *
     * NOTHING TO BURN YET. A trace passing THROUGH a backdoored system does
     * nothing to it — that is what the chain is for — but one that reaches 100
     * has followed the path all the way home, and every system on it is
     * exposed: the backdoors go, the systems lock down and relocate.
     *
     * That is the cost that replaces latency, and it is why a longer chain is
     * more protection and more to lose at once.
     */
    private function burnTheChain(Run $run): void
    {
        $chain = (array) ($run->chain ?? []);

        if ($chain === []) {
            return;
        }

        /*
         * A TRACE THAT REACHED YOU FOLLOWED THE PATH ALL THE WAY HOME.
         *
         * Every system on the route is exposed: the backdoors are gone, the
         * machines shut, and when their windows close they relocate. That is
         * the cost that makes a long chain a decision rather than a free
         * upgrade — ten hops is ten successful backdoor runs and one bad night
         * takes all of them.
         *
         * A trace PASSING THROUGH a hop does nothing to it; this is only ever
         * reached by one that finished. Shutting is the whole of it, because
         * `Lockdown::shut()` already closes every door into a machine and the
         * relocation follows from the window closing — so the two halves of
         * "the backdoors are gone and the systems move" are one call.
         *
         * Machines that have already gone are simply not found, which is the
         * ordinary way a route decays and needs no case of its own.
         */
        foreach (System::query()->whereIn('id', $chain)->get() as $hop) {
            $this->lockdown->shut($hop);
        }
    }

    /**
     * Cut at the ICE.
     *
     * TWO WAYS THIS DOES NOTHING, and they are deliberately indistinguishable
     * in the log: no icebreaker running, or nothing awake to cut at. The
     * operating system is NOT one of them — an icebreaker fighting an ICE is
     * one program against another, and the vocabulary only matters when the
     * machine itself is being told to do something. That is the core's roll,
     * not this one's.
     *
     * @return array{text: string, tone: string}
     */
    private function attack(Character $character, Run $run): array
    {
        $damage = $this->resolver
            ->resolve('icebreaker_damage', 0.0, $character->modifiers())
            ->toInt();

        // A running icebreaker is the gate, and `icebreaker_damage` floors at
        // zero to say so — but the stat alone cannot tell a character with no
        // icebreaker from one whose icebreaker is worth nothing, so the
        // process itself is the question.
        $icebreaker = $character->runningProcesses()
            ->first(fn ($process): bool => $process->item?->program?->kind === ProgramKind::Icebreaker);

        if ($icebreaker === null || $damage <= 0 || $run->ice_id === null) {
            return $this->line('cyberspace.run.log.attack_fails');
        }

        $run->ice_integrity = max(0, (int) $run->ice_integrity - $damage);

        // "Your [Icebreaker name] finds an intrusion vector…": the program is a green tag in the middle of the
        // sentence, so the line carries what comes before the tag as well as what follows it.
        return $this->line(
            'cyberspace.run.log.attack',
            ['damage' => $damage],
            'ok',
            (string) $icebreaker->item?->displayName(),
            'ok',
            'cyberspace.run.log.attack_before',
        );
    }

    /**
     * Take what the machine is holding.
     *
     * ## The IDS's second chance
     *
     * Extraction TOUCHES THE CORE — CYBERSPACE.md names it alongside burning,
     * backdooring and injecting — so it is the second place the IDS gets a
     * look, independent of whatever happened on approach. Rolled only while
     * `! $run->detected`: once something has noticed, there is nothing further
     * to discover, and asking again would just be asking whether they are
     * STILL noticed.
     *
     * The roll fires REGARDLESS of whether the extraction itself goes on to
     * succeed — touching the core is the act that risks it, not the result.
     *
     * ## One line for every reason it can fail
     *
     * An operating system the runner cannot speak, a purpose that is not
     * `RunPurpose::Extraction`, and a machine with nothing left to give all
     * wear the SAME sentence — `attack_fails`'s discipline again: a line that
     * told them apart would let a player learn which one they were facing by
     * elimination, and the OS gate exists precisely so that cannot happen. The
     * UI only offers this button on an extraction run, so the purpose check is
     * a defence against a hand-built request rather than something a player
     * ordinarily meets.
     *
     * ## Creds and items are instant; a file costs turns
     *
     * CYBERSPACE.md prices data in QB, cut back down by the deck's bandwidth —
     * "a few turns, scaling with the size of the data." Creds and catalogue
     * items come out the moment the core is touched, same as before; a file
     * starts a countdown on `runs.extracting_qb` and `continueTransfer()`
     * spends it down a bandwidth's worth per turn. **Continuing one still goes
     * through this same method and this same turn**, which is the whole
     * point: if the core touch woke the ICE, it gets to swing on every turn a
     * transfer runs, exactly as it would against Attack or Hold. Nothing about
     * a transfer resolves outside the ordinary turn cycle.
     *
     * The core-touch detection roll fires only on the turn that BEGINS a pull
     * — touching the core is the act that risks it, and a transfer already
     * running is not touching it again.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function extract(Character $character, Run $run): array
    {
        // CONTINUING. Spends this turn's bandwidth against what is already
        // owed. The core is not touched again, but the IDS watches every turn
        // a pull runs — so a long file is a gamble as well as slow. ICE it
        // wakes guards the core like any other, and the pull waits, kept.
        if ($run->extracting_qb !== null) {
            $log = $this->idsWatchesThePull($character, $run);

            if ($run->inAFight()) {
                return [...$log, $this->iceGuardsTheCore()];
            }

            return [...$log, ...$this->continueTransfer($character, $run)];
        }

        $system = System::query()->where('address', $run->address)->first();

        $log = $this->touchTheCore($character, $run, $system);

        if ($run->inAFight()) {
            return [...$log, $this->iceGuardsTheCore()];
        }

        if ($system === null || ! $this->opensTheCore($character, $run, $system) || $run->purpose !== RunPurpose::Extraction) {
            $log[] = $this->line('cyberspace.run.log.extract_fails');

            return $log;
        }

        $instant = $this->extractor->takeCredsAndItems($character, $run->address);
        $fileQb = $this->extractor->totalFileQb($run->address);

        if ($instant->tookNothing() && $fileQb <= 0 && ! $instant->leftBehind) {
            $log[] = $this->line('cyberspace.run.log.extract_fails');

            return $log;
        }

        if (! $instant->tookNothing()) {
            $this->recordTake($character, $run);
            $log[] = $this->line('cyberspace.run.log.extract', ['summary' => $this->summarize($instant)], 'ok');
        }

        // Asked BEFORE the countdown: turns spent pulling a file the deck
        // cannot hold would be turns spent exposed for nothing.
        $filesFit = $fileQb <= 0 || $this->extractor->filesFit($character, $run->address);

        if ($instant->leftBehind || ! $filesFit) {
            $log[] = $this->line('cyberspace.run.log.no_room', tone: 'warn');
        }

        if ($fileQb > 0 && $filesFit) {
            $log = [...$log, ...$this->beginTransfer($character, $run, $fileQb)];
        }

        return $log;
    }

    /**
     * Spend the first turn of a pull, against the whole file.
     *
     * A SMALL FILE NEVER SEES THE COUNTDOWN — if one turn's bandwidth clears
     * it, this completes on the spot rather than writing a one-turn-long
     * `extracting_qb` just to read it back and clear it again next turn.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function beginTransfer(Character $character, Run $run, int $fileQb): array
    {
        $bandwidth = $this->bandwidthFor($character);
        $remaining = $fileQb - $bandwidth;

        if ($remaining <= 0) {
            return $this->completeTransfer($character, $run);
        }

        $run->extracting_qb = $remaining;

        return [$this->line('cyberspace.run.log.transfer_begins', ['qb' => $fileQb, 'bandwidth' => $bandwidth])];
    }

    /**
     * Spend another turn against a pull already under way.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function continueTransfer(Character $character, Run $run): array
    {
        $remaining = (int) $run->extracting_qb - $this->bandwidthFor($character);

        if ($remaining <= 0) {
            $run->extracting_qb = null;

            return $this->completeTransfer($character, $run);
        }

        $run->extracting_qb = $remaining;

        return [$this->line('cyberspace.run.log.transfer_continues', ['remaining' => $remaining])];
    }

    /**
     * The countdown reaches zero: take the files, or find out somebody
     * already did.
     *
     * NOTHING TO TAKE is an ordinary answer, exactly as it is for an instant
     * haul — a slower raider losing a race they started first is the cost of
     * pricing a file in turns at all.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function completeTransfer(Character $character, Run $run): array
    {
        $run->extracting_qb = null;

        $result = $this->extractor->takeFiles($character, $run->address);

        if ($result->leftBehind) {
            return [$this->line('cyberspace.run.log.no_room', tone: 'warn')];
        }

        if ($result->tookNothing()) {
            return [$this->line('cyberspace.run.log.transfer_gone')];
        }

        $this->recordTake($character, $run);

        return [$this->line('cyberspace.run.log.extract', ['summary' => $this->summarize($result)], 'ok')];
    }

    /**
     * Can the runner speak to the machine itself yet?
     *
     * ## It is a roll, and it is the last thing between a run and its purpose
     *
     * Cutting at an ICE is one program against another and needs no vocabulary.
     * Telling a machine to hand something over, to hold a door open or to
     * destroy itself is said in the operating system it runs — so every core
     * action asks this first, and none of the other three ever does.
     *
     * The value is the runner's LEVEL in that skill and nothing else: level 5
     * lands on the certainty floor, no skill at all is under a twentieth, and
     * the curve between them is in `config/cyberspace.os`. A machine running
     * nothing is open to anybody.
     *
     * ## Once it is open it stays open
     *
     * `runs.os_locked` is what holds the answer, so the second turn of a
     * two-turn extraction does not re-roll a machine already being spoken to.
     * That is also why the column is per-RUN: going back in tomorrow means
     * finding the way in again.
     *
     * ## A failed attempt costs the turn and says nothing
     *
     * The caller's own failure line covers it — the same sentence a wrong
     * purpose and an empty machine get — because a line naming the operating
     * system would hand over a scan fact the player did not pay for. What they
     * are given instead is the CHANCE, on the screen, before they spend the
     * turn: see `coreChance()`. A streak of nothing is then a number they were
     * shown rather than a bug they have to guess at.
     */
    private function opensTheCore(Character $character, Run $run, ?System $system): bool
    {
        if (! $run->os_locked) {
            return true;
        }

        if (! $this->checks->roll($this->osValue($character, $system), $this->osDifficulty(), 'os')->passed) {
            return false;
        }

        $run->forceFill(['os_locked' => false]);

        return true;
    }

    /**
     * What the player is told before they spend the turn.
     *
     * Null when there is nothing to crack — no machine, or one already being
     * spoken to — which is the screen's cue to say nothing at all rather than
     * to print a hundred per cent. Public and separate from the roll for the
     * reason `egressChance()` is: a warning and the attempt must not be able
     * to disagree.
     */
    public function coreChance(Character $character, Run $run, ?System $system = null): ?float
    {
        if (! $run->os_locked) {
            return null;
        }

        $system ??= System::query()->where('address', $run->address)->first();

        if ($system === null) {
            return null;
        }

        return $this->checks->chance($this->osValue($character, $system), $this->osDifficulty(), 'os');
    }

    /**
     * The runner's standing in the machine's own language.
     *
     * LEVEL, not the check stat a skill also carries. This is "have you worked
     * with one of these", which is what a level says and what the design asked
     * for in as many words — attributes have nothing to do with whether you
     * have seen a filesystem before.
     *
     * The level indexes a TABLE rather than multiplying a constant, because
     * the curve is hand-shaped: the first level is worth nearly twice the
     * fourth. A level past the end of the table reads as the last entry, which
     * is the only sane answer if the progression ever grows a sixth.
     */
    private function osValue(Character $character, ?System $system): float
    {
        $skill = $system?->operatingSystem()->first();

        if ($skill === null) {
            return 0.0;
        }

        $level = (int) $character->characterSkills()
            ->where('skill_id', $skill->getKey())
            ->value('attained_level');

        /** @var array<int, float> $values */
        $values = (array) Config::get('cyberspace.os.values', []);

        if ($values === []) {
            return 0.0;
        }

        $base = (float) ($values[$level] ?? ($level > array_key_last($values) ? end($values) : reset($values)));

        // The LEVEL sets the base off the table; anything else that helps — a
        // program, an implant, a dose — is a modifier on the skill's own check,
        // and the roll never learns it exists.
        return $this->resolver
            ->resolve(SkillStats::checkId($skill->slug), $base, $character->modifiers())
            ->value;
    }

    private function osDifficulty(): float
    {
        return (float) Config::get('cyberspace.os.difficulty', 24);
    }

    /**
     * What the odds of leaving ARE, without spending them.
     *
     * PUBLIC AND SEPARATE FROM THE ROLL, which is the rule `CheckRoller` was
     * built around: a screen that says "about one in three" and the attempt
     * that follows have to be the same calculation, or the player is deciding
     * against a number the game does not believe.
     */
    public function egressChance(Character $character, Run $run): float
    {
        return $this->egressReport($character, $run)->chance;
    }

    /**
     * The same odds, with the working shown.
     *
     * ONE ASSEMBLY FOR ALL FOUR READERS — the button, the card that explains
     * it, the roll and the tests. `egressChance()` is a field of this rather
     * than a second calculation, so a screen cannot quote a number the attempt
     * does not use.
     *
     * The pool's terms are resolved off the SHEET, which is the only place a
     * pool exists: its base is the attributes added and divided once, and
     * asking the resolver for it with a base of zero returns the modifiers
     * alone. The steps come off the same `ResolvedStat` the value does, so the
     * explanation is the resolver's own account and not a reconstruction.
     */
    public function egressReport(Character $character, Run $run): EgressReport
    {
        $sheet = $character->sheet();
        $resolved = $sheet->get('egress');

        /** @var array{attributes: list<string>, divisor: float} $pool */
        $pool = (array) Config::get('stats.pools.egress', ['attributes' => [], 'divisor' => 1.0]);

        $terms = [];

        foreach ((array) $pool['attributes'] as $attribute) {
            $terms[$attribute] = $sheet->value($attribute);
        }

        /** @var array<int, int> $table */
        $table = Config::get('cyberspace.egress.difficulty', []);

        $grade = System::query()->where('address', $run->address)->value('grade');
        $grade = $grade === null ? null : (int) $grade;
        $gradeDifficulty = (int) ($table[$grade] ?? ($table[1] ?? 0));

        $ice = $run->ice_id === null ? null : Ice::query()->find($run->ice_id);
        $snare = $ice?->attackLevel(AttackTag::Ensnare);
        $multiplier = $this->ensnareMultiplier($character, $snare);
        $difficulty = (int) $this->egressDifficulty($run);

        return new EgressReport(
            value: $resolved->value,
            base: $resolved->base,
            terms: $terms,
            divisor: (float) $pool['divisor'],
            steps: $resolved->steps,
            difficulty: $difficulty,
            gradeDifficulty: $gradeDifficulty,
            grade: $grade,
            iceLevel: $ice === null ? null : (int) $ice->level,
            snareLevel: $snare,
            multiplier: $multiplier,
            chance: $this->checks->chance($resolved->value, (float) $difficulty, 'egress', $multiplier),
        );
    }

    /**
     * The grade, plus a term for whatever is currently fighting you.
     *
     * CYBERSPACE.md open question 12, resolved: tearing free mid-fight is not
     * the same act as walking out of a quiet machine, so an ICE that never
     * woke is a cheap exit — one more thing an undetected run is worth. The
     * term is on the ICE's LEVEL rather than its attack, because what makes a
     * guard hard to get away from is how good it is and not how hard it hits.
     *
     * A machine that has gone takes its grade with it and the difficulty falls
     * back to the bottom of the table: leaving a hole where a machine used to
     * be is not meant to be hard, and `runs.ice_id` is still on the run, so
     * anything that had hold of you still does.
     */
    private function egressDifficulty(Run $run): float
    {
        /** @var array<int, int> $table */
        $table = Config::get('cyberspace.egress.difficulty', []);

        $grade = System::query()->where('address', $run->address)->value('grade');
        $difficulty = (float) ($table[(int) $grade] ?? ($table[1] ?? 0));

        if ($run->ice_id === null) {
            return $difficulty;
        }

        $level = (int) (Ice::query()->whereKey($run->ice_id)->value('level') ?? 0);

        return $difficulty + ($level * (float) Config::get('cyberspace.egress.ice_term', 1));
    }

    /**
     * What being snared does to the chance.
     *
     * READ OFF THE ACTIVE ICE EVERY TIME, never stored on the run: an attack
     * tag is in force for as long as the thing is awake and alive, so breaking
     * the guard is the whole of getting free of it and there is nothing to
     * expire, clean up or disagree with. `runs.ice_id` going null takes the
     * snare with it.
     *
     * 1.0 is the ordinary answer — nothing fighting, or nothing that holds.
     */
    private function egressMultiplier(Character $character, Run $run): float
    {
        if ($run->ice_id === null) {
            return 1.0;
        }

        $ice = Ice::query()->find($run->ice_id);

        return $this->ensnareMultiplier($character, $ice?->attackLevel(AttackTag::Ensnare));
    }

    /**
     * Ensnare's bite, RESOLVED — the one place the tag's raw table value
     * (`AttackTag::multiplier()`, still pure and untouched) becomes a number
     * this character's own modifiers can move.
     *
     * The REDUCTION is the base, not the multiplier: `ensnare_reduction`
     * reads in per cent, same as the table, so a skill writes a plain
     * `percentage_of_base -10` rather than reasoning about a factor. Floored
     * at 0 by the stat itself, so the worst a build can do is blunt Ensnare
     * to nothing — never turn it into a bonus on top of leaving.
     */
    private function ensnareMultiplier(Character $character, ?int $snareLevel): float
    {
        if ($snareLevel === null) {
            return 1.0;
        }

        $reduction = (float) (AttackTag::Ensnare->levelValue($snareLevel)['reduction'] ?? 0);
        $resolved = $this->resolver->resolve('ensnare_reduction', $reduction, $character->modifiers())->value;

        return max(0.0, 1.0 - ($resolved / 100));
    }

    /**
     * The IDS's second chance, on whatever the core was touched FOR.
     *
     * Extraction and a backdoor install are the same act as far as the machine
     * is concerned — CYBERSPACE.md names burning, backdooring, injecting and
     * extracting together — so the roll lives here rather than in either. Only
     * while undetected: once something has noticed there is nothing further to
     * discover, and asking again would be asking whether they are STILL
     * noticed.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function touchTheCore(Character $character, Run $run, ?System $system): array
    {
        if ($system === null || $run->detected || ! $this->spottedBy($character, $system)) {
            return [];
        }

        return $this->noticedAtTheCore($character, $run, $system, 'core_noticed');
    }

    /**
     * THE IDS WATCHES A PULL, one roll for every turn it continues.
     *
     * Only the IDS, and only while nothing has noticed yet: the tracer and the
     * ICE had their look on arrival and at the touch, and a run already seen
     * has nothing left to discover. A system with no IDS never rolls here.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function idsWatchesThePull(Character $character, Run $run): array
    {
        if ($run->detected) {
            return [];
        }

        $system = System::query()->where('address', $run->address)->first();

        if ($system === null || (int) $system->ids_detection <= 0) {
            return [];
        }

        if ($this->checks->roll($character->stat('stealth')->value, (float) $system->ids_detection, 'detection')->passed) {
            return [];
        }

        return $this->noticedAtTheCore($character, $run, $system, 'pull_noticed');
    }

    /**
     * Seen at the core: the run is detected, its ICE wakes, its owner minds.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function noticedAtTheCore(Character $character, Run $run, System $system, string $line): array
    {
        $run->forceFill([
            'detected' => true,
            'ice_id' => $system->ice_id,
            'ice_integrity' => $system->ice?->integrity,
        ]);

        // Being seen is the cheapest thing that can cost standing: it is
        // being noticed rather than what was done.
        $this->charge($character, $system, StandingReason::Noticed);

        return [$this->line(
            'cyberspace.run.log.'.$line.($system->ice_id === null ? '' : '_ice'),
            tone: 'crit',
        )];
    }

    /**
     * AWAKE ICE STANDS BETWEEN THE RUNNER AND THE CORE.
     *
     * Asked by extraction, an install and a burn, AFTER the core touch — so the
     * attempt is what can wake it — and BEFORE the operating system is rolled,
     * because nothing reached the core to be answered. The turn is spent and
     * the ICE gets its swing. What is left is to break it or to leave.
     *
     * Said out loud, unlike the core's silent failures: the ICE is on the
     * screen already, so naming it gives nothing away.
     *
     * @return array{text: string, tone: string}
     */
    private function iceGuardsTheCore(): array
    {
        return $this->line('cyberspace.run.log.ice_guards', tone: 'warn');
    }

    /**
     * Put a way back in.
     *
     * ## It cleans up after itself, and only if it lands
     *
     * `runs.backdoored` is what `Lockdown` reads, and it is set only by an
     * install that actually happened. A backdoor run that failed — wrong
     * operating system, wrong purpose, a machine that went — cleans up
     * nothing, and the ordinary triggers shut the machine like any other run.
     *
     * ## One line for every reason it can fail
     *
     * `extract_fails`'s discipline, and the same sentence would do — but the
     * act is different enough that a player deserves to know the install did
     * not happen rather than reading about data. So it gets its own line,
     * which still covers every cause without naming one: the OS gate must not
     * announce itself here any more than it does over there.
     *
     * ## Going back in on a machine you already hold
     *
     * Costs the turn and the core roll, changes nothing, and says so. The kind
     * is permanent, so this is not a way to convert a siphon into a routing
     * hop — `Backdoors::install()` leaves an existing row alone and this reads
     * `wasRecentlyCreated` to tell the player which happened.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function install(Character $character, Run $run): array
    {
        $system = System::query()->where('address', $run->address)->first();

        $log = $this->touchTheCore($character, $run, $system);

        if ($run->inAFight()) {
            return [...$log, $this->iceGuardsTheCore()];
        }

        $kind = $run->backdoorKind();

        if ($system === null || ! $this->opensTheCore($character, $run, $system) || $kind === null) {
            $log[] = $this->line('cyberspace.run.log.install_fails');

            return $log;
        }

        /*
         * PLANTING FOR SOMEBODY ELSE COMES FIRST, and it is not an ordinary
         * install: the door belongs to the ENTITY. No `backdoors` row is
         * written for the runner, so it pays them nothing nightly, it is not a
         * routing hop, it never appears in a chain, and none of the four
         * things that take a backdoor away has anything to take. The flag on
         * the target row IS the record.
         *
         * A run installs once — `runs.backdoor_kind` is chosen at the door —
         * so a run spent planting for an entity is a run not spent planting
         * for yourself. That is the cost that makes the job a choice rather
         * than free standing. A door the runner already holds here is
         * untouched, and its approach discount made the job easier.
         */
        if (app(MissionLedger::class)->recordAgainst($character, $system, MissionAction::Plant) > 0) {
            // Still an install, so the one consequence that is not about
            // ownership holds: the system forgives the visit and does not lock
            // down, exactly as `runs.backdoored` already says. It just does
            // not owe the visitor anything afterwards.
            $run->backdoored = true;

            $log[] = $this->line('cyberspace.run.log.planted', [], 'ok');

            return $log;
        }

        $backdoor = $this->backdoors->install($character, $system, $kind);

        // ALREADY HELD is not a failure and must not read as one: the machine
        // was open before the run and is open after it.
        if (! $backdoor->wasRecentlyCreated) {
            $log[] = $this->line('cyberspace.run.log.install_already');

            return $log;
        }

        $run->backdoored = true;

        $log[] = $this->line("cyberspace.run.log.install.{$backdoor->kind->value}", tone: 'ok');

        return $log;
    }

    /**
     * Burn it out of existence.
     *
     * ## It is a core touch, like the other two
     *
     * CYBERSPACE.md names burning first in the list of things that give the
     * IDS its second look — "any action against the core, not just the
     * destructive ones" — so it takes the same shared roll extraction and an
     * install take. Being seen doing it changes nothing about whether the
     * machine burns; what it changes is whether the ICE is awake for the turn,
     * and there is no turn after this one.
     *
     * ## One line for every reason it can fail
     *
     * `install_fails`'s discipline exactly. A machine that has already gone, a
     * purpose that is not `RunPurpose::Burn`, and an operating system the
     * runner cannot speak all wear one sentence, because the OS gate must not
     * announce itself here either — the UI only offers the button on a burn
     * run, so the purpose check is a defence against a hand-built request.
     *
     * ## What it costs, and what it does not
     *
     * Heat on the character, and nothing else. No loot, because a burn takes
     * nothing; no raid counted, because the books it would be read from are
     * ash; no lockdown, because there is nothing left to shut. The real price
     * is everything the machine would ever have been worth again — see
     * `config/cyberspace.burn`.
     *
     * @return array{0: list<array{text: string, tone: string}>, 1: bool}
     */
    private function burn(Character $character, Run $run): array
    {
        $system = System::query()->where('address', $run->address)->first();

        $log = $this->touchTheCore($character, $run, $system);

        if ($run->inAFight()) {
            return [[...$log, $this->iceGuardsTheCore()], false];
        }

        if ($system === null || ! $this->opensTheCore($character, $run, $system) || $run->purpose !== RunPurpose::Burn) {
            $log[] = $this->line('cyberspace.run.log.burn_fails');

            return [$log, false];
        }

        $character->forceFill([
            // The GRADE of what burned, and more per grade than a trace.
            'heat' => (float) $character->heat + $system->grade * (float) Config::get('cyberspace.burn.heat_per_grade', 2.5),
            'bounty_grade' => max((int) $character->bounty_grade, $system->grade),
        ])->save();

        $log[] = $this->line('cyberspace.run.log.burn', ['name' => $system->displayName()], 'crit');

        // BEFORE `Burner` deletes the row, because the owner is read through
        // it. The ledger entry keeps the entity id, so a system that no
        // longer exists still cost its owner's opinion of you.
        $this->charge($character, $system, StandingReason::Burned);

        // AND BEFORE IT TOO, for the same reason and a sharper one: the target
        // row points at `systems.id`, and the cascade takes it with the
        // machine. A demolition job is satisfied by the act because the
        // evidence cannot be carried — there is nothing left to bring back.
        // Quiet: the runner finds out it counted at the desk.
        app(MissionLedger::class)->recordAgainst($character, $system, MissionAction::Burn);

        // LAST, because everybody else inside is thrown out by it and the line
        // above should read as the thing that caused that rather than a report
        // filed afterwards. The burner's own run is excluded and ends as
        // Completed in `take()`.
        (new Burner)->burn($system, $run->getKey());

        (new Achievements)->record($character, Metric::SystemsBurned);

        return [$log, true];
    }

    /**
     * Something came out of the machine.
     *
     * TWO THINGS AT ONCE, and they are one fact: the run has extracted, and
     * the machine has been raided. They are set together because a take is
     * the only thing that makes either true, and a raid counted anywhere else
     * would be a second answer to "did this run get anything".
     *
     * ONCE PER RUN. A haul that takes the creds on turn two and finishes
     * pulling a file on turn six is ONE raid — the guard is what makes that
     * true, and it is why the flag is written here rather than at either call
     * site. `systems.raids` is a count of runs that got something, not of
     * turns that did.
     */
    private function recordTake(Character $character, Run $run): void
    {
        if ($run->extracted) {
            return;
        }

        $run->extracted = true;
        $this->raids->record($run->address);

        // Once per run, for the same reason the raid counter is: an entity
        // whose system was emptied over four turns was robbed once.
        $this->charge($character, $run->system(), StandingReason::Raided);
    }

    /**
     * Tell the system's owner what was done to them.
     *
     * Built here rather than injected, the call `Burner` and `Achievements`
     * already make at these call sites: the ledger holds no dice and no
     * config a test would want to pin, so a constructor argument would be
     * ceremony.
     *
     * **Scenery notices nothing.** `StandingLedger` answers null for an
     * entity nobody can deal with, which is most of them, so every call here
     * is free for the 111 companies that are only a name on a card.
     */
    private function charge(Character $character, ?System $system, StandingReason $reason): void
    {
        if ($system === null) {
            return;
        }

        (new StandingLedger)->record($character, $system->owner, $reason);
    }

    /**
     * How fast a file moves, in QB per turn.
     *
     * THE EQUIPPED DECK'S RESOLVED BANDWIDTH — same rule `DeckTotals` fixes
     * everywhere else: modifiers belong to a character, so the deck they are
     * jacked in on reads through the resolver rather than off the row.
     *
     * FLOORED AT ONE. `deck_bandwidth` is a resolved stat and nothing today
     * can push it to zero on a deck that met the Link requirement to jack in
     * at all — but a transfer that could never finish would be a worse bug
     * than a floor nobody has reason to hit yet.
     */
    private function bandwidthFor(Character $character): int
    {
        $deck = $character->equippedDeck;

        if ($deck === null) {
            return 1;
        }

        return max(1, DeckTotals::forCharacter($character, $deck, $this->resolver)->bandwidth);
    }

    /**
     * Zero to three clauses, joined the way a sentence is rather than a list —
     * because what a haul contains is never a fixed shape and a template
     * cannot skip a placeholder that came back empty.
     */
    private function summarize(ExtractionResult $result): string
    {
        $pieces = [];

        if ($result->creds > 0) {
            $pieces[] = Text::get('cyberspace.run.log.took.creds', ['creds' => $result->creds]);
        }

        if ($result->items > 0) {
            $pieces[] = Text::plural('cyberspace.run.log.took.items', $result->items, ['count' => $result->items]);
        }

        if ($result->files > 0) {
            $pieces[] = Text::plural('cyberspace.run.log.took.files', $result->files, ['count' => $result->files]);
        }

        return match (count($pieces)) {
            0 => '',
            1 => $pieces[0],
            2 => "{$pieces[0]} and {$pieces[1]}",
            default => implode(', ', array_slice($pieces, 0, -1)).' and '.end($pieces),
        };
    }

    /**
     * The machine hits back.
     *
     * A MISS DOES NOTHING AT ALL — no attack, no damage — which is what makes a
     * low-grade guard survivable rather than merely slow, and why `skill` is its
     * own number rather than folded into `attack` as an average.
     *
     * What lands goes through `DamageResolver`, so a resistance to ICE is a
     * modifier and never a branch in here.
     *
     * @return array{0: array{text: string, tone: string}, 1: ?RunEnding}
     */
    private function iceStrikes(Character $character, Run $run): array
    {
        $ice = Ice::query()->find($run->ice_id);

        if ($ice === null) {
            return [$this->line('cyberspace.run.log.ice_silent'), null];
        }

        if (($this->roll)(1, 100) > $ice->skill) {
            // A grey tag naming the ICE, then what it tried: the same shape as its hit, quieter.
            return [$this->line('cyberspace.run.log.ice_misses', tag: $ice->name, tagTone: ''), null];
        }

        $result = (new DamageResolver($this->resolver))->apply(
            $character,
            Damage::synapticIntegrity($ice->attack, DamageOrigin::Ice),
        );

        $line = $this->line('cyberspace.run.log.ice_hits', ['damage' => $result->taken], 'crit', $ice->name);

        // THE ORDER EVERY BAD ENDING FOLLOWS: the damage has landed, so now the
        // consequence, then the ejection, then the screen. RunStarter::end()
        // and jackOut() happen in the controller's ending path, not here —
        // this says only that the run is over and why.
        if ((int) $character->fresh()->current_synaptic_integrity <= 0) {
            return [$line, RunEnding::Defeat];
        }

        return [$line, null];
    }

    /**
     * Resolve a bad ending: apply what it costs, eject, then the floor.
     *
     * THE ORDER EVERY BAD ENDING FOLLOWS: damage has already landed (that is
     * why we are here), so now the consequence, then the ejection, then —
     * only then — the waiting screen. Applying a defeat's tags after ejecting
     * would be settling a fight's outcome once the fight was already over by
     * every other measure.
     *
     * Separate from the turn on purpose. A turn says what happened; this says
     * what it cost, and keeping them apart is what stops the ordering from
     * being an accident of where a line sits.
     */
    public function resolveEnding(Character $character, Run $run, RunEnding $ending): void
    {
        $ice = $run->ice_id === null ? null : Ice::query()->find($run->ice_id);
        $system = System::query()->where('address', $run->address)->first();

        // THE CONSEQUENCE. Only a defeat has a piece of ICE whose tags fire —
        // a burnout's cost is the module it took and the SI the freeze already
        // dealt, and a cancelled run costs its own flat point. Neither reaches
        // this list.
        $effects = ($ending === RunEnding::Defeat && $ice !== null)
            ? array_column($this->defeats->apply($character, $ice), 'text')
            : [];

        /*
         * A CANCELLATION COSTS A FLAT POINT AND NOTHING ELSE.
         *
         * The machine shut or went out of existence under somebody standing in
         * it — no defeat effect, no raid counted, no trace, because the cause
         * was not theirs. `ReadinessWatch`'s reasoning transfers word for
         * word: small because they did not lose, and nonzero because being
         * dumped out of cyberspace is never free.
         *
         * Resolved like every other hit, so a resistance to having the world
         * move out from under you is a modifier and not a branch.
         */
        $cancelled = $ending === RunEnding::Cancelled
            ? (new DamageResolver($this->resolver))->apply(
                $character,
                Damage::synapticIntegrity(
                    (int) Config::get('cyberspace.readiness.damage', 1),
                    DamageOrigin::RunCancelled,
                ),
            )
            : null;

        $this->runs->end($character, $run, $ending);

        // Ejected BEFORE the screen: a character must never be on the waiting
        // screen while still holding a position in cyberspace.
        Navigator::fromConfig($this->resolver)->jackOut($character);

        /*
         * THE FLOOR IS ASKED FOR A CANCELLATION TOO, and one point can do it:
         * somebody on their last point of Synaptic Integrity whose machine
         * shuts under them goes down. `isBad()` stays false — a cancellation
         * is not a defeat and carries no tags — so the question is asked here
         * rather than folded into it.
         */
        if ($cancelled !== null) {
            $this->waitingRoom->checkFloor($character, new DownedCause(
                reason: $ending->value,
                address: $run->address,
                damage: $cancelled->taken,
                resource: 'current_synaptic_integrity',
            ));

            return;
        }

        if ($ending->isBad()) {
            $this->waitingRoom->checkFloor($character, new DownedCause(
                reason: $ending->value,
                address: $run->address,
                system: $system?->displayName(),
                ice: $ice?->name,
                resource: 'current_synaptic_integrity',
                tags: $ice === null ? [] : array_map(
                    static fn (array $entry): string => $entry['tag']->value,
                    $ice->defeatTags(),
                ),
                effects: $effects,
            ));
        }
    }

    /**
     * Arrive, and find out whether anything noticed.
     *
     * ## Everything hostile gets a look, not just the IDS
     *
     * The detection is far better at it, but a tracer and a piece of ICE each
     * carry a value of their own — which is what makes a machine with no IDS
     * quieter rather than safe, and why this rolls against every watcher
     * present rather than against the best one. **Any single success detects
     * you**: they are not competing, they are all in the room.
     *
     * ## Detection is what wakes things
     *
     * An undetected run walks past the ICE and the tracer both, which is the
     * whole of what stealth buys. So the ICE is only written onto the run when
     * something has noticed — everything downstream asks `runs.ice_id`, so a
     * quiet run simply has nothing to fight.
     *
     * ## The floor is the global one
     *
     * `checks.floors` names no kind here, deliberately. The best runner alive
     * still trips a household IDS about once in a hundred and twenty attempts
     * and the worst still slips a military one that often — and detection is
     * the case that rule was written for, so it takes the rule rather than an
     * exception to it.
     *
     * This is the IDS's FIRST chance. The second comes on touching the core,
     * and nothing touches the core yet.
     *
     * @return list<array{text: string, tone: string}>
     */
    /**
     * Everybody standing in a machine that stopped being somewhere to stand.
     *
     * Shared by the two things that can do that to a stranger: a lockdown
     * shutting the machine, and a burn taking it out of existence. It lives
     * here because what it does is resolve ENDINGS, and this class is where an
     * ending is resolved — the order (damage, consequence, ejection, floor) is
     * one order for defeats, burnouts, readiness losses and cancellations
     * alike, and a second copy of this loop would be a second place for that
     * order to drift.
     *
     * KEYED BY ADDRESS rather than by system, so it can be called after the
     * machine is gone — which `Burner` does deliberately, because cancelling
     * first would walk each ending through `Lockdown::afterRun()` on a machine
     * that is still standing.
     *
     * `$except` is a run that is ending some other way and must not be
     * cancelled under itself.
     *
     * Returns how many runs it ended.
     */
    public function cancelEveryRunOn(string $address, ?int $except = null): int
    {
        $runs = Run::query()->live()->where('address', $address)->get();
        $ended = 0;

        foreach ($runs as $run) {
            if ($except !== null && $run->getKey() === $except) {
                continue;
            }

            $character = Character::query()->find($run->character_id);

            if ($character === null) {
                continue;
            }

            $this->resolveEnding($character, $run, RunEnding::Cancelled);
            $ended++;
        }

        return $ended;
    }

    public function rouse(Character $character, Run $run, ?System $system): array
    {
        if ($system === null) {
            return [];
        }

        /*
         * A MACHINE YOU ALREADY HOLD DOES NOT LOOK UP WHEN YOU ARRIVE.
         *
         * The approach roll is skipped outright — "half the risk permanently,
         * not a bypass of the brake". It buys NOTHING at the core: extracting
         * through a backdoor is still stealing, still takes the second look,
         * still counts toward the machine's three raids and still shuts it if
         * it is caught. That asymmetry is what stops a backdoor from being a
         * key to a free machine.
         *
         * Skipped rather than discounted, because the discount is the whole of
         * what a backdoor buys on the way in and a number nobody has tuned
         * would be a second thing to balance. `install_discount` is not a
         * config value for that reason: there is nothing to turn down.
         */
        if ($this->backdoors->heldOn($character, $system) !== null) {
            return [$this->line('cyberspace.run.log.unnoticed_backdoor', tone: 'ok')];
        }

        if (! $this->spottedBy($character, $system)) {
            return [$this->line('cyberspace.run.log.unnoticed', tone: 'ok')];
        }

        $run->forceFill([
            'detected' => true,
            'ice_id' => $system->ice_id,
            'ice_integrity' => $system->ice?->integrity,
        ])->save();

        $this->charge($character, $system, StandingReason::Noticed);

        return [$this->line(
            $system->ice_id === null ? 'cyberspace.run.log.noticed' : 'cyberspace.run.log.noticed_ice',
            tone: 'crit',
        )];
    }

    /**
     * Does anything on this machine notice the character, right now?
     *
     * THE ROLL ITSELF, shared by both of the IDS's two chances — on approach
     * and on touching the core. Every watcher present gets a look and ANY
     * single success detects: an IDS, a tracer and a piece of ICE are not
     * competing against each other, they are all in the room, and a machine
     * with no IDS is quieter rather than safe.
     *
     * The global certainty floor, not a kind of its own — `checks.floors`
     * names none for `detection` on purpose, because the best runner alive
     * still trips a household IDS about once in a hundred and twenty
     * attempts, and this is the case that rule was written for.
     */
    private function spottedBy(Character $character, System $system): bool
    {
        // OFF A SHEET, NOT THE RESOLVER. `stealth` is a POOL — its base is
        // four attributes added and divided once, and that arithmetic lives in
        // `Sheet`. Asking the resolver for it with a base of 0.0 returns the
        // modifiers and nothing else, which is a bare zero for every character
        // who has not installed something: everybody was being seen by
        // everything, and the pool that shipped with the stat was never read.
        $stealth = $character->stat('stealth')->value;

        $watchers = array_filter([
            $system->ids_detection,
            $system->tracer_detection,
            $system->ice?->detection,
        ], static fn (?int $value): bool => $value !== null && $value > 0);

        foreach ($watchers as $difficulty) {
            // The character rolls to go UNNOTICED, so a failed check is a pair
            // of eyes that found them.
            if (! $this->checks->roll($stealth, (float) $difficulty, 'detection')->passed) {
                return true;
            }
        }

        return false;
    }

    /**
     * One log line. `$tag` names who did it, shown as a tag in front of the text ("ICE VII lands…") —
     * kept beside the text rather than inside it, so the screen can style it and an old log without
     * one still reads.
     *
     * @param  array<string, string|int>  $replace
     * @return array{text: string, tone: string, tag?: string}
     */
    private function line(string $key, array $replace = [], string $tone = '', ?string $tag = null, string $tagTone = 'crit', ?string $before = null): array
    {
        return ['text' => Text::get($key, $replace), 'tone' => $tone]
            + ($tag === null ? [] : ['tag' => $tag, 'tag_tone' => $tagTone])
            + ($before === null ? [] : ['before' => Text::get($before)]);
    }

    /** @param  list<array{text: string, tone: string, tag?: string, tag_tone?: string, before?: string}>  $lines */
    private function appendLog(Run $run, array $lines): void
    {
        $run->forceFill([
            'log' => array_merge($run->log ?? [], array_map(
                static fn (array $line): array => $line + ['turn' => $run->turn],
                $lines,
            )),
        ])->save();
    }
}
