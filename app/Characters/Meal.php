<?php

declare(strict_types=1);

namespace App\Characters;

use App\Models\Character;
use App\Support\Points;

/**
 * Eating: how much fullness a meal adds and how much fatigue it takes off, wherever the meal comes from. THE ONE PLACE
 * eating is done: a safehouse table (`meal_fullness`, `meal_fatigue` on the type), the food effects of a consumable
 * (`UseEffect`), and later a location's menu all build a Meal and ask it. Fullness can never be forced past the
 * maximum, so a full stomach refuses a meal that would fill it.
 */
final readonly class Meal
{
    public function __construct(public int|float $fullness = 0, public int|float $fatigue = 0) {}

    /** Validation rules for the two columns, so every editor that authors a meal asks the same thing. @return array<string, list<string>> */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'meal_fullness' => ['nullable', 'numeric', 'between:0,'.config('fatigue.fullness.max')],
            $prefix.'meal_fatigue' => ['nullable', 'numeric', 'between:0,9999.99'],
        ];
    }

    /** Does it do anything at all? */
    public function isEmpty(): bool
    {
        return $this->fullness <= 0 && $this->fatigue <= 0;
    }

    /** A meal that fills cannot be eaten by someone full. */
    public function refusedFor(Character $character): bool
    {
        return $this->fullness > 0 && app(Fullness::class)->isFull($character);
    }

    /** How much of each it would do right now. Reads, never writes. @return array{fullness: int|float, fatigue: int|float} */
    public function would(Character $character): array
    {
        $fullness = app(Fullness::class);

        return [
            'fullness' => Points::of(min($this->fullness, max(0, (float) $fullness->maximum() - (float) $character->current_fullness))),
            'fatigue' => Points::of(min($this->fatigue, (float) $character->current_fatigue)),
        ];
    }

    /** Eat it. Returns what actually changed. @return array{fullness: int|float, fatigue: int|float} */
    public function apply(Character $character): array
    {
        if ($this->fullness > 0) {
            app(Mood::class)->event($character, 'meal');
        }

        return [
            'fullness' => $this->fullness > 0 ? app(Fullness::class)->add($character, $this->fullness) : 0,
            'fatigue' => $this->fatigue > 0 ? app(Fatigue::class)->ease($character, $this->fatigue) : 0,
        ];
    }
}
