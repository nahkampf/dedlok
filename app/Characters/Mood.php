<?php

declare(strict_types=1);

namespace App\Characters;

use App\Models\Character;
use App\Models\CharacterModifier;
use App\Support\Points;
use App\Support\Text;

/**
 * Mood: -100 to +100. The only writer of `characters.mood`. TODO.md, *Mood*.
 *
 * Events move it (`event()`, amounts in `config/mood.php`), the minute clock pulls it back to 0 (`drift()`), and its bands
 * grant effect rows the way Tired and Drained do (`sync()`, after every write). The caller owns the transaction.
 */
final class Mood
{
    /** Move it by a configured event, `$times` over (a point of harm, say). Returns the change actually made. */
    public function event(Character $character, string $event, int|float $times = 1): int|float
    {
        return $this->add($character, (float) config("mood.events.{$event}", 0) * $times);
    }

    public function add(Character $character, int|float $delta): int|float
    {
        $before = Points::of($character->mood);
        $after = Points::of(max((float) config('mood.min'), min((float) config('mood.max'), $before + $delta)));

        if ($after === $before) {
            return 0;
        }

        $character->forceFill(['mood' => $after])->save();
        $this->sync($character);

        return Points::of($after - $before);
    }

    /** A minute's pull towards 0, never past it: faster the further out it is (`extreme_beyond`). */
    public function drift(Character $character): void
    {
        $now = (float) $character->mood;
        $by = abs($now) > (float) config('mood.extreme_beyond') ? (float) config('mood.extreme_drift_per_minute') : (float) config('mood.drift_per_minute');

        if ($now !== 0.0) {
            $this->add($character, $now > 0 ? -min($by, $now) : min($by, -$now));
        }
    }

    /** @return array{value: int|float, word: string} */
    public function reading(Character $character): array
    {
        $value = Points::of($character->mood);
        $band = $this->band((float) $value);

        return ['value' => $value, 'word' => Text::get('character.mood.'.($band['word'] ?? 'steady'))];
    }

    /** The effect rows tell the truth about the figure. */
    public function sync(Character $character): void
    {
        $want = $this->band((float) $character->mood)['effect'] ?? null;
        $keys = array_column((array) config('mood.bands'), 'effect');

        $rows = CharacterModifier::query()->where('character_id', $character->getKey())->whereIn('definition_key', $keys)->get();
        CharacterModifier::query()->whereIn('id', $rows->where('definition_key', '!=', $want)->pluck('id')->all())->delete();

        if ($want !== null && $rows->where('definition_key', $want)->isEmpty()) {
            CharacterModifier::query()->create(['character_id' => $character->getKey(), 'definition_key' => $want]);
        }
    }

    /** @return array{at: int, effect: string, word: string}|array{} */
    private function band(float $value): array
    {
        foreach ((array) config('mood.bands') as $band) {
            if ($band['at'] < 0 ? $value <= $band['at'] : $value >= $band['at']) {
                return $band;
            }
        }

        return [];
    }
}
