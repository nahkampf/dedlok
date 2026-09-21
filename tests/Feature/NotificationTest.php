<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Events\SkillLevelReached;
use App\Models\Character;
use App\Models\Notification;
use App\Models\Skill;
use App\Models\User;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The inbox: what happened while nobody was looking.
 *
 * The test for whether something belongs in it is the same one the strings
 * file states — a flash message covers what the player just did, and this
 * covers what the world did on its own. So the senders asserted here are the
 * ones nobody pressed a button for: a clock crossing a level, and an unlock
 * that is the side effect of doing something else entirely.
 *
 * The other two live with their own fixtures: a finished read in ProbeTest, a
 * backdoor found in BackdoorTest.
 */
final class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['user_id' => $this->user->id, 'archetype' => 3],
        ));
    }

    private function notifier(): Notifier
    {
        return new Notifier;
    }

    // ---- writing one -------------------------------------------------------

    /**
     * ADDRESSED FROM EITHER SIDE, DELIVERED TO ONE. Every sender in the game
     * holds a character and every inbox belongs to an account.
     */
    public function test_a_message_from_a_character_lands_in_the_account_inbox(): void
    {
        $this->notifier()->send($this->character, Category::Training, 'skill_level', [
            'skill' => 'Hacking',
            'level' => 2,
        ]);

        $message = Notification::query()->sole();

        $this->assertSame($this->user->id, $message->user_id);
        $this->assertSame(Category::Training, $message->category);
        $this->assertSame('Hacking reached level 2.', $message->body);
        $this->assertNull($message->read_at, 'a new message is unread');
    }

    /**
     * THE SENTENCE IS STORED, NOT THE TEMPLATE. What a message names is
     * allowed to move, be renamed or be deleted afterwards — which is exactly
     * what a machine does — so the body has to survive its own subject.
     */
    public function test_the_body_is_rendered_once_and_kept(): void
    {
        $this->notifier()->send($this->character, Category::Cyberspace, 'backdoor_found', [
            'system' => 'Data Silo - Kurosawa 114',
        ]);

        config(['strings.notifications.messages.backdoor_found' => 'Something else entirely, :system.']);

        $this->assertStringContainsString(
            'Data Silo - Kurosawa 114',
            Notification::query()->sole()->body,
            'the message says what it said when it was written',
        );
    }

    public function test_marking_them_read_clears_the_count(): void
    {
        $this->notifier()->send($this->character, Category::Training, 'skill_level', ['skill' => 'A', 'level' => 1]);
        $this->notifier()->send($this->character, Category::Training, 'skill_level', ['skill' => 'B', 'level' => 1]);

        $this->assertSame(2, $this->notifier()->unreadFor($this->user));

        $this->assertSame(2, $this->notifier()->markAllRead($this->user));
        $this->assertSame(0, $this->notifier()->unreadFor($this->user));

        // The messages stay. Read is about the badge and nothing else.
        $this->assertSame(2, Notification::query()->count());
    }

    /** Somebody else's inbox is not counted, and not cleared. */
    public function test_an_inbox_belongs_to_one_account(): void
    {
        $stranger = User::factory()->create();

        $this->notifier()->send($this->user, Category::Achievement, 'achievement_unlocked', ['name' => 'X']);
        $this->notifier()->send($stranger, Category::Achievement, 'achievement_unlocked', ['name' => 'X']);

        $this->notifier()->markAllRead($this->user);

        $this->assertSame(0, $this->notifier()->unreadFor($this->user));
        $this->assertSame(1, $this->notifier()->unreadFor($stranger));
    }

    // ---- the screen and the rail -------------------------------------------

    public function test_the_screen_lists_the_newest_first(): void
    {
        $this->notifier()->send($this->character, Category::Training, 'skill_level', ['skill' => 'Older', 'level' => 1]);
        $this->notifier()->send($this->character, Category::Training, 'skill_level', ['skill' => 'Newer', 'level' => 2]);

        $response = $this->actingAs($this->user)->get(route('notifications.index'))->assertOk();

        $response->assertSeeTextInOrder(['Newer reached level 2.', 'Older reached level 1.']);
        $response->assertSeeText(Text::get('notifications.categories.training'));
    }

    public function test_an_empty_inbox_says_so(): void
    {
        $this->actingAs($this->user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSeeText(Text::get('notifications.empty'))
            ->assertDontSee(route('notifications.read'));
    }

    public function test_the_screen_marks_them_all_read(): void
    {
        $this->notifier()->send($this->character, Category::Training, 'skill_level', ['skill' => 'A', 'level' => 1]);

        $this->actingAs($this->user)
            ->from(route('notifications.index'))
            ->post(route('notifications.read'))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('status', Text::plural('notifications.marked', 1));

        $this->assertSame(0, $this->notifier()->unreadFor($this->user));
    }

    /**
     * THE BADGE IS THE POINT OF THE RAIL ITEM. A count nobody can see without
     * opening the screen is a count that does not do its job.
     */
    public function test_the_rail_carries_the_unread_count(): void
    {
        $this->notifier()->send($this->character, Category::Training, 'skill_level', ['skill' => 'A', 'level' => 1]);
        $this->notifier()->send($this->character, Category::Training, 'skill_level', ['skill' => 'B', 'level' => 1]);

        $this->actingAs($this->user)
            ->get(route('notifications.index'))
            ->assertSee('dock__count')
            ->assertSee('>2<', false);
    }

    public function test_an_empty_inbox_puts_nothing_in_the_rail(): void
    {
        $this->actingAs($this->user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('dock__count');
    }

    // ---- who sends them ----------------------------------------------------

    /**
     * THE FIRST LISTENER IN THE GAME. `SkillLevelReached` has been dispatched
     * since the queue was built with nothing subscribed to it; this is that
     * event finally arriving somewhere.
     */
    public function test_a_level_reached_by_the_clock_is_a_message(): void
    {
        $skill = Skill::factory()->create(['slug' => 'hacking', 'title' => 'Hacking']);

        event(new SkillLevelReached($this->character, $skill, 3, now()));

        $message = Notification::query()->sole();

        $this->assertSame(Category::Training, $message->category);
        $this->assertSame('Hacking reached level 3.', $message->body);
    }

    /**
     * An unlock is the side effect of doing something else, so there is no
     * screen about to carry the news.
     */
    public function test_an_unlocked_achievement_is_a_message(): void
    {
        (new Achievements)->record($this->character, Metric::SiphonCreds, 12_000);

        $message = Notification::query()->sole();

        $this->assertSame(Category::Achievement, $message->category);
        $this->assertStringContainsString(Text::get('achievements.name', ['id' => 2]), $message->body);
    }

    /** Crossing the line again is not a second message, and the index says so. */
    public function test_an_achievement_is_only_announced_once(): void
    {
        (new Achievements)->record($this->character, Metric::SiphonCreds, 12_000);
        (new Achievements)->record($this->character, Metric::SiphonCreds, 12_000);

        $this->assertSame(1, Notification::query()->count());
    }
}
