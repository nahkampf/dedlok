<?php

namespace Tests\Feature;

use App\Market\MarketSeeder;
use App\Market\Rarity;
use App\Models\Item;
use App\Models\MarketPurchase;
use App\Models\MarketStock;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The market's supply, which is the whole of the market that exists so far.
 *
 * Two mechanisms, and they are tested apart because they fail differently: the
 * arithmetic decides how deep a shelf should be, and the rarity roll decides
 * whether it gets filled at all. The roll is injected throughout — a
 * probabilistic pass tested against real dice is a test that passes 95% of the
 * time, which is worse than no test.
 */
class MarketSeedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * NOTHING ON THE SHELF THAT THIS TEST DID NOT PUT THERE.
     *
     * The catalogue is content and content arrives by migration — a skill
     * shipped that way brings a restockable skillbook with it, which is an
     * item in the pass that no test here created. These tests pin the die by
     * SEQUENCE and count what a pass considered, so one extra item shifts
     * every roll by one. Zeroing the seed quantity is what `restockable()`
     * reads, so it takes them out of the pass without deleting content other
     * tests may rely on.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Item::query()->update(['seed_quantity' => 0]);
    }

    /** An item the market carries, common so the roll never gets in the way. */
    private function stocked(int $seedQuantity, Rarity $rarity = Rarity::Common): Item
    {
        return Item::factory()->rarity($rarity)->restocks($seedQuantity)->create();
    }

    /**
     * A pass with the dice pinned: 1 passes every rarity, 100 fails all but common.
     *
     * Not named seeder() — RefreshDatabase has a method by that name and would
     * hand this to `db:seed --class`.
     */
    private function restocker(int $roll = 1): MarketSeeder
    {
        return MarketSeeder::fromConfig(static fn (): int => $roll);
    }

    /** Rolls in order, one per item, so a multi-item pass can be steered. */
    private function rolling(int ...$rolls): Closure
    {
        return static function () use (&$rolls): int {
            return array_shift($rolls) ?? 100;
        };
    }

    private function shelf(Item $item): int
    {
        return (int) (MarketStock::where('item_id', $item->id)->value('quantity') ?? 0);
    }

    private function bought(Item $item, int $quantity, float $daysAgo = 0): void
    {
        MarketPurchase::factory()->of($item, $quantity, $daysAgo)->create();
    }

    public function test_an_empty_shelf_fills_to_the_seed_quantity(): void
    {
        $item = $this->stocked(10);

        $this->restocker()->run();

        $this->assertSame(10, $this->shelf($item));
    }

    public function test_a_pass_tops_up_rather_than_adds(): void
    {
        $item = $this->stocked(10);
        MarketStock::create(['item_id' => $item->id, 'quantity' => 4]);

        $result = $this->restocker()->run();

        $this->assertSame(10, $this->shelf($item));
        $this->assertSame(6, $result->for($item->slug)->added);
    }

    public function test_a_full_shelf_gains_nothing(): void
    {
        $item = $this->stocked(10);
        $this->restocker()->run();

        $result = $this->restocker()->run();

        $this->assertSame(10, $this->shelf($item));
        $this->assertSame(0, $result->for($item->slug)->added);
        $this->assertTrue($result->for($item->slug)->wasAlreadyFull());
    }

    /** Stock above the target is left alone: the market does not take things back. */
    public function test_an_overfull_shelf_is_not_trimmed(): void
    {
        $item = $this->stocked(10);
        MarketStock::create(['item_id' => $item->id, 'quantity' => 25]);

        $this->restocker()->run();

        $this->assertSame(25, $this->shelf($item));
    }

    public function test_demand_below_the_threshold_leaves_the_shelf_at_its_seed_quantity(): void
    {
        $item = $this->stocked(10);
        $this->bought($item, 4);   // 0.4 of the shelf, under the 0.5 threshold

        $result = $this->restocker()->run();

        $this->assertSame(4, $result->for($item->slug)->demand);
        $this->assertSame(10, $result->for($item->slug)->target);
    }

    /**
     * The two branches of the formula meet.
     *
     * At exactly the threshold the multiplier is 1, so demand that has only
     * just become interesting does not double the shelf in one step.
     */
    public function test_demand_at_the_threshold_is_continuous(): void
    {
        $item = $this->stocked(10);
        $this->bought($item, 5);

        $this->assertSame(10, $this->restocker()->run()->for($item->slug)->target);
    }

    public function test_demand_above_the_threshold_deepens_the_shelf_in_proportion(): void
    {
        $item = $this->stocked(10);
        $this->bought($item, 8);   // ratio 0.8, so 1.6x the shelf

        $result = $this->restocker()->run();

        $this->assertSame(16, $result->for($item->slug)->target);
        $this->assertSame(16, $this->shelf($item));
    }

    public function test_the_deepening_is_capped(): void
    {
        $item = $this->stocked(10);
        $this->bought($item, 500);   // 50x the shelf, which would be a 100x delivery

        $this->assertSame(40, $this->restocker()->run()->for($item->slug)->target);
    }

    public function test_demand_is_the_sum_of_the_window(): void
    {
        $item = $this->stocked(10);
        $this->bought($item, 3, daysAgo: 1);
        $this->bought($item, 5, daysAgo: 6);

        $this->assertSame(8, $this->restocker()->run()->for($item->slug)->demand);
    }

    public function test_purchases_older_than_the_window_are_not_demand(): void
    {
        $item = $this->stocked(10);
        $this->bought($item, 40, daysAgo: 8);

        $result = $this->restocker()->run();

        $this->assertSame(0, $result->for($item->slug)->demand);
        $this->assertSame(10, $result->for($item->slug)->target);
    }

    public function test_demand_for_one_item_does_not_stock_another(): void
    {
        $wanted = $this->stocked(10);
        $ignored = $this->stocked(10);
        $this->bought($wanted, 10);

        $result = $this->restocker()->run();

        $this->assertSame(20, $result->for($wanted->slug)->target);
        $this->assertSame(10, $result->for($ignored->slug)->target);
    }

    public function test_a_failed_rarity_roll_delivers_nothing_at_all(): void
    {
        $item = $this->stocked(10, Rarity::Rare);   // 20% chance

        $result = $this->restocker(roll: 21)->run();

        $this->assertFalse($result->for($item->slug)->stocked);
        $this->assertSame(0, $this->shelf($item));
        $this->assertDatabaseCount('market_stock', 0);
    }

    public function test_a_passed_rarity_roll_delivers_the_whole_shelf(): void
    {
        $item = $this->stocked(10, Rarity::Rare);

        $result = $this->restocker(roll: 20)->run();

        $this->assertTrue($result->for($item->slug)->stocked);
        $this->assertSame(10, $this->shelf($item));
    }

    /** 100% means 100%: there is no roll a common item can fail. */
    public function test_common_items_always_restock(): void
    {
        $item = $this->stocked(10);

        $this->restocker(roll: 100)->run();

        $this->assertSame(10, $this->shelf($item));
    }

    public function test_a_failed_roll_leaves_an_existing_shelf_untouched(): void
    {
        $item = $this->stocked(10, Rarity::UltraRare);
        MarketStock::create(['item_id' => $item->id, 'quantity' => 3, 'last_seeded_at' => now()->subDay()]);

        $this->restocker(roll: 6)->run();

        $this->assertSame(3, $this->shelf($item));
    }

    public function test_each_item_rolls_for_itself(): void
    {
        $lucky = $this->stocked(10, Rarity::Rare);
        $unlucky = $this->stocked(10, Rarity::Rare);

        $seeder = new MarketSeeder($this->rolling(1, 99), 7, 0.5, 4.0);
        $result = $seeder->run();

        $this->assertSame(10, $this->shelf($lucky));
        $this->assertSame(0, $this->shelf($unlucky));
        $this->assertSame(1, $result->itemsPassedOver());
    }

    public function test_items_with_no_seed_quantity_are_never_stocked(): void
    {
        $item = Item::factory()->create(['seed_quantity' => 0, 'on_market' => true]);

        $result = $this->restocker()->run();

        $this->assertSame(0, $result->itemsConsidered());
        $this->assertNull($result->for($item->slug));
    }

    public function test_items_off_the_market_are_never_stocked(): void
    {
        $item = Item::factory()->restocks(10)->offMarket()->create();

        $this->assertSame(0, $this->restocker()->run()->itemsConsidered());
        $this->assertSame(0, $this->shelf($item));
    }

    public function test_a_dry_run_decides_everything_and_writes_nothing(): void
    {
        $item = $this->stocked(10);

        $result = $this->restocker()->run(dryRun: true);

        $this->assertSame(10, $result->for($item->slug)->added);
        $this->assertSame(0, $this->shelf($item));
        $this->assertDatabaseCount('market_stock', 0);
    }

    public function test_a_delivery_records_when_it_arrived(): void
    {
        $item = $this->stocked(10);

        $this->restocker()->run();

        $this->assertNotNull(MarketStock::where('item_id', $item->id)->value('last_seeded_at'));
    }

    /**
     * A rarity with no chance configured would restock at 0%, which looks
     * exactly like an item nobody sells. config/market.php has to stay
     * exhaustive, and this is what says so when a case is added.
     */
    public function test_every_rarity_has_a_seed_chance(): void
    {
        foreach (Rarity::cases() as $rarity) {
            $this->assertIsInt(Config::get("market.seed_chance.{$rarity->value}"), $rarity->value);
            $this->assertGreaterThan(0, $rarity->seedChance(), $rarity->value);
        }
    }

    public function test_rarity_defaults_to_common(): void
    {
        $item = Item::query()->create(['slug' => 'unspecified', 'container' => Item::CONTAINER_PHYSICAL]);

        $this->assertSame(Rarity::Common, $item->fresh()->rarity);
        $this->assertSame(0, $item->fresh()->seed_quantity);
    }

    public function test_a_missing_stock_row_reads_as_an_empty_shelf(): void
    {
        $item = $this->stocked(10);

        $this->assertSame(0, $item->stockOnHand());
    }
}
