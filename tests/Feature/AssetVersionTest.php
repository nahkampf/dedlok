<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Support\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The kit's URLs carry the file's own modification time.
 *
 * There is no bundler, so nothing renames a file when its contents change, and
 * Apache sends no Cache-Control for public/assets. Without the stamp a browser
 * may hold ui-kit.js indefinitely — which shipped once as new markup driven by
 * week-old behaviour, and reads as a screen that simply does nothing.
 */
class AssetVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_asset_is_stamped_with_its_mtime(): void
    {
        $path = 'assets/js/ui-kit.js';

        $this->assertSame(
            asset($path).'?v='.filemtime(public_path($path)),
            Asset::url($path)
        );
    }

    public function test_a_missing_asset_keeps_its_bare_url(): void
    {
        // A 404 that says so beats one dressed up with a query string.
        $this->assertSame(
            asset('assets/js/nothing-here.js'),
            Asset::url('assets/js/nothing-here.js')
        );
    }

    public function test_both_layouts_version_the_kit(): void
    {
        $css = Asset::url('assets/css/ui-kit.css');
        $js = Asset::url('assets/js/ui-kit.js');

        // The guest layout is what a signed-out player loads first, so a stale
        // copy cached there follows them into the game.
        $this->get(route('login'))
            ->assertSee($css, false)
            ->assertSee($js, false);

        $character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));

        $this->actingAs($character->user)
            ->get(route('inventory.index'))
            ->assertSee($css, false)
            ->assertSee($js, false);
    }
}
