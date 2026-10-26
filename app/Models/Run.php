<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PointsCast;
use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Position;
use App\Cyberspace\RunPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An intrusion run.
 *
 * DURABLE, BECAUSE IT IS INTERACTIVE. A run used to be resolved end to end
 * inside one request and needed no row; making it a thing a player plays out
 * turn by turn made it a state machine that has to survive a disconnect, a
 * crash and a deploy. This is that state.
 *
 * NO FOREIGN KEY TO `systems`, on purpose and for the third time in this
 * codebase: a run has to outlive the machine it is against, because the machine
 * being burned or relocated out from under it is precisely the case that
 * cancels one. `system()` may return null and that is an ordinary answer.
 *
 * Written by App\Cyberspace\RunStarter and nothing else.
 */
#[Fillable(['character_id', 'run_party_id', 'turn_at', 'ice_attack', 'pull_at', 'pull_content_id', 'pending_action', 'ice_damage', 'address', 'grid_id', 'x', 'y', 'purpose', 'infiltrated', 'pressure', 'extracted', 'backdoored', 'backdoor_kind', 'turn', 'os_locked', 'detected', 'ice_id', 'ice_integrity', 'extracting_qb', 'trace', 'trace_hop', 'chain', 'thermal_load', 'started_at', 'ended_at', 'ending', 'log'])]
class Run extends Model
{
    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'turn' => 'integer',
            'os_locked' => 'boolean',
            'extracted' => 'boolean',
            'backdoored' => 'boolean',
            'backdoor_kind' => BackdoorKind::class,
            'detected' => 'boolean',
            'ice_integrity' => PointsCast::class,
            'ice_attack' => PointsCast::class,
            'ice_damage' => PointsCast::class,
            'extracting_qb' => 'float',
            'pressure' => 'float',
            'trace' => 'integer',
            'trace_hop' => 'integer',
            'chain' => 'array',
            'thermal_load' => 'integer',
            'purpose' => RunPurpose::class,
            'started_at' => 'immutable_datetime',
            'pull_at' => 'immutable_datetime',
            'turn_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'log' => 'array',
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /**
     * What is fighting, if anything woke up.
     *
     * A key here where `address` is deliberately not one, and the difference is
     * what each points at: an address names a machine that may stop existing,
     * and the ICE catalogue is authored content that gets edited rather than
     * deleted.
     *
     * @return BelongsTo<Ice, $this>
     */
    public function ice(): BelongsTo
    {
        return $this->belongsTo(Ice::class);
    }

    /** Is something still swinging at them? */
    public function inAFight(): bool
    {
        return $this->ice_id !== null && (float) $this->ice_integrity > 0;
    }

    /** How fast the trace is walking, per turn. Zero when nothing is following. */
    public function traceRate(): int
    {
        if (! $this->detected) {
            return 0;
        }

        return (int) (System::query()->where('address', $this->address)->value('tracer_rate') ?? 0);
    }

    /**
     * Turns before it reaches you, or null when nothing is coming.
     *
     * THE NUMBER THE EGRESS DECISION IS MADE ON, which is why it is worked out
     * here rather than left for a player to divide in their head while
     * something is hitting them. Null means either nothing has noticed or this
     * machine has no tracer — both of which are the same good news.
     */
    public function turnsUntilTraced(): ?int
    {
        $rate = $this->traceRate();

        if ($rate <= 0 || $this->trace >= 100) {
            return null;
        }

        return (int) ceil((100 - $this->trace) / $rate);
    }

    /**
     * The grade of every backdoored system the trace has to come through.
     *
     * The route this run declared when it began, as grades — one slot per hop,
     * in the order a trace meets them. A trace attempts the next one each turn
     * and only advances if it gets through, so a long chain of deep machines is
     * turns of nothing happening.
     *
     * An empty list is no routing at all, which is the ordinary state for
     * anybody who holds no routing backdoors or has trained neither Routing
     * skill: the trace comes on unopposed from the first turn.
     *
     * @return list<?int> One slot per stored hop; null where it has gone.
     */
    public function routingGrades(): array
    {
        $chain = (array) ($this->chain ?? []);

        if ($chain === []) {
            return [];
        }

        /*
         * LOSING ONE HOP IS NOT LOSING THE CHAIN. A completed trace is the only
         * thing that takes the whole route; a hop lost any other way — somebody
         * else's lockdown, a burn, the nightly ageing roll — simply is not
         * there any more, and the rest of the chain stands.
         *
         * The stored list is NEVER rewritten and this returns a slot for every
         * entry in it, null where the machine has gone or the backdoor with
         * it. That is what keeps `runs.trace_hop` honest: it indexes this
         * list, so a hop going dark must not shift the ones behind it. A null
         * is walked straight past — one less thing in the way — and a hop
         * already passed stays passed because the index only ever moves
         * forward.
         *
         * Read fresh every turn rather than cached on the run, because the
         * whole point is that it can change underneath one.
         */
        $live = System::query()
            ->whereIn('id', $chain)
            ->whereIn('id', Backdoor::query()
                ->where('character_id', $this->character_id)
                ->where('kind', BackdoorKind::Routing->value)
                ->select('system_id'))
            ->pluck('grade', 'id');

        return array_map(
            static fn (mixed $id): ?int => ($grade = $live->get((int) $id)) === null ? null : (int) $grade,
            array_values($chain),
        );
    }

    /** How much of the route is still standing, for a screen that says so. */
    public function liveHops(): int
    {
        return count(array_filter($this->routingGrades(), static fn (?int $grade): bool => $grade !== null));
    }

    /** The chain the trace has to walk: the machine, the hops, and you. */
    public function traceChain(): array
    {
        // ROUTING IS NOT BUILT, so the chain is the shortest one there is —
        // the machine you are in, and you. Every backdoored relay a player
        // routes through will land between these two, and the widget already
        // draws a chain rather than a pair so that it does not have to change.
        return [
            ['label' => $this->address, 'kind' => 'target'],
            ['label' => null, 'kind' => 'you'],
        ];
    }

    /**
     * The machine, if it is still there.
     *
     * NULL IS AN ORDINARY ANSWER — it means the target was burned or has
     * relocated and is wearing a new address, which is what cancels a run
     * rather than what breaks one.
     */
    public function system(): ?System
    {
        return System::query()->where('address', $this->address)->first();
    }

    public function position(): Position
    {
        return new Position($this->x, $this->y, (int) ($this->grid_id ?? 1));
    }

    /** Is this run still being played? */
    public function isLive(): bool
    {
        return $this->ended_at === null;
    }

    /** @param  Builder<self>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    /**
     * What this run came to install, or null where that is not the question.
     *
     * NULL IS A REFUSAL AND NOT A DEFAULT. A backdoor run with no kind on it
     * is a hand-built request rather than something a player can reach, and
     * picking one for them would be picking the permanent property of a
     * machine on their behalf.
     */
    public function backdoorKind(): ?BackdoorKind
    {
        return $this->purpose === RunPurpose::Backdoor ? $this->backdoor_kind : null;
    }
}
