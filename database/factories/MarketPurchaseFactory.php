<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Item;
use App\Models\MarketPurchase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketPurchase>
 */
class MarketPurchaseFactory extends Factory
{
    protected $model = MarketPurchase::class;

    public function definition(): array
    {
        return [
            'character_id' => null,
            'item_id' => Item::factory(),
            'quantity' => 1,
            'unit_price' => fake()->numberBetween(10, 50_000),
            'purchased_at' => now(),
        ];
    }

    /** Demand: $quantity units of $item, $daysAgo days back. */
    public function of(Item $item, int $quantity = 1, float $daysAgo = 0): static
    {
        return $this->state([
            'item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => $item->base_cost,
            'purchased_at' => now()->subDays($daysAgo),
        ]);
    }
}
