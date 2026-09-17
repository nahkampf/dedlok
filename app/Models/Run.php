<?php

declare(strict_types=1);

namespace App\Models;

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
#[Fillable(['character_id', 'address', 'x', 'y', 'purpose', 'turn', 'os_locked', 'detected', 'ice_id', 'ice_integrity', 'trace', 'heat', 'started_at', 'ended_at', 'ending', 'log'])]
class Run extends Model
{
    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'turn' => 'integer',
            'os_locked' => 'boolean',
            'detected' => 'boolean',
            'ice_integrity' => 'integer',
            'trace' => 'integer',
            'heat' => 'integer',
            'purpose' => RunPurpose::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'log' => 'array',
        ];
    }

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
     */
    public function ice(): BelongsTo
    {
        return $this->belongsTo(Ice::class);
    }

    /** Is something still swinging at them? */
    public function inAFight(): bool
    {
        return $this->ice_id !== null && (int) $this->ice_integrity > 0;
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
        return new Position($this->x, $this->y);
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
}
