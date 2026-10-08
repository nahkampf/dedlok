<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Cyberspace\Extractor;
use App\Decks\DeckRequirement;
use App\Decks\DeckTotals;
use App\Decks\Storage;
use App\Market\Purchaser;
use App\Market\PurchaseRefusal;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\MarketStock;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemContent;
use App\Programs\ProgramKind;
use App\Stats\Resolver;
use App\Support\Qb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * The data inventory lives on the equipped deck, and the deck has to hold it.
 *
 * Every deck starts with `decks.onboard_storage`; what is carried counts
 * against it; a door that would overfill it refuses, and a deck that is
 * overfull anyway (a smaller one equipped) will not run.
 */
final class DeckStorageTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        config(['decks.onboard_storage' => 20]);

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3, 'creds' => 10_000],
        ));
    }

    private function carry(Item $item, int $quantity = 1, array $extra = []): InventoryItem
    {
        return InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => $quantity, ...$extra]);
    }

    public function test_everything_in_the_data_inventory_counts_and_nothing_else(): void
    {
        $this->carry(Item::factory()->program(ProgramKind::Icebreaker, storage: 3)->create(['size' => 0]), 2);
        $this->carry(Item::factory()->data()->size(4)->create());
        $this->carry(Item::factory()->data()->size(5)->unstackable()->create(), 1, ['extracted_file' => ['size_qb' => 7]]);
        $this->carry(Item::factory()->physical()->size(9)->create());

        $shard = $this->carry(Item::factory()->physical()->holds('data')->create());
        $this->carry(Item::factory()->data()->size(6)->create(), 1, ['parent_id' => $shard->id]);

        // 2 × 3 for the programs, 4, and the file at its OWN 7 rather than its
        // item's 5. The physical thing and the shard's contents take nothing.
        $this->assertEquals(17, Storage::used($this->character));
    }

    public function test_no_deck_holds_nothing_and_every_deck_holds_the_onboard_figure(): void
    {
        $this->assertEquals(0, Storage::capacity($this->character));

        $this->equipReadyDeck($this->character);

        $this->assertEquals(20, Storage::capacity($this->character->fresh()));
    }

    public function test_a_deck_carries_its_own_figure_and_the_config_is_the_fallback(): void
    {
        $deck = $this->equipReadyDeck($this->character);
        $deck->item->forceFill(['onboard_storage' => 12])->save();

        $this->assertEquals(12, Storage::capacity($this->character->fresh()));

        $deck->item->forceFill(['onboard_storage' => null])->save();

        $this->assertEquals(20, Storage::capacity($this->character->fresh()));
        $this->assertEquals(0, Item::factory()->data()->create(['onboard_storage' => 99])->onboardStorage(), 'only a deck holds anything');
    }

    public function test_tenths_add_up_exactly_at_the_limit(): void
    {
        $deck = $this->equipReadyDeck($this->character);
        $deck->item->forceFill(['onboard_storage' => 0.3])->save();
        $character = $this->character->fresh();

        $this->carry(Item::factory()->data()->unstackable()->create(['size' => 0.1]));
        $this->carry(Item::factory()->data()->unstackable()->create(['size' => 0.2]));

        // 0.1 + 0.2 is 0.30000000000000004 in a float; the deck is exactly full, not over it.
        $this->assertEquals(0.3, Storage::used($character));
        $this->assertTrue(Storage::fits($character, 0.0));
        $this->assertFalse(Storage::fits($character, 0.1));
        $this->assertSame('0.4 QB', Qb::format(0.4).' QB');
        $this->assertSame('2', Qb::format(2.0));
    }

    public function test_an_overfull_deck_will_not_run(): void
    {
        $deck = $this->equipReadyDeck($this->character);
        $this->carry(Item::factory()->data()->size(21)->create());

        $totals = DeckTotals::forCharacter($this->character->fresh(), $deck, app(Resolver::class));

        $this->assertSame([DeckRequirement::Storage], $totals->unmet());
    }

    public function test_a_purchase_that_would_not_fit_is_refused(): void
    {
        $this->equipReadyDeck($this->character);
        $item = Item::factory()->data()->size(8)->create(['on_market' => true, 'base_cost' => 10]);
        MarketStock::query()->create(['item_id' => $item->id, 'quantity' => 5, 'last_seeded_at' => now()]);

        $this->assertTrue((new Purchaser)->buy($this->character, $item, 2)->succeeded());

        $refused = (new Purchaser)->buy($this->character, $item);

        $this->assertSame(PurchaseRefusal::NoStorage, $refused->refusal);
        $this->assertSame(3, MarketStock::query()->where('item_id', $item->id)->value('quantity'), 'nothing moved');
    }

    public function test_a_file_the_deck_cannot_hold_stays_on_the_system(): void
    {
        $this->equipReadyDeck($this->character);
        $system = System::factory()->at(8, 8)->grade(1)->create(['address' => 'DEADBEEF']);
        $skill = Skill::factory()->create(['slug' => 'cinder', 'title' => 'Cinder', 'category' => 'Encryption', 'rank' => 2]);
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 50]);
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'data', 'label' => 'Ledger',
            'size_qb' => 21, 'encryption_skill_id' => $skill->id, 'value_sealed' => 1, 'value_broken' => 2,
        ]);

        $result = (new Extractor)->extract($this->character, 'DEADBEEF');

        $this->assertSame(50, $result->creds, 'what fits is still taken');
        $this->assertSame(0, $result->files);
        $this->assertTrue($result->leftBehind);
        $this->assertSame(1, SystemContent::query()->where('kind', 'data')->count());
    }
}
