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

        // Skills, implants and achievements became tabs of the character
        // sheet, so one rail item stands where four did — and the rail is
        // where you GO rather than a list of every screen there is.
        foreach (['character', 'stash', 'deck'] as $section) {
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
            // The exact figures live in the dial's title and aria-label; the
            // ring shows the ratio and the current value shows inside it.
            $response->assertSee(Text::get('ui.hud.aria', [
                'label' => Text::get("character.abbreviations.{$statId}"),
                'current' => $character->{$resource},
                'max' => $character->maximumFor($resource),
            ]));
        }

        $response->assertSee(Text::get('creds.name'));
        $response->assertSee('48 210');
    }

    /**
     * A ring rather than a bar, because the bar is where there is no room for
     * one. Same contract either way: --v is the ratio and ui-kit.js decides
     * what "low" is from the same threshold.
     */
    public function test_each_resource_is_a_dial_and_the_money_is_not(): void
    {
        $character = $this->character(['creds' => 500]);

        $response = $this->actingAs($character->user)->get(route('inventory.index'));

        $this->assertSame(
            count(Character::RESOURCES) + 1,
            substr_count($response->getContent(), 'class="dial" role="meter"'),
            'one dial per resource plus fatigue, and creds is not one — see Character::RESOURCES'
        );

        $response->assertSee('data-hot-low', false);
    }

    /**
     * The word costs more width than the bar can spare, so the generic
     * currency sign carries it. U+00A4 is in Latin-1, which the font subset
     * covers by construction — the name stays for a screen reader.
     */
    public function test_the_money_shows_a_glyph_and_keeps_its_name_for_a_reader(): void
    {
        $character = $this->character(['creds' => 500]);

        $this->actingAs($character->user)
            ->get(route('inventory.index'))
            ->assertSee('&#164;', false)
            ->assertSee('<span class="sr-only">'.Text::get('creds.name').'</span>', false);
    }

    /** The count is what the bar has room for. The modal says the rest. */
    public function test_the_bar_carries_a_process_count_that_opens_the_list(): void
    {
        $character = $this->character();

        $this->actingAs($character->user)
            ->get(route('inventory.index'))
            ->assertSee('data-open="#processes"', false)
            ->assertSee('<dialog id="processes"', false)
            ->assertSee(Text::get('programs.running_none'));
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
            ->assertDontSee('class="dial"', false)
            ->assertDontSee('data-open="#processes"', false);
    }
}
