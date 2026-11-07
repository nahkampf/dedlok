<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Decks\DeckTotals;
use App\Decks\Hardware;
use App\Decks\ModuleFitter;
use App\Decks\Variant;
use App\Models\Character;
use App\Models\DeckFitting;
use App\Models\DeckSlot;
use App\Models\Item;
use App\Stats\Resolver;
use App\Stats\Sources\FittedModules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** An expansion card does what is written on its item: the eighth modifier source reads the equipped deck's fittings. */
final class FittedModulesTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    public function test_a_fitted_expansion_card_adds_to_the_decks_ram_only_while_that_deck_is_equipped(): void
    {
        $character = Character::factory()->create(['archetype' => 3]);
        $deck = $this->equipReadyDeck($character);
        $before = DeckTotals::forCharacter($character->fresh(), $deck->fresh(), app(Resolver::class))->quantabits;

        // The deck has no expansion socket, so the card is fitted straight into a row (what ModuleFitter would write).
        $card = Item::factory()->module(Hardware::Expansion, Variant::XGate, measure: 0, draw: 0)->create([
            'modifiers' => [['op' => 'add', 'amount' => 4, 'target' => ['id' => 'deck_quantabits']]],
        ]);
        $slot = DeckSlot::query()->create(['item_id' => $deck->item_id, 'kind' => 'expansion', 'variant' => 'xgate', 'position' => 9]);
        DeckFitting::query()->create(['deck_id' => $deck->id, 'deck_slot_id' => $slot->id, 'module_id' => $this->ownedBy($character, $card)->id]);

        $after = DeckTotals::forCharacter($character->fresh(), $deck->fresh(), app(Resolver::class))->quantabits;

        $this->assertEqualsWithDelta($before + 4, $after, 0.01);

        // A deck that is not the equipped one gives nothing.
        $character->forceFill(['equipped_deck_id' => null])->save();
        $this->assertSame([], (new FittedModules($character->fresh()))->modifiers());
    }

    public function test_the_equipped_decks_own_effects_count(): void
    {
        $character = Character::factory()->create(['archetype' => 3]);
        $deck = $this->equipReadyDeck($character);
        $before = DeckTotals::forCharacter($character->fresh(), $deck->fresh(), app(Resolver::class))->quantabits;

        $deck->item->update(['modifiers' => [['op' => 'add', 'amount' => 4, 'target' => ['id' => 'deck_quantabits']]]]);

        $after = DeckTotals::forCharacter($character->fresh(), $deck->fresh(), app(Resolver::class))->quantabits;

        $this->assertEqualsWithDelta($before + 4, $after, 0.01);
    }
}
