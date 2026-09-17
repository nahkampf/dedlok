<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Characters\DownedCause;
use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
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
        private Closure $roll,
    ) {}

    public static function fromConfig(
        ?Closure $roll = null,
        ?Resolver $resolver = null,
        ?CheckRoller $checks = null,
    ): self {
        $resolver ??= app(Resolver::class);

        return new self(
            $resolver,
            new RunStarter($resolver),
            new WaitingRoom($resolver),
            $checks ?? app(CheckRoller::class),
            $roll ?? static fn (int $min, int $max): int => random_int($min, $max),
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
                $log[] = $this->line('cyberspace.run.log.egress');

                $run->save();
                $this->runs->end($character, $run, RunEnding::Egress);

                return TurnResult::resolved($run, $log, RunEnding::Egress);
            }

            if ($action === RunAction::Attack) {
                $log[] = $this->attack($character, $run);
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
     * Resolve a bad ending: eject, then the floor.
     *
     * Separate from the turn on purpose. A turn says what happened; this says
     * what it cost, and keeping them apart is what stops the ordering from
     * being an accident of where a line sits.
     */
    public function resolveEnding(Character $character, Run $run, RunEnding $ending): void
    {
        $ice = $run->ice_id === null ? null : Ice::query()->find($run->ice_id);
        $system = System::query()->where('address', $run->address)->first();

        $this->runs->end($character, $run, $ending);

        // Ejected BEFORE the screen: a character must never be on the waiting
        // screen while still holding a position in cyberspace.
        Navigator::fromConfig($this->resolver)->jackOut($character);

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

        $stealth = $this->resolver
            ->resolve('stealth', 0.0, $character->modifiers())
            ->value;

        $watchers = array_filter([
            $system->ids_detection,
            $system->tracer_detection,
            $system->ice?->detection,
        ], static fn (?int $value): bool => $value !== null && $value > 0);

        $noticed = false;

        foreach ($watchers as $difficulty) {
            // The character rolls to go UNNOTICED, so a failed check is a pair
            // of eyes that found them.
            if (! $this->checks->roll($stealth, (float) $difficulty, 'detection')->passed) {
                $noticed = true;
            }
        }

        if (! $noticed) {
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
