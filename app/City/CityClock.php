<?php

declare(strict_types=1);

namespace App\City;

use App\Models\Character;
use App\Models\CityActivity;
use Carbon\CarbonInterface;

/**
 * Settles whatever a character was doing in the City.
 *
 * LAZY, with a sweep behind it: every City request settles on the way in, and
 * `city:settle` makes the rows true between visits and tells the player what
 * they missed. The sweep is NOT what makes an activity finish.
 */
final class CityClock
{
    public function __construct(
        private readonly Traveller $traveller,
        private readonly Explorer $explorer,
        private readonly Surgeon $surgeon,
    ) {}

    /** @return list<array{0: string, 1: array<string, string>}> message key and replacements */
    public function settle(Character $character, ?CarbonInterface $now = null, bool $notify = false): array
    {
        return [
            ...$this->traveller->settle($character, $now, $notify),
            ...$this->explorer->settle($character, $now, $notify),
            ...$this->surgeon->settle($character, $now, $notify),
        ];
    }

    /** Everybody with a pass that has matured. @return int how many characters were settled */
    public function sweep(?CarbonInterface $now = null): int
    {
        $due = CityActivity::query()->where('pass_ends_at', '<=', $now ?? now())->pluck('character_id');
        $settled = 0;

        foreach (Character::query()->whereIn('id', $due)->cursor() as $character) {
            $this->settle($character, $now, notify: true);
            $settled++;
        }

        return $settled;
    }
}
