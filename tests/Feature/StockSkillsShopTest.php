<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Location;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `city:stock-skills` lists every on-market skill's imprint in a shop at list price, and only adds. */
final class StockSkillsShopTest extends TestCase
{
    use RefreshDatabase;

    private function skill(string $slug, bool $onMarket): Item
    {
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.skills.store'), [
            'slug' => $slug, 'title' => ucfirst($slug), 'description' => 'x', 'category' => 'intrusion', 'rank' => 4,
            'primary_attribute' => 'intellect', 'secondary_attribute' => 'focus', 'base_cost' => 900,
            'on_market' => $onMarket ? '1' : '0',
        ])->assertSessionHasNoErrors();

        return Item::query()->where('use_skill_id', Skill::query()->where('slug', $slug)->value('id'))->sole();
    }

    public function test_every_on_market_skill_is_listed_at_list_price_and_a_rerun_adds_nothing(): void
    {
        $listed = $this->skill('lockpicking', true);
        $hidden = $this->skill('forgery', false);
        $place = Location::query()->firstOrFail();

        $this->artisan('city:stock-skills', ['location' => $place->slug])->assertSuccessful();

        $row = $place->shopItems()->where('item_id', $listed->id)->sole();
        $this->assertNull($row->price, 'no price of its own: it sells for the imprint\'s base cost');
        $this->assertNull($row->max_stock, 'unlimited');
        $this->assertSame(900, $row->unitPrice());
        $this->assertFalse($place->shopItems()->where('item_id', $hidden->id)->exists(), 'off the market is not stocked');

        $count = $place->shopItems()->count();
        $this->artisan('city:stock-skills', ['location' => $place->slug])->assertSuccessful();
        $this->assertSame($count, $place->shopItems()->count());
    }

    public function test_a_price_of_its_own_on_a_skill_listing_is_put_back_to_list_price(): void
    {
        $listed = $this->skill('lockpicking', true);
        $place = Location::query()->firstOrFail();
        $other = Item::factory()->create(['base_cost' => 50]);
        $place->shopItems()->create(['item_id' => $listed->id, 'price' => 5, 'max_stock' => null, 'stock' => 0, 'restock_amount' => 0]);
        $place->shopItems()->create(['item_id' => $other->id, 'price' => 7, 'max_stock' => null, 'stock' => 0, 'restock_amount' => 0]);

        $this->artisan('city:stock-skills', ['location' => $place->slug])->assertSuccessful();

        $this->assertNull($place->shopItems()->where('item_id', $listed->id)->value('price'));
        $this->assertSame(7, $place->shopItems()->where('item_id', $other->id)->value('price'), 'only skills follow list price');
    }

    public function test_with_no_argument_it_stocks_the_configured_shop_and_is_quiet_when_there_is_none(): void
    {
        $this->skill('lockpicking', true);
        $place = Location::query()->firstOrFail();
        config(['city.skills_shop' => 'no-such-place']);

        $this->artisan('city:stock-skills', ['--quiet-when-idle' => true])->assertSuccessful();

        config(['city.skills_shop' => $place->slug]);
        $this->artisan('city:stock-skills')->assertSuccessful();
        $this->assertGreaterThan(0, $place->shopItems()->count());
    }

    public function test_an_unknown_location_fails(): void
    {
        $this->artisan('city:stock-skills', ['location' => 'nowhere'])->assertFailed();
    }
}
