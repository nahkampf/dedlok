<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Skill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The landing page's showcase reads the items table.
 *
 * It is the one public page that names items, so what matters is that it reads
 * definitions only — never anyone's inventory — and that every word on a card
 * still comes from lang rather than the row.
 */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_showcase_comes_from_the_items_table(): void
    {
        Item::factory()->physical()->tier(5)->create(['slug' => 'sandevistan_mk5']);

        $this->get(route('home'))
            ->assertOk()
            // The name and the tier word are both lang lookups keyed off the row.
            ->assertSee(__('items.sandevistan_mk5.name'))
            ->assertSee(__('items.tiers.5'))
            ->assertSee('item-card t5', false);
    }

    public function test_it_shows_the_three_rarest_and_no_more(): void
    {
        foreach ([1, 2, 3, 4, 5] as $tier) {
            Item::factory()->physical()->tier($tier)->create(['slug' => "gear_{$tier}"]);
        }

        $html = $this->get(route('home'))->assertOk()->getContent();

        // The tier class is what separates a card from its own children,
        // which all share the item-card prefix.
        $this->assertSame(3, substr_count($html, 'class="item-card t'));

        foreach (['t5', 't4', 't3'] as $shown) {
            $this->assertStringContainsString('item-card '.$shown, $html);
        }

        foreach (['t2', 't1'] as $hidden) {
            $this->assertStringNotContainsString('item-card '.$hidden, $html);
        }
    }

    public function test_skillbooks_stay_off_the_landing_page(): void
    {
        $skill = Skill::factory()->create(['slug' => 'hacking', 'title' => 'Hacking']);

        // Tier 5, so ordering alone would put it first if it were eligible.
        Item::factory()->skillbookFor($skill)->tier(5)->create();
        Item::factory()->physical()->tier(1)->create(['slug' => 'scrap_alloy']);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee(__('items.skillbook.name', ['skill' => $skill->title]))
            ->assertSee(__('items.scrap_alloy.name'));
    }

    public function test_an_unseeded_catalogue_says_so_instead_of_rendering_nothing(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(__('items.catalogue_empty'));
    }

    public function test_no_item_carries_its_own_tier_name(): void
    {
        // The tier number on the row decides the word. A per-item 'tier' key
        // would be a second source of truth, and this is what deletes it.
        foreach (require lang_path('en/items.php') as $key => $value) {
            if (is_array($value) && $key !== 'tiers') {
                $this->assertArrayNotHasKey('tier', $value, "items.{$key} still names its own tier");
            }
        }
    }
}
