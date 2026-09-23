<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The front page: NOTICE.md in a wide card, and the two places beneath it.
 */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_notice_is_rendered_from_markdown_with_html_stripped(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        // The first heading moves up into the accordion's summary.
        $this->assertStringContainsString('<details class="panel grid__wide">', $html);
        $this->assertStringContainsString('class="panel__teaser"', $html);
        $this->assertStringContainsString('class="panel__bd prose"', $html);
        $this->assertStringNotContainsString('<h1>', $html);
    }

    public function test_markdown_carrying_html_loses_it(): void
    {
        $out = Str::markdown("# Hi\n\n<script>alert(1)</script>", ['html_input' => 'strip']);

        $this->assertStringNotContainsString('<script', $out);
    }

    public function test_the_two_places_are_beneath_it(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('images/logo-560.png', false)
            ->assertSee('320w', false)
            ->assertSee('class="grid grid--pair"', false)
            ->assertSee('grid__wide', false)
            ->assertSeeText(Text::get('home.places.city'))
            ->assertSeeText(Text::get('home.places.cyberspace'));
    }

    public function test_a_missing_picture_renders_no_image(): void
    {
        foreach (['images/city.jpg', 'images/cyberspace.jpg'] as $image) {
            if (is_file(public_path($image))) {
                $this->markTestSkipped("{$image} exists, so its absence cannot be tested here.");
            }
        }

        // The wordmark above the cards is an <img> of its own, so this asks
        // about the two places by name rather than about pictures in general.
        $page = $this->get(route('home'))->assertOk();

        foreach (['images/city.jpg', 'images/cyberspace.jpg'] as $image) {
            $page->assertDontSee($image, false);
        }
    }

    public function test_the_tier_scale_is_two_steps_and_the_words_match_it(): void
    {
        // A tier with no word renders its own key, and a word with no tier is
        // a name for a step nothing can be on. Widening the scale means
        // widening both, so this is the test that says so out loud.
        $this->assertSame([1, 2], array_keys(config('strings.items.tiers')));
        $this->assertSame(2, Item::MAX_TIER);
    }

    public function test_the_shared_item_vocabulary_holds_nothing_per_item(): void
    {
        // What is left in config is the vocabulary every item shares. Anything
        // that belongs to ONE item belongs on its row, and a per-slug entry
        // here would be a second source of truth for a word the row already has.
        $this->assertSame(
            ['kinds', 'tiers', 'rarities', 'skillbook'],
            array_keys(config('strings.items'))
        );
    }
}
