<?php

declare(strict_types=1);

namespace App\City;

use App\Models\Character;
use App\Models\CityActivity;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Safehouses\HomeActions;
use App\Safehouses\Leads;
use App\Safehouses\Renter;
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
        private readonly HomeActions $home,
        private readonly Renter $renter,
        private readonly Leads $leads,
    ) {}

    /** @return list<array{0: string, 1: array<string, string>}> message key and replacements */
    public function settle(Character $character, ?CarbonInterface $now = null, bool $notify = false): array
    {
        // A rest is credited as it is looked at, and the rent that fell due is charged.
        $this->home->settleRest($character, $now);
        $this->home->settleSession($character, $now);
        $this->renter->settle($character, $now);

        return [
            ...$this->traveller->settle($character, $now, $notify),
            ...$this->explorer->settle($character, $now, $notify),
            ...$this->surgeon->settle($character, $now, $notify),
            ...$this->watching($character, $now, $notify),
        ];
    }

    /** A stakeout or scouting: passes of a minute. @return list<array{0: string, 1: array<string, string>}> */
    private function watching(Character $character, ?CarbonInterface $now, bool $notify): array
    {
        $messages = $this->leads->settle($character, $now, $notify);

        if ($notify) {
            foreach ($messages as [$key, $replace]) {
                (new Notifier)->send($character, Category::City, $key, $replace);
            }
        }

        return $messages;
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
