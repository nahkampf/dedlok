<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Notification;
use App\Models\Skill;
use App\Models\User;
use App\Skills\QueueRefusal;
use App\Skills\TrainingQueue;
use App\Supporters\Supporters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InjectsSkills;
use Tests\TestCase;

/** Supporters: a flag on the account that earns a badge, an unlimited training queue, a place on the page and a message. */
final class SupportersTest extends TestCase
{
    use InjectsSkills, RefreshDatabase;

    private User $user;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['user_id' => $this->user->id, 'archetype' => 3]));
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user->fresh();
    }

    public function test_granting_sets_the_flag_and_says_thank_you_once_with_the_discord_link(): void
    {
        $this->assertTrue(Supporters::make()->grant($this->user));
        $this->assertFalse(Supporters::make()->grant($this->user->fresh()), 'already one: nothing changes and nothing is sent');

        $this->assertTrue($this->user->fresh()->isSupporter());
        $this->assertNotNull($this->user->fresh()->supporter_since);

        $message = Notification::query()->where('user_id', $this->user->id)->sole();
        $this->assertSame('Thank you for supporting Dedlok', $message->title);
        $this->assertStringContainsString(config('strings.discord.url'), $message->body);
    }

    public function test_revoking_takes_the_flag_and_sends_nothing(): void
    {
        Supporters::make()->grant($this->user);
        Notification::query()->delete();

        $this->assertTrue(Supporters::make()->revoke($this->user->fresh()));

        $this->assertFalse($this->user->fresh()->isSupporter());
        $this->assertNull($this->user->fresh()->supporter_since);
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_a_supporter_has_an_unlimited_training_queue_and_everybody_else_five(): void
    {
        $skill = Skill::factory()->create(['slug' => 'hacking']);
        $this->inject($this->character, $skill);

        $this->assertSame(TrainingQueue::LIMIT, TrainingQueue::limitFor($this->character->fresh()));

        Supporters::make()->grant($this->user);
        $character = $this->character->fresh();
        $this->assertNull(TrainingQueue::limitFor($character));

        // Five levels exist, so use five skills to get past the old limit of five.
        $skills = Skill::factory()->count(7)->create();
        foreach ($skills as $each) {
            $this->inject($character, $each);
            $this->assertNotInstanceOf(QueueRefusal::class, TrainingQueue::make()->enqueue($character, $each));
        }

        $this->assertSame(7, $character->trainingQueue()->count());
    }

    public function test_the_badge_is_on_the_characters_name_for_a_supporter_only(): void
    {
        $this->actingAs($this->user)->get(route('character.index'))->assertOk()->assertDontSee('Supporter');

        Supporters::make()->grant($this->user);

        $this->actingAs($this->user->fresh())->get(route('character.index'))->assertOk()->assertSee('tag tag--ember', false)->assertSee('Supporter');
    }

    public function test_the_page_lists_supporters_by_credit_name_or_handle_and_nobody_else(): void
    {
        $this->user->forceFill(['credit_name' => 'Ada Lovelace'])->save();
        Supporters::make()->grant($this->user);

        $second = User::factory()->create();
        Character::factory()->create(['user_id' => $second->id, 'handle' => 'plainhandle', 'archetype' => 3]);
        Supporters::make()->grant($second);

        $other = User::factory()->create(['credit_name' => 'Not A Supporter']);

        $this->get(route('supporters.index'))->assertOk()
            ->assertSeeText('Ada Lovelace')->assertSeeText('plainhandle')->assertDontSeeText('Not A Supporter')->assertDontSeeText($this->character->handle);
    }

    public function test_only_an_admin_can_make_a_supporter_and_the_screen_does_it(): void
    {
        $this->actingAs($this->user)->get(route('admin.supporters.index'))->assertForbidden();
        $this->actingAs($this->user)->put(route('admin.supporters.update', $this->user), ['supporter' => 1])->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.supporters.index'))->assertOk()->assertSee($this->user->email);
        $this->actingAs($admin)->put(route('admin.supporters.update', $this->user), ['supporter' => 1])->assertRedirect();
        $this->assertTrue($this->user->fresh()->isSupporter());

        $this->actingAs($admin)->put(route('admin.supporters.update', $this->user), ['supporter' => 0])->assertRedirect();
        $this->assertFalse($this->user->fresh()->isSupporter());
    }

    public function test_the_flag_cannot_be_set_through_the_model_fillable_path(): void
    {
        $this->user->fill(['is_supporter' => true])->save();

        $this->assertFalse($this->user->fresh()->isSupporter());
    }

    public function test_the_console_command_grants_and_revokes(): void
    {
        $this->artisan('supporter:grant', ['email' => $this->user->email])->expectsOutputToContain('is now a supporter')->assertSuccessful();
        $this->assertTrue($this->user->fresh()->isSupporter());

        $this->artisan('supporter:grant', ['email' => $this->user->email, '--revoke' => true])->expectsOutputToContain('no longer a supporter')->assertSuccessful();
        $this->assertFalse($this->user->fresh()->isSupporter());
    }
}
