<?php

declare(strict_types=1);

namespace App\Characters;

use App\Models\Character;
use App\Stats\Resolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The floor under every defeat.
 *
 * Reaching 0 Synaptic Integrity or 0 hit points has had no consequence at all
 * until now. This is it, and it is deliberately the SMALLEST possible one: a
 * minute, and then you are up with a single point back. What losing actually
 * costs is whatever the defeat tags carried — a piece of a ceiling, a destroyed
 * module, a wiped deck — and the wait is only here so that a defeat carrying
 * nothing still costs something.
 *
 * ## Zero to one, and no further
 *
 * Getting up is not healing: it is getting up. A character comes back on **one** point of whatever put them
 * down, which is enough to act and not enough to be safe — and the way back
 * from there is the two-hourly mend, Neuroplasticity, or something a player does.
 *
 * Both resources are checked, and both are brought to one if both were at
 * zero: waking up still broken in the other half would put somebody straight
 * back on the floor on their next request.
 *
 * ## Released lazily, like everything else here
 *
 * No scheduler. `release()` is asked on the way past by the middleware that
 * holds somebody here, so the wait ends the moment they look. A player who
 * never comes back never needs to be got up.
 */
final readonly class WaitingRoom
{
    public function __construct(private Resolver $resolver) {}

    /** Is this character on the floor right now? */
    public function isDown(Character $character): bool
    {
        return $character->waiting_until !== null && $character->waiting_until->isFuture();
    }

    /**
     * Put somebody down.
     *
     * Idempotent in the way that matters: somebody already down is left where
     * they are rather than having their clock restarted, because a second hit
     * on an unconscious character is not a second wait.
     */
    public function put(Character $character, DownedCause $cause): void
    {
        if ($this->isDown($character)) {
            return;
        }

        $seconds = $this->resolver->resolve(
            'recovery_seconds',
            (float) Config::get('cyberspace.recovery_seconds', 60),
            $character->modifiers(),
        )->toInt();

        $character->forceFill([
            'waiting_until' => CarbonImmutable::now()->addSeconds(max(0, $seconds)),
            'waiting_cause' => $cause->toArray(),
        ])->save();
    }

    /**
     * Get them up, if the minute has passed.
     *
     * Returns true when somebody was actually got up, so a caller can say so.
     * Asked on every request from a downed character, which is what makes this
     * need no scheduler at all.
     */
    public function release(Character $character): bool
    {
        if ($character->waiting_until === null || $character->waiting_until->isFuture()) {
            return false;
        }

        DB::transaction(function () use ($character): void {
            // ONE POINT, on whichever was empty. Getting up is not healing, and
            // waking still broken in the other half would put somebody back on
            // the floor on their next request.
            foreach (array_keys(Character::RESOURCES) as $resource) {
                if ($character->getAttribute($resource) <= 0) {
                    $character->recover($resource, 1);
                }
            }

            $character->forceFill(['waiting_until' => null, 'waiting_cause' => null])->save();
        });

        return true;
    }

    /**
     * Put somebody down if this damage emptied them.
     *
     * The one door every cause should use, so that "did that finish them" is
     * asked in one place rather than by each thing that can hurt somebody.
     */
    public function checkFloor(Character $character, DownedCause $cause): bool
    {
        foreach (array_keys(Character::RESOURCES) as $resource) {
            if ($character->getAttribute($resource) <= 0) {
                $this->put($character, $cause);

                return true;
            }
        }

        return false;
    }
}
