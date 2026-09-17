<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Characters\DownedCause;
use App\Characters\WaitingRoom;
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
        private Closure $roll,
    ) {}

    public static function fromConfig(?Closure $roll = null, ?Resolver $resolver = null): self
    {
        $resolver ??= app(Resolver::class);

        return new self(
            $resolver,
            new RunStarter($resolver),
            new WaitingRoom($resolver),
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
     * Wake whatever guards this machine.
     *
     * DETECTION IS NOT BUILT, so this is certain — see the note on the caller.
     * When it is, this is the one place that changes: everything downstream
     * already asks `runs.ice_id` rather than the machine.
     */
    public function rouse(Run $run, ?System $system): void
    {
        if ($system?->ice_id === null) {
            return;
        }

        $run->forceFill([
            'detected' => true,
            'ice_id' => $system->ice_id,
            'ice_integrity' => $system->ice?->integrity,
        ])->save();
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
