<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Market\Purchaser;
use App\Market\PurchaseRefusal;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\MarketPurchase;
use App\Models\MarketStock;
use App\Programs\ProgramKind;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Buying something, which is the first thing in the game that spends money.
 *
 * `characters.creds` existed for four releases with nothing to spend it on and
 * the shelves restocked themselves for nobody. This is the other side of both.
 *
 * WHAT IS ASSERTED MOST CAREFULLY IS THE SHELF. Two people reaching for the
 * last one is the case the transaction exists for, and a purchase row is the
 * demand signal the restock pass reads — so neither is allowed to be
 * approximately right.
 */
final class MarketPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3, 'creds' => 1000],
        ));
    }

    private function stocked(int $quantity = 5, int $price = 100, bool $stackable = true): Item
    {
        $item = Item::factory()->create([
            'container' => 'physical',
            'base_cost' => $price,
            'on_market' => true,
            'stackable' => $stackable,
        ]);

        MarketStock::query()->create([
            'item_id' => $item->id,
            'quantity' => $quantity,
            'last_seeded_at' => now(),
        ]);

        return $item->fresh();
    }

    private function purchaser(): Purchaser
    {
        return new Purchaser;
    }

    // ---- what a purchase moves ---------------------------------------------

    public function test_buying_one_moves_money_stock_and_goods(): void
    {
        $item = $this->stocked();

        $result = $this->purchaser()->buy($this->character, $item);

        $this->assertTrue($result->succeeded());
        $this->assertSame(100, $result->paid);

        $this->assertSame(900, (int) $this->character->fresh()->creds);
        $this->assertSame(4, (int) MarketStock::query()->sole()->quantity);
        $this->assertSame(1, (int) InventoryItem::query()->sole()->quantity);
    }

    /** The price is the ITEM'S, and what was paid is kept on the purchase. */
    public function test_the_purchase_records_what_it_cost_that_day(): void
    {
        $item = $this->stocked(price: 250);

        $this->purchaser()->buy($this->character, $item, 2);

        $purchase = MarketPurchase::query()->sole();

        $this->assertSame($this->character->id, (int) $purchase->character_id);
        $this->assertSame(2, (int) $purchase->quantity);
        $this->assertSame(250, (int) $purchase->unit_price);

        // Retuning the catalogue afterwards does not rewrite history.
        $item->forceFill(['base_cost' => 900])->save();
        $this->assertSame(250, (int) MarketPurchase::query()->sole()->unit_price);
    }

    public function test_several_of_a_stackable_thing_are_one_pile(): void
    {
        $this->purchaser()->buy($this->character, $this->stocked(), 3);

        $this->assertSame(1, InventoryItem::query()->count());
        $this->assertSame(3, (int) InventoryItem::query()->sole()->quantity);
    }

    /** And something unstackable is one row per copy, which is what it means. */
    public function test_several_of_an_unstackable_thing_are_several_objects(): void
    {
        $this->purchaser()->buy($this->character, $this->stocked(stackable: false), 3);

        $this->assertSame(3, InventoryItem::query()->count());
    }

    // ---- what is refused ---------------------------------------------------

    public function test_an_empty_shelf_sells_nothing(): void
    {
        $item = $this->stocked(quantity: 0);

        $this->assertSame(
            PurchaseRefusal::NotStocked,
            $this->purchaser()->buy($this->character, $item)->refusal,
        );
        $this->assertSame(1000, (int) $this->character->fresh()->creds);
    }

    /** A missing shelf row is a stock of zero, not an error. */
    public function test_an_item_nobody_ever_stocked_sells_nothing(): void
    {
        $item = Item::factory()->create(['container' => 'physical', 'on_market' => true, 'base_cost' => 10]);

        $this->assertSame(
            PurchaseRefusal::NotStocked,
            $this->purchaser()->buy($this->character, $item)->refusal,
        );
    }

    /** Withdrawn from sale is the same answer as never stocked. */
    public function test_something_off_the_market_sells_nothing(): void
    {
        $item = $this->stocked();
        $item->forceFill(['on_market' => false])->save();

        $this->assertSame(
            PurchaseRefusal::NotStocked,
            $this->purchaser()->buy($this->character->fresh(), $item->fresh())->refusal,
        );
    }

    /**
     * ASKING FOR MORE THAN IS THERE IS REFUSED, not quietly reduced: somebody
     * who asked for five and could have had two did not decide that.
     */
    public function test_asking_for_more_than_is_left_is_refused(): void
    {
        $item = $this->stocked(quantity: 2);

        $this->assertSame(
            PurchaseRefusal::NotEnoughLeft,
            $this->purchaser()->buy($this->character, $item, 5)->refusal,
        );
        $this->assertSame(2, (int) MarketStock::query()->sole()->quantity, 'and nothing was sold');
    }

    public function test_money_that_is_not_there_buys_nothing(): void
    {
        $item = $this->stocked(price: 400);

        $result = $this->purchaser()->buy($this->character, $item, 3);

        $this->assertSame(PurchaseRefusal::CannotAfford, $result->refusal);
        $this->assertSame(1000, (int) $this->character->fresh()->creds);
        $this->assertSame(5, (int) MarketStock::query()->sole()->quantity);
        $this->assertSame(0, InventoryItem::query()->count());
    }

    /** Exactly enough is enough. */
    public function test_spending_the_last_cred_is_allowed(): void
    {
        $item = $this->stocked(price: 500);

        $this->assertTrue($this->purchaser()->buy($this->character, $item, 2)->succeeded());
        $this->assertSame(0, (int) $this->character->fresh()->creds);
    }

    // ---- the screen --------------------------------------------------------

    public function test_the_shelf_lists_what_is_on_it(): void
    {
        $stocked = $this->stocked();
        $empty = $this->stocked(quantity: 0);

        $this->actingAs($this->character->user)
            ->get(route('market.index'))
            ->assertOk()
            ->assertSeeText($stocked->displayName())
            ->assertDontSeeText($empty->displayName());
    }

    /** One accordion per kind, each card carrying the words the search box matches. */
    public function test_the_shelf_is_grouped_by_kind_and_searchable(): void
    {
        $item = $this->stocked();

        $this->actingAs($this->character->user)
            ->get(route('market.index'))
            ->assertOk()
            ->assertSee('data-filter="#shelves"', false)
            ->assertSee('<details class="panel" data-filter-group>', false)
            ->assertSee('data-filter-text="'.e($item->displayName()), false)
            ->assertSeeText($item->kindName());
    }

    /**
     * EVERY ICEBREAKER IS BUYABLE. It is the gate in front of every fight —
     * without one a run that meets ICE has nothing to cut with — and the
     * other half of that rule is pinned in DefencesTest: twenty turns is
     * never enough, so the ladder has to be climbable by buying the next
     * piece as well as by training for it.
     *
     * A shelf size of zero would mean listed and never delivered, which is
     * the default and was the state they shipped in.
     */
    public function test_every_icebreaker_can_reach_a_shelf(): void
    {
        $icebreakers = Item::query()
            ->whereHas('program', fn ($query) => $query->where('kind', ProgramKind::Icebreaker))
            ->get();

        $this->assertCount(12, $icebreakers);

        foreach ($icebreakers as $item) {
            $this->assertTrue($item->on_market, "{$item->name} is not listed");
            $this->assertGreaterThan(0, $item->seed_quantity, "{$item->name} has a shelf of nothing");
        }

        // And the sizes fall as the rarity climbs, which is what makes the top
        // of the ladder scarce rather than merely expensive.
        $byRarity = $icebreakers->groupBy(fn (Item $item): string => $item->rarity->value)
            ->map(fn ($group): int => (int) $group->first()->seed_quantity);

        $this->assertGreaterThan($byRarity['ultra_rare'], $byRarity['common']);
        $this->assertGreaterThanOrEqual($byRarity['rare'], $byRarity['uncommon']);
    }

    public function test_an_empty_market_says_so(): void
    {
        $this->actingAs($this->character->user)
            ->get(route('market.index'))
            ->assertOk()
            ->assertSeeText(Text::get('market.empty'));
    }

    public function test_the_screen_buys(): void
    {
        $item = $this->stocked();

        $this->actingAs($this->character->user)
            ->from(route('market.index'))
            ->post(route('market.buy', $item), ['quantity' => 2])
            ->assertRedirect(route('market.index'))
            ->assertSessionHas('status');

        $this->assertSame(800, (int) $this->character->fresh()->creds);
        $this->assertSame(2, (int) InventoryItem::query()->sole()->quantity);
    }

    public function test_a_refusal_arrives_as_a_message(): void
    {
        $item = $this->stocked(price: 5000);

        $this->actingAs($this->character->user)
            ->from(route('market.index'))
            ->post(route('market.buy', $item))
            ->assertSessionHas('error', PurchaseRefusal::CannotAfford->message());
    }

    public function test_a_nonsense_quantity_is_refused_by_the_form(): void
    {
        $item = $this->stocked();

        $this->actingAs($this->character->user)
            ->from(route('market.index'))
            ->post(route('market.buy', $item), ['quantity' => 0])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(1000, (int) $this->character->fresh()->creds);
    }
}
