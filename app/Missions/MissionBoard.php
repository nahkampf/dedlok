<?php

declare(strict_types=1);

namespace App\Missions;

use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\Exchange;
use App\Models\Mission;
use App\Models\System;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What an agent is offering, rolled for whoever is standing at the desk.
 *
 * ## Per character, never shared
 *
 * A board is not a thing the world holds and players race for. It is rolled
 * for one character and only they can see it, so nobody can take your job and
 * two people at one desk are looking at different work.
 *
 * ## Refreshed lazily, on the hour
 *
 * `Probe::settle()`'s shape rather than a sweep: the board is rebuilt when the
 * screen is opened and what is there is older than the window. A scheduled
 * refresher would be rolling boards for every character at every desk they
 * have ever visited, nearly all of which nobody will look at.
 *
 * ## Declining clears the job until the next refresh
 *
 * Which is what makes the hour a real decision. Turning down a burn costs you
 * that desk's offer of it until the clock comes round, and a board somebody
 * has picked over is a board with less on it. THE FLOOR HOLDS EVEN THEN — the
 * board is never empty, so somebody who declines everything still finds one
 * job waiting, and it is not the one they threw back.
 *
 * ## The die is injected
 *
 * Every roll here goes through it, so a test can pin a board exactly the way
 * `Probe` and `Simulator` are pinned.
 */
final readonly class MissionBoard
{
    /** @param  \Closure(int, int): int  $roll */
    public function __construct(private \Closure $roll) {}

    /** The ordinary construction: `random_int`, and config for everything else. */
    public static function fromConfig(?\Closure $roll = null): self
    {
        return new self($roll ?? static fn (int $min, int $max): int => random_int($min, $max));
    }

    /**
     * What this desk has for this character, rolling a new board if the old
     * one has gone stale.
     *
     * Accepted work is NOT part of a board and is never cleared by a refresh —
     * a job somebody took is theirs until it ends, whatever the desk offers
     * afterwards.
     *
     * @return Collection<int, Mission>
     */
    public function forCharacter(Character $character, Exchange $desk): Collection
    {
        if ($this->isStale($character, $desk)) {
            $this->refresh($character, $desk);
        }

        return Mission::query()
            ->where('character_id', $character->getKey())
            ->where('exchange_id', $desk->getKey())
            ->offered()
            ->with('targets.system', 'entity')
            ->orderBy('id')
            ->get();
    }

    /**
     * Has the hour come round?
     *
     * An empty board is stale by definition, which is what makes the floor
     * work: somebody who declined everything gets a fresh roll on the next
     * visit rather than an empty desk until the hour is up.
     */
    private function isStale(Character $character, Exchange $desk): bool
    {
        $newest = Mission::query()
            ->where('character_id', $character->getKey())
            ->where('exchange_id', $desk->getKey())
            ->offered()
            ->max('offered_at');

        if ($newest === null) {
            return true;
        }

        $minutes = (int) config('missions.board.refresh_minutes', 60);

        return now()->diffInMinutes($newest, absolute: true) >= $minutes;
    }

    /**
     * Throw the old board away and roll a new one.
     *
     * Deleting rather than marking: an offered row nobody took is not history,
     * it is a thing that was never true of the world. Only ACCEPTED work has a
     * story worth keeping.
     */
    private function refresh(Character $character, Exchange $desk): void
    {
        $entity = $desk->entity();

        if ($entity === null) {
            return;
        }

        DB::transaction(function () use ($character, $desk, $entity): void {
            Mission::query()
                ->where('character_id', $character->getKey())
                ->where('exchange_id', $desk->getKey())
                ->offered()
                ->delete();

            $size = ($this->roll)(
                (int) config('missions.board.size.min', 2),
                (int) config('missions.board.size.max', 4),
            );

            $size = max((int) config('missions.board.floor', 1), $size);

            for ($i = 0; $i < $size; $i++) {
                $this->offer($character, $desk, $entity->getKey());
            }
        });
    }

    /**
     * Roll one job.
     *
     * An action that cannot find enough targets is not an error and not worth
     * retrying: the world is a grid with holes in it, and a desk whose band is
     * thin simply offers less. The floor is about the board as a whole, and a
     * supply job always has something to ask for.
     */
    private function offer(Character $character, Exchange $desk, int $entityId): ?Mission
    {
        $action = $this->pickAction();
        $grade = max(1, min(5, (int) $desk->grade));
        $rewards = $this->rewards($action, $grade);

        $quantity = 0;
        $targets = collect();

        if ($action === MissionAction::Supply) {
            [$low, $high] = config("missions.supply.{$grade}", [4, 8]);
            $quantity = ($this->roll)((int) $low, (int) $high);
        } else {
            $targets = $this->drawTargets($action, $desk, $entityId);

            if ($targets->isEmpty()) {
                return null;
            }
        }

        $mission = Mission::query()->create([
            'character_id' => $character->getKey(),
            'entity_id' => $entityId,
            'exchange_id' => $desk->getKey(),
            'action' => $action,
            'state' => MissionState::Offered,
            'quantity_required' => $quantity,
            'creds_reward' => $rewards['creds'],
            'standing_reward' => $rewards['standing'],
            'loyalty_reward' => $rewards['loyalty'],
            'penalty' => $rewards['penalty'],
            'offered_at' => now(),
        ]);

        foreach ($targets as $system) {
            $mission->targets()->create([
                'system_id' => $system->getKey(),
                'address' => $system->address,
            ]);
        }

        return $mission;
    }

    private function pickAction(): MissionAction
    {
        $cases = MissionAction::cases();

        return $cases[($this->roll)(0, count($cases) - 1)];
    }

    /**
     * Systems the desk may name.
     *
     * DRAWN FROM ANYWHERE BUT THE GIVER, within the grade band. Some belong to
     * flavour entities and cost nothing; some belong to interactable ones and
     * cost standing through hooks that already exist. So a job is a choice
     * about who to annoy, and nothing had to be written to make that true.
     *
     * An Exchange is never a target — `SystemType::isInteractable()` already
     * says nothing can be done to one, so the filter is on the type rather
     * than on the desk's own id.
     *
     * @return Collection<int, System>
     */
    private function drawTargets(MissionAction $action, Exchange $desk, int $entityId): Collection
    {
        [$low, $high] = $desk->targetGrades();
        [$least, $most] = $action->targets();

        $wanted = ($this->roll)($least, $most);

        return System::query()
            ->whereBetween('grade', [$low, $high])
            ->whereIn('type', self::targetableTypes())
            ->where(fn ($query) => $query->whereNull('owner_id')->orWhere('owner_id', '!=', $entityId))
            // There has to be an ICE to wake.
            ->when($action === MissionAction::Agitate, fn ($query) => $query->whereNotNull('ice_id'))
            // A fortress is a player's own node: never a job's.
            ->where('type', '!=', 'fortress')
            ->when($action === MissionAction::Transport, function ($query) use ($desk): void {
                $at = $desk->system;
                [$near, $far] = (array) config('missions.transport_steps.'.max(1, min(5, (int) $desk->grade)), [10, 15]);

                if ($at === null) {
                    return;
                }

                $dx = 'ABS(CAST(x AS SIGNED) - ?)';
                $dy = 'ABS(CAST(y AS SIGNED) - ?)';

                // Chebyshev distance from the desk between near and far, written so it runs on SQLite as well as MySQL.
                $query->whereRaw("{$dx} <= ? AND {$dy} <= ? AND ({$dx} >= ? OR {$dy} >= ?)", [
                    $at->x, $far, $at->y, $far, $at->x, $near, $at->y, $near,
                ]);
            })
            ->inRandomOrder()
            ->limit($wanted)
            ->get();
    }

    /**
     * The types a job may name.
     *
     * `isInteractable()` is already the question "may a run be started against
     * this" — no for the portal, no for a desk — and every action on the board
     * is either a run or a scan of something a run could be made against. So
     * the list is derived rather than written down a second time.
     *
     * @return list<string>
     */
    public static function targetableTypes(): array
    {
        return array_values(array_map(
            static fn (SystemType $type): string => $type->value,
            array_filter(SystemType::cases(), static fn (SystemType $type): bool => $type->isInteractable()),
        ));
    }

    /**
     * What this job pays, read once and frozen onto the row.
     *
     * @return array{creds: int, standing: float, loyalty: int, penalty: float}
     */
    private function rewards(MissionAction $action, int $grade): array
    {
        $band = config("missions.grades.{$grade}");
        $scale = config("missions.actions.{$action->value}");
        // A multiplier is one figure, or a table by grade.
        $scale['creds'] = is_array($scale['creds']) ? $scale['creds'][$grade] : $scale['creds'];

        return [
            'creds' => (int) round(($this->roll)((int) $band['creds'][0], (int) $band['creds'][1]) * $scale['creds']),
            // Rolled in thousandths, because the column holds three decimals
            // and the grade 1 band is hundredths wide.
            'standing' => round(
                ($this->roll)((int) round($band['standing'][0] * 1000), (int) round($band['standing'][1] * 1000))
                / 1000 * $scale['standing'],
                3,
            ),
            'loyalty' => (int) round(($this->roll)((int) $band['loyalty'][0], (int) $band['loyalty'][1]) * $scale['loyalty']),
            'penalty' => round((float) $band['penalty'], 3),
        ];
    }
}
