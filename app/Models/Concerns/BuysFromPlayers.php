<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\InventoryItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;

/**
 * What a buy listing is, whoever keeps it: a percentage of the item's stock price, a limit per window, and the
 * count taken so far in this one. Shared by `LocationBuy` and `EntityBuy` so the two cannot drift apart.
 *
 * The model needs `percent`, `max_quantity`, `bought` and `window_started_at` columns and an `item` relation.
 * `App\Trade\Buying` is the only thing that moves `bought`.
 */
trait BuysFromPlayers
{
    /** @return array<string, string> */
    protected function buyCasts(): array
    {
        return [
            'percent' => 'integer',
            'max_quantity' => 'integer',
            'bought' => 'integer',
            'window_started_at' => 'immutable_datetime',
        ];
    }

    /** What one copy of this row fetches: its share of the row's stock price. THE one place it is worked out. */
    public function unitPriceFor(InventoryItem $row): int
    {
        return (int) floor($row->stockPrice() * (int) $this->percent / 100);
    }

    /** How many more it will take in this window; null when it takes any number. Settle first. */
    public function remaining(): ?int
    {
        return $this->max_quantity === null ? null : max(0, (int) $this->max_quantity - (int) $this->bought);
    }

    /**
     * Roll the window: each whole interval since its boundary zeroes the count, and the boundary advances by whole
     * intervals, so a quiet buyer banks nothing. Does not save. Call it under a lock.
     */
    public function settleWindow(CarbonInterface $now): void
    {
        $hours = max(1, (int) Config::get('city.sell.reset_hours', 8));
        $since = $this->window_started_at ?? CarbonImmutable::instance($now);
        $intervals = intdiv(max(0, (int) floor($since->diffInSeconds($now, false))), $hours * 3600);

        if ($this->window_started_at === null || $intervals >= 1) {
            $this->bought = $intervals >= 1 ? 0 : $this->bought;
            $this->window_started_at = $since->addHours($intervals * $hours);
        }
    }
}
