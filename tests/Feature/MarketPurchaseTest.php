<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Decks\Hardware;
use App\Decks\Variant;
use App\Inventory\Imprint;
use App\Inventory\ItemGranter;
use App\Market\Purchaser;
use App\Market\PurchaseRefusal;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\MarketPurchase;
use App\Models\MarketStock;
use App\Models\Skill;
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

    /** RAM is split under its sticks and a plain kind is not split at all. */
    public function test_modules_are_split_by_variant_under_their_kind(): void
    {
        foreach ([Variant::Opto, Variant::QDram] as $variant) {
            $item = Item::factory()->module(Hardware::Ram, $variant, draw: 12, thermalLoad: 7)->create(['base_cost' => 50, 'on_market' => true]);
            MarketStock::query()->create(['item_id' => $item->id, 'quantity' => 3, 'last_seeded_at' => now()]);
        }

        $this->actingAs($this->character->user)
            ->get(route('market.index'))
            ->assertOk()
            ->assertSeeInOrder(['RAM', Variant::Opto->label(), Variant::QDram->label()])
            ->assertSeeText('100 QB')
            ->assertSeeText('12 W draw')
            ->assertSeeText('7 TU');
    }

    /** Implants leave Physical for a category of their own. */
    public function test_physical_gear_is_split_into_sections(): void
    {
        $implant = Item::factory()->create(['container' => 'physical', 'base_cost' => 50, 'on_market' => true, 'implant_region' => 'head', 'stackable' => false]);
        $plain = $this->stocked();
        MarketStock::query()->create(['item_id' => $implant->id, 'quantity' => 1, 'last_seeded_at' => now()]);

        $this->actingAs($this->character->user)
            ->get(route('market.index'))
            ->assertOk()
            ->assertSeeInOrder([Text::get('market.categories.implants')]);
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

    // ---- the corner tick ---------------------------------------------------

    /** Anything they hold a row of, wherever the row is, wears the kit's --ok. */
    public function test_a_thing_you_own_is_ticked(): void
    {
        $item = $this->stocked();
        ItemGranter::grant($this->character, $item);

        $this->actingAs($this->character->user)
            ->get(route('market.index'))
            ->assertOk()
            ->assertSee('title="'.Text::get('market.tick.owned').'"', false);
    }

    public function test_a_thing_you_do_not_own_is_not(): void
    {
        $this->stocked();

        $this->actingAs($this->character->user)
            ->get(route('market.index'))
            ->assertOk()
            ->assertDontSee('class="tick"', false);
    }

    // ---- skills are not on the market ------------------------------------------

    /**
     * A SKILL IMPRINT IS NEVER ON THE SHELF, whatever a row says. Skills come from shops, loot and loyalty stores;
     * a shelf row left over from before is neither drawn nor buyable.
     */
    public function test_an_imprint_is_not_on_the_shelf_and_cannot_be_bought(): void
    {
        $skill = Skill::factory()->create(['description' => 'Split the work and run it twice.']);
        $book = Imprint::ensureFor($skill);
        $book->forceFill(['on_market' => true])->save();
        MarketStock::query()->create(['item_id' => $book->id, 'quantity' => 3, 'last_seeded_at' => now()]);
        $this->character->forceFill(['creds' => 1_000_000])->save();

        $this->actingAs($this->character->user)
            ->get(route('market.index'))
            ->assertOk()
            ->assertDontSeeText('Split the work and run it twice.')
            ->assertDontSee('data-inspect=', false);

        $result = app(Purchaser::class)->buy($this->character->fresh(), $book);

        $this->assertFalse($result->succeeded());
        $this->assertSame(3, (int) MarketStock::query()->where('item_id', $book->id)->value('quantity'));
        $this->assertNotContains($book->id, Item::query()->restockable()->pluck('id')->all());
    }

    /** Anything that is not an imprint has no skill to show, and no hover card. */
    public function test_an_ordinary_item_has_no_hover_card(): void
    {
        $this->stocked();

        $this->actingAs($this->character->user)
            ->get(route('market.index'))
            ->assertOk()
            ->assertDontSee('data-inspect=', false);
    }

    // ---- buying without a reload ---------------------------------------------

    /**
     * ASKED FOR JSON, IT ANSWERS WITH WHAT CHANGED: the money left, the shelf
     * left, and the corner tick as it now reads — everything the page needs
     * to redraw itself without a round trip for the whole screen.
     */
    public function test_a_json_purchase_says_what_changed(): void
    {
        $item = $this->stocked(quantity: 5, price: 100);

        $this->actingAs($this->character->user)
            ->postJson(route('market.buy', $item), ['quantity' => 2])
            ->assertOk()
            ->assertJson([
                'creds' => 800,
                'left' => 3,
                'left_text' => Text::plural('market.on_shelf', 3),
            ])
            ->assertJsonPath('tick', fn (string $html): bool => str_contains($html, Text::get('market.tick.owned')))
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, $item->displayName()));

        $this->assertSame(800, (int) $this->character->fresh()->creds);
    }

    /** A refusal is a 422 with the same sentence the redirect would flash. */
    public function test_a_json_refusal_is_a_422_with_the_reason(): void
    {
        $item = $this->stocked(quantity: 5, price: 600);

        $this->actingAs($this->character->user)
            ->postJson(route('market.buy', $item), ['quantity' => 2])
            ->assertStatus(422)
            ->assertJson(['message' => PurchaseRefusal::CannotAfford->message()]);

        $this->assertSame(1000, (int) $this->character->fresh()->creds);
    }

    /** The shelf's form asks for it, and carries what the page re-reads. */
    public function test_the_shelf_form_buys_without_a_reload(): void
    {
        $item = $this->stocked(quantity: 5, price: 100);

        $this->actingAs($this->character->user)
            ->get(route('market.index'))
            ->assertOk()
            ->assertSee('data-buy', false)
            ->assertSee('data-price="100" data-left="5"', false)
            ->assertSee('data-creds', false);
    }

    /** A flash after a redirect is the kind that glitches in and leaves. */
    public function test_a_flash_is_marked_to_leave_by_itself(): void
    {
        $item = $this->stocked();

        $this->actingAs($this->character->user)
            ->from(route('market.index'))
            ->post(route('market.buy', $item))
            ->assertRedirect();

        $this->actingAs($this->character->user)
            ->get(route('market.index'))
            ->assertSee('callout callout--ok callout--flash', false);
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
