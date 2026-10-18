<?php

declare(strict_types=1);

namespace App\Stats;

use App\Characters\Mood;
use App\Models\Character;
use App\Models\CharacterModifier;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of an accumulating injury.
 *
 * ONE ROW PER CHARACTER PER INJURY, DEEPENED RATHER THAN REPEATED. A character
 * carrying -5 neural damage who takes another -3 carries -8: the row is
 * updated, not joined by a second. The nerves do not care what caused the
 * damage, only that there is more of it — which is what lets a deck freeze, an
 * ICE flatline and, later, a drug overdose all write into the same injury
 * without any of them knowing the others exist.
 *
 * It is also why healing is simple. A clinic reduces one number rather than
 * hunting for "whichever unexpiring negative modifiers happen to target this
 * stat", and nothing has to cancel, counter or shadow anything.
 *
 * **A row that reaches zero is deleted.** Nothing needs to know that a
 * character was hurt once and has been made whole, and a zero row is a line in
 * a breakdown saying nothing.
 *
 * These rows are deliberately UNSOURCED. Every other modifier exists because of
 * something and dies with it; an injury outlives its cause, and the cause is
 * not part of what it is.
 */
final class InjuryLedger
{
    /** Permanent damage to maximum Synaptic Integrity. */
    public const NEURAL = 'neural_damage';

    /** Permanent damage to maximum hit points. */
    public const TRAUMA = 'physical_trauma';

    /**
     * Deepen an injury, and say what the character now carries.
     *
     * $points is a POSITIVE quantity of harm; the stored amount is negative,
     * because it is added to a maximum. Callers say "three points of neural
     * damage" rather than having to remember the sign, which is the shape that
     * stops somebody one day healing a character by hurting them.
     */
    public function inflict(Character $character, string $key, float $points): float
    {
        if ($points <= 0) {
            return $this->carried($character, $key);
        }

        app(Mood::class)->event($character, 'injury', $points);

        return $this->adjust($character, $key, -abs($points));
    }

    /**
     * Heal some of it. The mirror of inflict, and the only way back.
     *
     * Healing more than is carried leaves nothing rather than overshooting
     * into a bonus — a clinic cannot make a character better than well.
     */
    public function heal(Character $character, string $key, float $points): float
    {
        if ($points <= 0) {
            return $this->carried($character, $key);
        }

        return $this->adjust($character, $key, abs($points));
    }

    /** How much of this injury the character is carrying, as a negative number. */
    public function carried(Character $character, string $key): float
    {
        return CharacterModifier::query()
            ->where('character_id', $character->getKey())
            ->ofDefinition($key)
            ->first()?->amount() ?? 0.0;
    }

    /**
     * Move the magnitude by $by, and clean up if it reaches zero.
     *
     * In a transaction with a lock, because two things hurting one character at
     * once is exactly the case this is for — a Surge and a burnout in the same
     * resolution — and read-modify-write without one loses whichever landed
     * first.
     */
    private function adjust(Character $character, string $key, float $by): float
    {
        return DB::transaction(function () use ($character, $key, $by): float {
            $row = CharacterModifier::query()
                ->where('character_id', $character->getKey())
                ->ofDefinition($key)
                ->lockForUpdate()
                ->first();

            $now = ($row?->amount() ?? 0.0) + $by;

            // Healing cannot overshoot into a bonus.
            if ($now >= 0.0) {
                $row?->delete();

                return 0.0;
            }

            if ($row === null) {
                CharacterModifier::query()->create([
                    'character_id' => $character->getKey(),
                    'definition_key' => $key,
                    'data' => ['amount' => $now],
                ]);

                return $now;
            }

            $row->update(['data' => ['amount' => $now] + ($row->data ?? [])]);

            return $now;
        });
    }
}
