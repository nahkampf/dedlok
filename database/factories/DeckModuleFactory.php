<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Decks\Hardware;
use App\Decks\Variant;
use App\Models\DeckModule;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeckModule>
 */
class DeckModuleFactory extends Factory
{
    protected $model = DeckModule::class;

    /** A blank spec. `of()` is how it becomes a CPU or a supply. */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'variant' => null,
            'power_draw' => 0,
            'qflops' => null,
            'quantabits' => null,
            'bandwidth' => null,
            'watts' => null,
        ];
    }

    /**
     * The spec a module of $kind would have: its own measure filled, the
     * other three left null, and a draw unless it is the thing supplying it.
     */
    public function of(Hardware $kind, ?Variant $variant = null, int $measure = 100, int $draw = 10): static
    {
        $state = ['variant' => $variant, 'power_draw' => $kind->draws() ? $draw : 0];

        $column = $kind->measure();

        if ($column !== null) {
            $state[$column] = $measure;
        }

        return $this->state($state);
    }
}
