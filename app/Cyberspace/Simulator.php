<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Characters\DownedCause;
use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\Decks\DeckTotals;
use App\Models\Character;
use App\Models\Ice;
use App\Models\Run;
use App\Models\System;
use App\Programs\ProgramKind;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Stats\Resolver;
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
 * Nothing announces itself. An action that touches the SYSTEM simply fails when
 * `runs.os_locked` is set, and the log line is the same one a missed attack
 * gets — because a refusal naming the operating system would hand over a scan
 * fact for free, and the player is supposed to work it out from a streak. What
 * happens TO the player is unaffected: the ICE still swings.
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
            if ($this->thresholdBreached($character, $run)) {
                $log[] = $this->line('cyberspace.run.log.threshold', tone: 'warn');
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
                    $this->egressMultiplier($run),
                )->passed) {
                    $log[] = $this->line('cyberspace.run.log.egress');

                    // COMPLETED IF THEY GOT WHAT THEY CAME FOR, egress
                    // otherwise — "they did what they came to do" is only true
                    // once extraction has actually taken something.
                    $ending = $run->extracted ? RunEnding::Completed : RunEnding::Egress;

                    $run->save();
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
            } else {
                $log[] = $this->line('cyberspace.run.log.hold');
            }

            // Broken. Nothing is left to hit back, so the machine's half of the
            // turn does not happen — which is what makes the last blow feel
            // like the last blow.
            if ($run->ice_id !== null && $run->ice_integrity <= 0) {
                $log[] = $this->line('cyberspace.run.log.ice_broken', tone: 'ok');
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

        if (! $this->traceGetsThrough($run)) {
            return [$this->line('cyberspace.run.log.trace_held')];
        }

        $was = (int) $run->trace;
        $run->trace = min(100, $was + (int) $rate);

        if ($run->trace < 100 || $was >= 100) {
            return [];
        }

        return $this->traceLands($character, $run);
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
     */
    private function thresholdBreached(Character $character, Run $run): bool
    {
        $trace = (int) $character->egress_trace_at;

        if ($trace > 0 && $run->trace >= $trace) {
            return true;
        }

        $si = (int) $character->egress_si_at;

        if ($si <= 0) {
            return false;
        }

        $max = $character->maximumFor('current_synaptic_integrity');

        return $max > 0
            && ((int) $character->current_synaptic_integrity / $max) * 100 < $si;
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
    private function traceGetsThrough(Run $run): bool
    {
        $grades = $run->routingGrades();
        $next = $grades[(int) $run->trace_hop] ?? null;

        if ($next === null) {
            return true;
        }

        /** @var array<int, int> $table */
        $table = Config::get('cyberspace.trace.advance', []);

        if (($this->roll)(1, 100) > ($table[$next] ?? 100)) {
            return false;
        }

        // Through it, and past it for good.
        $run->trace_hop = (int) $run->trace_hop + 1;

        return true;
    }

    /**
     * It reached you.
     *
     * The order the design fixes: heat on the character, then the chain burns.
     * **What heat DOES is not designed** — see TODO.md — so what is built is
     * that a completed trace costs something and that the something
     * accumulates. One point, and the number is in config to be moved.
     *
     * Burning the chain is a no-op with nothing in it: a completed trace takes
     * every backdoor on the route, and there are no backdoors. It is called
     * anyway, because the day there are is not the day this should be
     * remembered.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function traceLands(Character $character, Run $run): array
    {
        $heat = (int) Config::get('cyberspace.trace.heat', 1);

        $character->forceFill(['heat' => (int) $character->heat + $heat])->save();

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
        // Backdoors do not exist. When they do, this is where they stop.
    }

    /**
     * Cut at the ICE.
     *
     * THREE WAYS THIS DOES NOTHING, and they are deliberately indistinguishable
     * in the log: no icebreaker running, an operating system the runner cannot
     * speak, or nothing awake to cut at. A player is meant to work out which
     * from a pattern rather than be told, and the one that is a gate — the
     * operating system — must never announce itself.
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
        $running = $character->runningProcesses()
            ->contains(fn ($process): bool => $process->item?->program?->kind === ProgramKind::Icebreaker);

        if (! $running || $damage <= 0 || $run->os_locked || $run->ice_id === null) {
            return $this->line('cyberspace.run.log.attack_fails');
        }

        $run->ice_integrity = max(0, (int) $run->ice_integrity - $damage);

        return $this->line('cyberspace.run.log.attack', ['damage' => $damage], 'ok');
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
        // owed and never re-rolls detection — the core was already touched on
        // the turn that started this.
        if ($run->extracting_qb !== null) {
            return $this->continueTransfer($character, $run);
        }

        $system = System::query()->where('address', $run->address)->first();

        $log = $this->touchTheCore($character, $run, $system);

        if ($system === null || $run->os_locked || $run->purpose !== RunPurpose::Extraction) {
            $log[] = $this->line('cyberspace.run.log.extract_fails');

            return $log;
        }

        $instant = $this->extractor->takeCredsAndItems($character, $run->address);
        $fileQb = $this->extractor->totalFileQb($run->address);

        if ($instant->tookNothing() && $fileQb <= 0) {
            $log[] = $this->line('cyberspace.run.log.extract_fails');

            return $log;
        }

        if (! $instant->tookNothing()) {
            $this->recordTake($run);
            $log[] = $this->line('cyberspace.run.log.extract', ['summary' => $this->summarize($instant)], 'ok');
        }

        if ($fileQb > 0) {
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

        if ($result->tookNothing()) {
            return [$this->line('cyberspace.run.log.transfer_gone')];
        }

        $this->recordTake($run);

        return [$this->line('cyberspace.run.log.extract', ['summary' => $this->summarize($result)], 'ok')];
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
        $multiplier = $snare === null ? 1.0 : AttackTag::Ensnare->multiplier($snare);
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
    private function egressMultiplier(Run $run): float
    {
        if ($run->ice_id === null) {
            return 1.0;
        }

        $ice = Ice::query()->find($run->ice_id);
        $level = $ice?->attackLevel(AttackTag::Ensnare);

        return $level === null ? 1.0 : AttackTag::Ensnare->multiplier($level);
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

        $run->forceFill([
            'detected' => true,
            'ice_id' => $system->ice_id,
            'ice_integrity' => $system->ice?->integrity,
        ]);

        return [$this->line(
            $system->ice_id === null ? 'cyberspace.run.log.core_noticed' : 'cyberspace.run.log.core_noticed_ice',
            tone: 'crit',
        )];
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

        $kind = $run->backdoorKind();

        if ($system === null || $run->os_locked || $kind === null) {
            $log[] = $this->line('cyberspace.run.log.install_fails');

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

        $log[] = $this->line('cyberspace.run.log.install', ['kind' => $backdoor->kind->label()], 'ok');

        return $log;
    }

    /**
     * Something came out of the machine.    /**
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
    private function recordTake(Run $run): void
    {
        if ($run->extracted) {
            return;
        }

        $run->extracted = true;
        $this->raids->record($run->address);
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
            return [$this->line('cyberspace.run.log.ice_misses'), null];
        }

        $result = (new DamageResolver($this->resolver))->apply(
            $character,
            Damage::synapticIntegrity($ice->attack, DamageOrigin::Ice),
        );

        $line = $this->line('cyberspace.run.log.ice_hits', ['damage' => $result->taken], 'crit');

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
     * @param  array<string, string|int>  $replace
     * @return array{text: string, tone: string}
     */
    private function line(string $key, array $replace = [], string $tone = ''): array
    {
        return ['text' => Text::get($key, $replace), 'tone' => $tone];
    }

    /** @param  list<array{text: string, tone: string}>  $lines */
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
