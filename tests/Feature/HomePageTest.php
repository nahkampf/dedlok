<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Skill;
use App\Support\Text;
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
        Item::factory()->physical()->tier(2)->create([
            'slug' => 'sandevistan_mk5',
            'name' => 'Sandevistan MK.V',
        ]);

        $this->get(route('home'))
            ->assertOk()
            // The name is on the row; only the tier word is still shared.
            ->assertSee('Sandevistan MK.V')
            ->assertSee(Text::get('items.tiers.2'))
            ->assertSee('item-card t2', false);
    }

    public function test_it_shows_the_three_highest_tiers_and_no_more(): void
    {
        // Two tiers means the showcase is mostly deciding ties, so the slug
        // order inside a tier is as much of the contract as the tier order.
        Item::factory()->physical()->tier(2)->create(['slug' => 'gear_b', 'name' => 'Gear B']);
        Item::factory()->physical()->tier(2)->create(['slug' => 'gear_a', 'name' => 'Gear A']);
        Item::factory()->physical()->tier(1)->create(['slug' => 'gear_c', 'name' => 'Gear C']);
        Item::factory()->physical()->tier(1)->create(['slug' => 'gear_d', 'name' => 'Gear D']);

        $html = $this->get(route('home'))->assertOk()->getContent();

        // The tier class is what separates a card from its own children,
        // which all share the item-card prefix.
        $this->assertSame(3, substr_count($html, 'class="item-card t'));

        foreach (['Gear A', 'Gear B', 'Gear C'] as $shown) {
            $this->assertStringContainsString($shown, $html);
        }

        $this->assertStringNotContainsString('Gear D', $html);
    }

    public function test_the_tier_scale_is_two_steps_and_the_words_match_it(): void
    {
        // A tier with no word renders its own key, and a word with no tier is
        // a name for a step nothing can be on. Widening the scale means
        // widening both, so this is the test that says so out loud.
        $this->assertSame([1, 2], array_keys(config('strings.items.tiers')));
        $this->assertSame(2, Item::MAX_TIER);
    }

    public function test_skillbooks_stay_off_the_landing_page(): void
    {
        $skill = Skill::factory()->create(['slug' => 'hacking', 'title' => 'Hacking']);

        // Top tier, so ordering alone would put it first if it were eligible.
        Item::factory()->skillbookFor($skill)->tier(2)->create();
        Item::factory()->physical()->tier(1)->create([
            'slug' => 'scrap_alloy',
            'name' => 'Scrap Alloy',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee(Text::get('items.skillbook.name', ['skill' => $skill->title]))
            ->assertSee('Scrap Alloy');
    }

    public function test_an_unseeded_catalogue_says_so_instead_of_rendering_nothing(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(Text::get('items.catalogue_empty'));
    }

    public function test_the_shared_item_vocabulary_holds_nothing_per_item(): void
    {
        // What is left in config is the vocabulary every item shares. Anything
        // that belongs to ONE item belongs on its row, and a per-slug entry
        // here would be a second source of truth for a word the row already has.
        $this->assertSame(
            ['tiers', 'rarities', 'skillbook', 'stat', 'catalogue_empty'],
            array_keys(config('strings.items'))
        );
    }
}
