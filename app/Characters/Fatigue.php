<?php

declare(strict_types=1);

namespace App\Characters;

use App\Models\Character;
use App\Models\CharacterModifier;
use App\Stats\Sheet;
use App\Support\Points;

/**
 * The only writer of `characters.current_fatigue`.
 *
 * Fatigue runs the other way from the two older resources: nothing at rest, a maximum that is a collapse. It is
 * deliberately NOT in `Character::RESOURCES` — that list means "full is good" to the damage vocabulary, the waiting room
 * and the top bar, and none of them should treat this as a third pool of hit points.
 *
 * Both calls clamp into [0, fatigue_max] and carry two decimals through `Points::of()`. The caller owns the transaction
 * and the lock, as for `Wallet::move()`. What a collapse or a breakpoint DOES is not decided here.
 */
final class Fatigue
{
    /** The three `config/effects.php` definitions this writes; `config/fatigue.php` `effects` names the same keys. */
    public const TIRED = 'fatigue_tired';

    public const DRAINED = 'fatigue_drained';

    public const EXHAUSTED = 'fatigue_exhausted';

    /** Exhausted ends when fatigue falls under this share of the maximum. */
    private const EXHAUSTED_UNTIL = 0.5;

    /** The resolved ceiling. */
    public function maximum(Character $character): int|float
    {
        return Points::of($character->stat('fatigue_max')->value);
    }

    /**
     * What a screen draws: the figure, the ceiling, how full (0-1) and the colour of where it stands. Yellow from the
     * Tired line, ember from the Drained line. Pass the sheet when the caller already holds one.
     *
     * @return array{current: int|float, max: int|float, ratio: float, fill: string}
     */
    public function reading(Character $character, ?Sheet $sheet = null): array
    {
        $max = Points::of(($sheet ?? $character->sheet())->get('fatigue_max')->value);
        $current = Points::of($character->current_fatigue);
        $ratio = $max > 0 ? max(0.0, min(1.0, (float) $current / $max)) : 0.0;

        return [
            'current' => $current,
            'max' => $max,
            'ratio' => round($ratio, 3),
            'fill' => match (true) {
                $ratio >= (float) config('fatigue.drained_at') => 'var(--crit)',
                $ratio >= (float) config('fatigue.tired_at') => 'var(--warn)',
                default => 'var(--ok)',
            },
        ];
    }

    /** Raise fatigue, scaled by `fatigue_taken`. Returns the points actually added, which is less than asked at the ceiling. */
    public function add(Character $character, int|float $points): int|float
    {
        if ($points < 0) {
            throw new \InvalidArgumentException('Fatigue is never added negatively. Easing is its own act.');
        }

        // Scaled by what the character takes of it; easing is never scaled.
        return $this->move($character, $points * (float) $character->stat('fatigue_taken')->value / 100);
    }

    /**
     * What an action costs, from `config/fatigue.php` `costs`: the one call every hook makes. A negative cost eases
     * (a pod fare), a kind not listed costs nothing, and `$times` is for a cost that comes per thing (rounds, passes).
     *
     * ponytail: works on the instance it is handed and does not re-read the row, so two requests landing together can
     * lose one cost. Fatigue is a soft figure; lock and re-read here if it ever has to be exact.
     */
    public function charge(Character $character, string $kind, int $times = 1): void
    {
        $cost = (float) config("fatigue.costs.{$kind}", 0) * $times;

        if ($cost > 0) {
            $this->add($character, $cost);
        } elseif ($cost < 0) {
            $this->ease($character, -$cost);
        }
    }

    /** Lower fatigue. Returns the points actually removed, which is less than asked at nothing. */
    public function ease(Character $character, int|float $points): int|float
    {
        if ($points < 0) {
            throw new \InvalidArgumentException('Fatigue is never eased negatively. Adding is its own act.');
        }

        return Points::of(abs($this->move($character, -$points)));
    }

    private function move(Character $character, int|float $delta): int|float
    {
        $before = Points::of($character->getAttribute('current_fatigue'));
        $after = Points::of(max(0, min($this->maximum($character), $before + $delta)));

        if ($after === $before) {
            return 0;
        }

        $character->forceFill(['current_fatigue' => $after])->save();
        $this->sync($character);

        // Reaching the ceiling only FLAGS the collapse. It happens at the next safe moment (`Collapse`), never from
        // inside the travel loop, run turn or fight round that tipped it.
        if ($delta > 0 && $after >= $this->maximum($character) && ! $character->collapse_pending) {
            $character->forceFill(['collapse_pending' => true])->save();
        }

        return Points::of($after - $before);
    }

    /**
     * Make the status rows tell the truth about the figure: Tired from `tired_at` of the maximum, Drained from
     * `drained_at` (and Drained REPLACES Tired), and Exhausted gone once fatigue is under half. Run after every write
     * and by the minute clock, so a maximum that moved under a tired character is put right within the minute.
     * Exhausted is never created here: a collapse grants it (`exhaust()`).
     */
    public function sync(Character $character): void
    {
        $max = $this->maximum($character);
        $ratio = $max > 0 ? (float) $character->getAttribute('current_fatigue') / $max : 0.0;

        $want = match (true) {
            $ratio >= (float) config('fatigue.drained_at') => self::DRAINED,
            $ratio >= (float) config('fatigue.tired_at') => self::TIRED,
            default => null,
        };

        $rows = CharacterModifier::query()
            ->where('character_id', $character->getKey())
            ->whereIn('definition_key', [self::TIRED, self::DRAINED, self::EXHAUSTED])
            ->get()
            ->groupBy('definition_key');

        foreach ([self::TIRED, self::DRAINED] as $key) {
            if ($key !== $want) {
                CharacterModifier::query()->whereIn('id', $rows->get($key, collect())->pluck('id')->all())->delete();
            } elseif ($rows->get($key, collect())->isEmpty()) {
                CharacterModifier::query()->create(['character_id' => $character->getKey(), 'definition_key' => $key]);
            }
        }

        if ($ratio < self::EXHAUSTED_UNTIL) {
            CharacterModifier::query()->whereIn('id', $rows->get(self::EXHAUSTED, collect())->pluck('id')->all())->delete();
        }
    }

    /** What a collapse leaves you with. Idempotent: a second collapse while Exhausted is the same row. */
    public function exhaust(Character $character): void
    {
        CharacterModifier::query()->firstOrCreate([
            'character_id' => $character->getKey(),
            'definition_key' => self::EXHAUSTED,
        ]);
    }
}
