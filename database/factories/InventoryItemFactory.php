<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'character_id' => Character::factory(),
            'item_id' => Item::factory(),
            'quantity' => 1,
        ];
    }

    public function of(Item $item): static
    {
        return $this->state(['item_id' => $item->id]);
    }

    public function ownedBy(Character $character): static
    {
        return $this->state(['character_id' => $character->id]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(['quantity' => $quantity]);
    }
}
