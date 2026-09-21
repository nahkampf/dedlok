<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Models\Character;
use App\Support\Image;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The one screen that belongs to the account rather than the character.
 *
 * What is earned sits above what is not, and a secret achievement is not on
 * the page at all until it is won — a list that showed a locked secret as a
 * blank would be telling the player it exists, which is the one thing the
 * flag is for.
 */
final class AchievementPageTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));

        // A catalogue of three, over one metric, so a test can win exactly
        // what it means to win. Named out of order on purpose — see below.
        Config::set('achievements.unlocks', [
            1 => ['metric' => 'flatlines', 'threshold' => 1, 'secret' => false],
            2 => ['metric' => 'flatlines', 'threshold' => 50, 'secret' => false],
            3 => ['metric' => 'flatlines', 'threshold' => 2, 'secret' => true],
        ]);
    }

    private function visit()
    {
        return $this->actingAs($this->character->user)->get(route('achievements.index'));
    }

    private function label(int $id): string
    {
        return Text::get('achievements.name', ['id' => $id]);
    }

    public function test_the_rail_leads_here(): void
    {
        $response = $this->actingAs($this->character->user)->get(route('inventory.index'));

        $response->assertSee(route('achievements.index'), false);
        $response->assertSee(Text::get('ui.nav.achievements'));
    }

    /** Both headers are there whether or not either list has anything in it. */
    public function test_an_empty_account_is_told_what_is_out_there(): void
    {
        $response = $this->visit();

        $response->assertOk();
        $response->assertSee(Text::get('achievements.gained'));
        $response->assertSee(Text::get('achievements.remaining'));
        $response->assertSee(Text::get('achievements.none'));

        // The unwon open one is listed; the unwon SECRET one is not there at
        // all, which is the whole point of the flag.
        $response->assertSee($this->label(2));
        $response->assertDontSee($this->label(3));

        // The description is on a LOCKED card too — it is the only place a
        // player finds out what one would take.
        $response->assertSee(Text::get('achievements.description.2'));
    }

    /** Earned first, and the rest underneath. */
    public function test_what_is_won_sits_above_what_is_not(): void
    {
        (new Achievements)->record($this->character, Metric::Flatlines);

        $response = $this->visit();

        $response->assertSeeInOrder([
            Text::get('achievements.gained'),
            $this->label(1),
            Text::get('achievements.remaining'),
            $this->label(2),
        ]);
    }

    /** A secret one is ordinary the moment it is won. */
    public function test_a_secret_achievement_shows_up_once_it_is_earned(): void
    {
        (new Achievements)->record($this->character, Metric::Flatlines, 2);

        $this->visit()->assertSee($this->label(3));
    }

    /**
     * NO PICTURE OF ITS OWN IS THE ORDINARY CASE, and the folder's default is
     * what stands in — the same convention every other picture in the game is
     * found by, so an achievement that gets drawn later needs no code.
     */
    public function test_a_card_falls_back_to_the_default_picture(): void
    {
        $response = $this->visit();

        $response->assertSee(Image::card('achievements', 2), false);
        $response->assertSee('images/achievements/_default.jpg', false);

        // A badge, not a scene: the square variant and its intrinsic size.
        $response->assertSee('entity-image--sq', false);
        $response->assertSee('width="128" height="128"', false);
    }

    /**
     * ALPHABETICALLY, which for the placeholder names means naturally: the
     * ids run past nine, and 10 sorting between 1 and 2 is the bug this
     * pins.
     */
    public function test_the_lists_are_sorted_by_name(): void
    {
        Config::set('achievements.unlocks', [
            2 => ['metric' => 'flatlines', 'threshold' => 50, 'secret' => false],
            10 => ['metric' => 'flatlines', 'threshold' => 50, 'secret' => false],
            1 => ['metric' => 'flatlines', 'threshold' => 50, 'secret' => false],
        ]);

        $this->visit()->assertSeeInOrder([$this->label(1), $this->label(2), $this->label(10)]);
    }
}
