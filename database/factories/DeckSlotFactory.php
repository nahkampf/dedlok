<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Decks\SlotKind;
use App\Decks\Variant;
use App\Models\DeckSlot;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeckSlot>
 */
class DeckSlotFactory extends Factory
{
    protected $model = DeckSlot::class;

    /**
     * A CPU socket, because a deck that has one socket has this one. Nothing
     * is randomised: a slot's kind decides what fits, so a random one would
     * make every compatibility test roll dice it never asked to roll.
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'kind' => SlotKind::Cpu,
            'variant' => Variant::SocketK,
            'position' => 1,
        ];
    }

    public function of(SlotKind $kind, ?Variant $variant = null): static
    {
        return $this->state(['kind' => $kind, 'variant' => $variant]);
    }

    public function at(int $position): static
    {
        return $this->state(['position' => $position]);
    }
}
