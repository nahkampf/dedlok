<?php

namespace Tests\Feature;

use App\Market\Rarity;
use App\Models\Item;
use App\Models\MarketStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The scheduled half of the market. The arithmetic is MarketSeedTest's
 * problem; what matters here is that the pass runs, writes, and says so.
 *
 * The command uses real dice, so everything asserted here is about common
 * items — the one rarity whose roll cannot fail.
 */
class SeedMarketCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stocks_the_market(): void
    {
        $item = Item::factory()->rarity(Rarity::Common)->restocks(7)->create();

        $this->artisan('market:seed')
            ->expectsOutputToContain('1 item(s) considered, 1 restocked')
            ->assertSuccessful();

        $this->assertSame(7, MarketStock::where('item_id', $item->id)->value('quantity'));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        Item::factory()->rarity(Rarity::Common)->restocks(7)->create();

        $this->artisan('market:seed --dry-run')
            ->expectsOutputToContain('dry run, nothing written')
            ->assertSuccessful();

        $this->assertDatabaseCount('market_stock', 0);
    }

    public function test_an_empty_catalogue_is_not_a_failure(): void
    {
        $this->artisan('market:seed')->assertSuccessful();

        $this->assertDatabaseCount('market_stock', 0);
    }

    /** The console goes nowhere on a schedule, so the log is the record. */
    public function test_the_summary_reaches_the_log(): void
    {
        Item::factory()->rarity(Rarity::Common)->restocks(7)->create();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'Market seed:'));

        $this->artisan('market:seed')->assertSuccessful();
    }

    public function test_a_dry_run_stays_out_of_the_log(): void
    {
        Item::factory()->rarity(Rarity::Common)->restocks(7)->create();

        Log::shouldReceive('info')->never();

        $this->artisan('market:seed --dry-run')->assertSuccessful();
    }
}
