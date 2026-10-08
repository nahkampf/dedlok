<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Cyberspace\Extractor;
use App\Models\Character;
use App\Models\Notification;
use App\Models\System;
use App\Models\SystemContent;
use App\Models\User;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * What the account has done, as opposed to what the character has.
 *
 * The counters are the durable half and the unlocks are a line drawn across
 * them, which is the whole reason they are two tables: an achievement added
 * tomorrow reads a total that has been accumulating since today.
 */
final class AchievementTest extends TestCase
{
    use RefreshDatabase;

    private Achievements $achievements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->achievements = new Achievements;
    }

    private function character(): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3, 'creds' => 0],
        ));
    }

    private function unlocked(Character $character): array
    {
        return $this->achievements->unlockedFor(User::query()->findOrFail($character->user_id));
    }

    // ---- the wiring ---------------------------------------------------------

    /** The one place creds come off a system is the one place they are counted. */
    public function test_raiding_a_system_counts_towards_the_million(): void
    {
        $system = System::factory()->at(8, 8)->grade(1)->create(['address' => 'DEADBEEF']);
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 1_000_000]);
        $character = $this->character();

        (new Extractor)->extract($character, 'DEADBEEF');

        $this->assertSame(1_000_000, $this->achievements->progressFor($character, Metric::RaidCreds));
        $this->assertContains(1, $this->unlocked($character));
    }

    // ---- counting -----------------------------------------------------------

    /** A total accumulates, and the line is crossed by the event that crosses it. */
    public function test_a_threshold_is_reached_by_accumulation(): void
    {
        $character = $this->character();

        $this->achievements->record($character, Metric::SiphonCreds, 4_000);
        $this->achievements->record($character, Metric::SiphonCreds, 4_000);

        $this->assertSame([], $this->unlocked($character));

        $this->achievements->record($character, Metric::SiphonCreds, 4_000);

        $this->assertSame(12_000, $this->achievements->progressFor($character, Metric::SiphonCreds));
        $this->assertSame([2], $this->unlocked($character));
    }

    /** Crossing it again is not a second achievement. */
    public function test_an_achievement_is_only_unlocked_once(): void
    {
        $character = $this->character();

        $this->achievements->record($character, Metric::SystemsBurned, 100);
        $this->achievements->record($character, Metric::SystemsBurned, 100);

        $this->assertSame([4], $this->unlocked($character));
        $this->assertSame(200, $this->achievements->progressFor($character, Metric::SystemsBurned));
    }

    /** Nothing happened is not something to count. */
    public function test_nothing_is_not_counted(): void
    {
        $character = $this->character();

        $this->achievements->record($character, Metric::IceDefeated, 0);

        $this->assertSame(0, $this->achievements->progressFor($character, Metric::IceDefeated));
    }

    // ---- a state rather than an event ---------------------------------------

    /** A chain is the longest ever held, never the sum of every one built. */
    public function test_a_state_keeps_the_highest_ever_seen(): void
    {
        $character = $this->character();

        $this->achievements->reach($character, Metric::RoutingChain, 6);
        $this->achievements->reach($character, Metric::RoutingChain, 3);

        $this->assertSame(6, $this->achievements->progressFor($character, Metric::RoutingChain));
        $this->assertSame([], $this->unlocked($character));

        $this->achievements->reach($character, Metric::RoutingChain, 10);

        $this->assertSame([3], $this->unlocked($character));
    }

    // ---- the reason there are two tables ------------------------------------

    /** A threshold added over a total that passed it long ago is won on the next movement. */
    public function test_a_new_achievement_reads_the_total_that_was_already_there(): void
    {
        $character = $this->character();

        $this->achievements->record($character, Metric::IceDefeated, 500);

        Config::set('achievements.unlocks.99', ['metric' => 'ice_defeated', 'threshold' => 250]);

        $this->achievements->record($character, Metric::IceDefeated, 1);

        $this->assertContains(99, $this->unlocked($character));
    }

    // ---- what a page may show -----------------------------------------------

    /** An open achievement is listed from the first day: it is something to go and do. */
    public function test_an_open_achievement_is_listed_before_it_is_won(): void
    {
        $character = $this->character();
        $user = User::query()->findOrFail($character->user_id);

        Config::set('achievements.unlocks', [
            101 => ['metric' => 'flatlines', 'threshold' => 5, 'secret' => false],
        ]);

        $listing = $this->achievements->listableFor($user);

        $this->assertArrayHasKey(101, $listing);
        $this->assertFalse($listing[101]['unlocked']);
    }

    /** A secret one is not there at all until it is won, and then it is ordinary. */
    public function test_a_secret_achievement_appears_only_once_it_is_unlocked(): void
    {
        $character = $this->character();
        $user = User::query()->findOrFail($character->user_id);

        Config::set('achievements.unlocks', [
            102 => ['metric' => 'flatlines', 'threshold' => 2, 'secret' => true],
        ]);

        $this->assertSame([], $this->achievements->listableFor($user));

        $this->achievements->record($character, Metric::Flatlines, 2);

        $this->assertTrue($this->achievements->listableFor($user)[102]['unlocked']);
    }

    /** Absent means visible — a new entry has to say it is secret. */
    public function test_an_entry_with_no_flag_is_not_secret(): void
    {
        $user = User::query()->findOrFail($this->character()->user_id);

        Config::set('achievements.unlocks', [103 => ['metric' => 'flatlines', 'threshold' => 1]]);

        $this->assertFalse($this->achievements->listableFor($user)[103]['secret']);
    }

    // ---- what a player reads ------------------------------------------------

    /**
     * EVERY ACHIEVEMENT HAS A DESCRIPTION, AND IT FITS IN 255 CHARACTERS.
     *
     * `Text::get()` returns the key when a string is missing, so a forgotten
     * description is not an error — it is a blank card
     * on a card, which is exactly the kind of failure nothing catches. The
     * length is the other half: nothing here is a column today, but one that
     * will not fit a varchar(255) is one that has to be rewritten the day this
     * catalogue becomes a table.
     */
    public function test_every_achievement_has_a_name_and_a_description_that_would_fit_a_column(): void
    {
        foreach ((array) Config::get('achievements.unlocks') as $id => $unlock) {
            $this->assertNotSame('', trim((string) ($unlock['name'] ?? '')), "achievement {$id} has no name");
            $this->assertLessThanOrEqual(120, mb_strlen($unlock['name']), "achievement {$id} has a name too long for a message title");
            $this->assertNotSame('', trim((string) ($unlock['description'] ?? '')), "achievement {$id} has no description");
            $this->assertLessThanOrEqual(255, mb_strlen($unlock['description']), "achievement {$id} is too long for a column");
        }
    }

    /** It is the account's record, so it outlives the character who earned it. */
    public function test_the_record_belongs_to_the_account(): void
    {
        $character = $this->character();
        $user = User::query()->findOrFail($character->user_id);

        $this->achievements->record($character, Metric::SystemsBurned, 100);
        $character->delete();

        $this->assertSame([4], $this->achievements->unlockedFor($user));
    }

    public function test_an_unlock_sends_the_message_with_its_link_and_the_page_shows_the_share(): void
    {
        $character = $this->character();
        $this->achievements->record($character, Metric::SystemsBurned, 100);

        $message = Notification::where('user_id', $character->user_id)->first();
        $this->assertSame('Achievement unlocked!', $message->title);
        $name = (string) config('achievements.unlocks.4.name');
        $this->assertSame("You unlocked the achievement {$name}!", $message->body);
        $this->assertSame('achievements.index', $message->details['link']['route']);

        $user = User::findOrFail($character->user_id);
        $this->actingAs($user)->get(route('notifications.index'))
            ->assertSee('You unlocked the <a href="'.route('achievements.index').'">achievement</a> '.e($name).'!', false);

        // One player, who has it: 100.00%; the others, 0.00%.
        $this->actingAs($user)->get(route('achievements.index'))->assertSee('100.00% of players')->assertSee('0.00% of players');
    }
}
