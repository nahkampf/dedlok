<?php

declare(strict_types=1);

namespace App\Cyberspace\Party;

use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Missions\MissionAction;
use App\Missions\MissionLedger;
use App\Models\Character;
use App\Models\Run;
use App\Models\RunParty;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Programs\ProgramKind;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The round of a party run: everybody chooses, and it resolves when the last has or thirty seconds pass.
 *
 * Each runner keeps their own run row (own trace, own loadout), so the shared things are synced between them: detection
 * is party-wide (any runner noticed is all noticed) and the ICE is one piece of ICE with one integrity. The ICE gets ONE
 * strike a round, at the runner with the highest priority: damage dealt to it plus how little stealth they have.
 *
 * The clock is whoever's request arrives first, the way the rest of the run is lazy: nothing is scheduled, and a party
 * who all walked away stays frozen under the lock.
 */
final class PartyTurns
{
    public const DEADLINE_SECONDS = 30;

    /** A runner who never opens the screen cannot hold the first round for longer than this. */
    public const FIRST_ROUND_GRACE_SECONDS = 300;

    public function __construct(private readonly Simulator $simulator) {}

    /** The live runs of a party, owner first. @return Collection<int, Run> */
    public static function liveRuns(int $partyId): Collection
    {
        $owner = RunParty::query()->whereKey($partyId)->value('owner_character_id');

        return Run::query()->where('run_party_id', $partyId)->whereNull('ended_at')->orderBy('id')->get()
            ->sortBy(fn (Run $r): int => $r->character_id === $owner ? 0 : 1)->values();
    }

    /** Is this run one of a party of more than one still in it? */
    public static function shared(Run $run): bool
    {
        return $run->run_party_id !== null && self::liveRuns($run->run_party_id)->count() > 1;
    }

    /** Record a runner's choice, then resolve the round if it is complete or overdue. */
    public function submit(Run $run, RunAction $action): void
    {
        $run->forceFill(['pending_action' => $action->value])->save();

        $this->settle((int) $run->run_party_id);
    }

    /** Resolve the round if everybody has chosen or the deadline has passed; otherwise do nothing. */
    public function settle(int $partyId): void
    {
        DB::transaction(function () use ($partyId): void {
            $party = RunParty::query()->whereKey($partyId)->lockForUpdate()->first();

            if ($party === null) {
                return;
            }

            $runs = self::liveRuns($partyId);

            if ($runs->isEmpty()) {
                return;
            }

            $due = $this->due($party, $runs);
            $ready = $runs->every(fn (Run $r): bool => $r->pending_action !== null);

            if (! $ready && ! $due) {
                return;
            }

            $this->resolve($party, $runs);
        });
    }

    /**
     * The first round's clock starts when the last runner has opened the screen (so nobody loses turn 0 unseen), unless
     * somebody never does; every later round runs from the last one.
     *
     * @param  Collection<int, Run>  $runs
     */
    private function due(RunParty $party, Collection $runs): bool
    {
        if ($party->round_started_at !== null) {
            return now()->diffInSeconds($party->round_started_at, false) <= -self::DEADLINE_SECONDS;
        }

        $started = $party->started_at ?? now();

        if (now()->diffInSeconds($started, false) <= -self::FIRST_ROUND_GRACE_SECONDS) {
            return true;
        }

        if ($runs->contains(fn (Run $r): bool => $r->party_seen_at === null)) {
            return false;
        }

        return now()->diffInSeconds($runs->max('party_seen_at'), false) <= -self::DEADLINE_SECONDS;
    }

    /** @param  Collection<int, Run>  $runs */
    private function resolve(RunParty $party, Collection $runs): void
    {
        $this->syncShared($runs);

        foreach ($runs as $run) {
            $run->refresh();
            $character = Character::query()->find($run->character_id);

            if ($character === null || ! $run->isLive()) {
                continue;
            }

            $action = $run->pending_action !== null
                ? RunAction::from($run->pending_action)
                : $this->defaultFor($character, $run);

            $run->forceFill(['pending_action' => null])->save();

            $result = $this->simulator->take($character, $run, $action, iceStrikes: false);

            $this->afterTurn($character, $run, $result->ended() ? $result->ending : null);
            $this->syncShared(self::liveRuns($party->getKey()), $run);
        }

        // A backdoor that has landed is the job done: nothing is left to do inside, so everybody finishes together.
        $live = self::liveRuns($party->getKey());

        if ($live->contains(fn (Run $r): bool => (bool) $r->backdoored)) {
            foreach ($live as $run) {
                $character = Character::query()->find($run->character_id);

                if ($character !== null) {
                    app(RunStarter::class)->end($character, $run->refresh(), RunEnding::Completed);
                    (new Notifier)->send($character, Category::Cyberspace, 'party_run_ended', ['ending' => RunEnding::Completed->message()]);
                }
            }

            return;
        }

        // A Denial round: the node pushes back once, and a full meter ends everybody's run together.
        if ($this->denialRound($party)) {
            $party->update(['round_started_at' => now()]);

            return;
        }

        $this->strike($party);

        $party->update(['round_started_at' => now()]);
    }

    /**
     * The round's end for a Denial party: the node's push-back (once, however many are flooding), then, if the shared
     * meter is full, every live run completes and records its own job. Returns whether the node fell.
     */
    private function denialRound(RunParty $party): bool
    {
        $live = self::liveRuns($party->getKey())->filter(fn (Run $r): bool => $r->purpose === RunPurpose::Denial);

        if ($live->isEmpty()) {
            return false;
        }

        $target = Simulator::denialTarget($live->first());

        if ((float) $live->max('pressure') < $target) {
            $pressure = max(0.0, round((float) $live->max('pressure') - $this->simulator->rollDenialDrain($live->first()), 1));
            $live->each(fn (Run $r) => $r->forceFill(['pressure' => $pressure])->save());

            // Out of time: the node shakes the whole party off.
            if ((int) $live->max('turn') >= (int) config('cyberspace.denial.turns', 12)) {
                foreach ($live as $run) {
                    $character = Character::query()->find($run->character_id);

                    if ($character !== null) {
                        app(RunStarter::class)->end($character, $run->refresh(), RunEnding::Egress);
                        (new Notifier)->send($character, Category::Cyberspace, 'party_run_ended', ['ending' => RunEnding::Egress->message()]);
                    }
                }

                return true;
            }

            return false;
        }

        foreach ($live as $run) {
            $character = Character::query()->find($run->character_id);
            $system = $run->system();

            if ($character === null) {
                continue;
            }

            if ($system !== null) {
                app(MissionLedger::class)->recordAgainst($character, $system, MissionAction::Denial);
            }

            app(RunStarter::class)->end($character, $run->refresh(), RunEnding::Completed);
            (new Notifier)->send($character, Category::Cyberspace, 'party_run_ended', ['ending' => RunEnding::Completed->message()]);
        }

        return true;
    }

    /** The ICE's one strike of the round. */
    private function strike(RunParty $party): void
    {
        $live = self::liveRuns($party->getKey());
        $struck = $live->filter(fn (Run $r): bool => $r->ice_id !== null);

        if ($struck->isEmpty()) {
            return;
        }

        $target = $this->target($live);
        $character = Character::query()->find($target->character_id);

        if ($character === null) {
            return;
        }

        $ending = $this->simulator->strikeIce($character, $target->refresh());
        $this->afterTurn($character, $target, $ending);
    }

    /**
     * The runner with the highest priority: their share of the damage dealt to the ICE, plus how much less stealthy
     * they are than the rest (0 for the stealthiest, 1 for the least). Equal scores (including nobody having done any
     * damage and everybody equally stealthy) are chosen between at random.
     *
     * @param  Collection<int, Run>  $live
     */
    private function target(Collection $live): Run
    {
        $stealth = $live->mapWithKeys(fn (Run $r): array => [
            $r->id => (float) Character::query()->find($r->character_id)?->stat('stealth')->value,
        ]);
        $total = max(1.0, (float) $live->sum('ice_damage'));
        $range = $stealth->max() - $stealth->min();

        $score = $live->mapWithKeys(fn (Run $r): array => [
            $r->id => round(
                (float) $r->ice_damage / $total + ($range > 0 ? ($stealth->max() - $stealth[$r->id]) / $range : 0.0),
                6,
            ),
        ]);

        return $live->filter(fn (Run $r): bool => $score[$r->id] === $score->max())->random();
    }

    private function afterTurn(Character $character, Run $run, ?RunEnding $ending): void
    {
        if ($ending === null) {
            return;
        }

        if ($ending->isBad()) {
            $this->simulator->resolveEnding($character, $run, $ending);
        }

        (new Notifier)->send($character, Category::Cyberspace, 'party_run_ended', ['ending' => $ending->message()]);
    }

    /** What an absent runner does: their own purpose, carried on, and out once it is done. */
    private function defaultFor(Character $character, Run $run): RunAction
    {
        if ($run->purpose === RunPurpose::Denial) {
            return RunAction::Flood;
        }

        if ($run->ice_id !== null) {
            $breaker = $character->runningProcesses()
                ->first(fn ($p): bool => $p->item?->program?->kind === ProgramKind::Icebreaker);

            return $breaker !== null ? RunAction::Attack : RunAction::Hold;
        }

        // Part way through a download, it carries on; nothing left to take or install, an idle runner gets out.
        if ($run->extracting_qb !== null) {
            return RunAction::Extract;
        }

        if ($run->extracted || $run->backdoored) {
            return RunAction::Egress;
        }

        return match ($run->purpose) {
            RunPurpose::Extraction => RunAction::Extract,
            RunPurpose::Backdoor => RunAction::Install,
            RunPurpose::Burn => RunAction::Burn,
            default => RunAction::Hold,
        };
    }

    /**
     * Detection is party-wide and the ICE is one: carry the sharpest state to every live runner.
     *
     * @param  Collection<int, Run>  $runs
     */
    private function syncShared(Collection $runs, ?Run $from = null): void
    {
        $runs = $runs->map(fn (Run $r): Run => $r->fresh() ?? $r)->filter(fn (Run $r): bool => $r->isLive());

        if ($runs->isEmpty()) {
            return;
        }

        $detected = $runs->contains(fn (Run $r): bool => (bool) $r->detected);
        $ice = $runs->filter(fn (Run $r): bool => $r->ice_id !== null)->sortBy('ice_integrity')->first();
        // A Denial meter is the node's, one for the party: the run that just acted carries it, else the highest.
        $pressure = $from !== null && $from->purpose === RunPurpose::Denial ? (float) $from->fresh()?->pressure : (float) $runs->max('pressure');

        // A broken one (the source cleared it) wins over a stale copy still holding it.
        $cleared = $from !== null && $from->ice_id === null && $runs->contains(fn (Run $r): bool => $r->ice_id !== null) && $from->fresh()?->ice_id === null;

        foreach ($runs as $run) {
            $run->forceFill([
                'detected' => $detected,
                'pressure' => $pressure,
                'ice_id' => $cleared ? null : $ice?->ice_id,
                'ice_integrity' => $cleared ? null : $ice?->ice_integrity,
                'ice_attack' => $cleared ? null : $ice?->ice_attack,
            ])->save();
        }
    }
}
