<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The page frame: the rail on the left and the bar across the top.
 *
 * The split is the point. The rail holds everywhere you can go and the one
 * thing you can do to your session; the bar holds numbers and nothing else,
 * which is what makes all three of them fit across a 320px phone.
 */
class ShellTest extends TestCase
{
    use RefreshDatabase;

    private function character(array $attributes = []): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
            $attributes,
        ));
    }

    public function test_a_signed_in_player_gets_the_rail(): void
    {
        $character = $this->character();

        $response = $this->actingAs($character->user)->get(route('inventory.index'));

        $response->assertSee('class="dock"', false);
        $response->assertSee('shell--docked', false);

        // Three destinations, and only three. Run and Contracts were a link to
        // the landing page and a link to nothing; the mark in the bar still
        // goes home, so removing the first cost no way of getting there.
        foreach (['stash', 'deck', 'skills'] as $section) {
            $response->assertSee(Text::get("ui.nav.{$section}"));
        }

        // The rail links to deck.index and never to a row: a nav item that
        // appears and disappears with your loadout is one nobody trusts.
        $response->assertSee(route('deck.index'), false);

        $response->assertDontSee(Text::get('ui.nav.contracts'));

        // The way in where there is no pointer to hover with.
        $response->assertSee('data-dock-toggle', false);

        // Leaving is a POST, so it is a button in the rail rather than a link.
        $response->assertSee(route('logout'), false);

        // The mark stays in the bar whoever is looking; only the wordmark
        // beside it is responsive, and that is CSS rather than markup.
        $response->assertSee('class="brand"', false);
        $response->assertSee('class="brand__name"', false);
    }

    public function test_the_current_section_is_marked_once(): void
    {
        $character = $this->character();

        $response = $this->actingAs($character->user)->get(route('inventory.index'));

        $this->assertSame(1, substr_count($response->getContent(), 'aria-current="page"'));
    }

    public function test_the_bar_carries_both_resources_and_the_money(): void
    {
        $character = $this->character(['creds' => 48210]);
        $character->update([
            'current_hitpoints' => 7,
            'current_synaptic_integrity' => 3,
        ]);

        $response = $this->actingAs($character->user)->get(route('inventory.index'));

        foreach (Character::RESOURCES as $resource => $statId) {
            $response->assertSee(Text::get("character.abbreviations.{$statId}"));
            $response->assertSee(Text::get('ui.hud.ratio', [
                'current' => $character->{$resource},
                'max' => $character->maximumFor($resource),
            ]));
        }

        $response->assertSee(Text::get('creds.name'));
        $response->assertSee('48 210');
    }

    public function test_the_money_has_no_bar_because_it_has_no_maximum(): void
    {
        $character = $this->character(['creds' => 500]);

        $response = $this->actingAs($character->user)->get(route('inventory.index'));

        // One meter per resource, and creds is not one — see Character::RESOURCES.
        $this->assertSame(
            count(Character::RESOURCES),
            substr_count($response->getContent(), 'class="meter" role="meter"')
        );
    }

    public function test_a_guest_gets_no_rail_and_keeps_the_brand(): void
    {
        $response = $this->get(route('home'));

        $response->assertDontSee('class="dock"', false);
        $response->assertDontSee('shell--docked', false);
        $response->assertSee('class="brand"', false);
        $response->assertSee(Text::get('auth.login'));
    }

    public function test_the_admin_door_is_only_visible_to_an_admin(): void
    {
        $character = $this->character();

        $this->actingAs($character->user)
            ->get(route('inventory.index'))
            ->assertDontSee(route('admin.index'), false);

        $admin = $this->character();
        $admin->user->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin->user->fresh())
            ->get(route('inventory.index'))
            ->assertSee(route('admin.index'), false);
    }

    public function test_a_signed_in_account_with_no_character_still_renders(): void
    {
        // An admin who has never played. The rail is theirs; the bar has
        // nothing to show and shows nothing rather than a row of zeroes.
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('class="dock"', false)
            ->assertDontSee('class="gauge"', false);
    }
}
