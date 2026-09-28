<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Bounties;
use App\Models\Character;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\Category;
use App\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The first thing heat has ever done.
 *
 * It has been a number that only went up since the trace was built — one point
 * a completed trace, two a burn — and read by nothing. Now it decides how
 * likely it is that somebody out there thinks you are worth money.
 *
 * WHAT A HIT IS HAS NOT BEEN BUILT. There is no bounty row, no hunter and no
 * price; what lands is a notification saying the word is out. So what is
 * asserted here is the curve, who is rolled at all, and that the message
 * arrives — and nothing about consequences, because there are none yet.
 */
final class BountyTest extends TestCase
{
    use RefreshDatabase;

    private function bounties(int $die = 1): Bounties
    {
        return Bounties::fromConfig(static fn (int $min, int $max): int => $die);
    }

    private function character(int $heat): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3, 'heat' => $heat],
        ));
    }

    // ---- the curve ---------------------------------------------------------

    /**
     * NOTHING TO ANSWER FOR IS NOT A FLOOR. A floor is what an attempt keeps;
     * a character who has never been traced is not attempting anything and
     * attracts nobody.
     */
    public function test_no_heat_is_no_chance_at_all(): void
    {
        $this->assertSame(0.0, $this->bounties()->chanceFor(0));
    }

    /** Under the threshold it is the uncertainty floor and nothing more. */
    public function test_a_little_heat_is_the_floor(): void
    {
        $floor = (float) Config::get('checks.certainty_floor');

        foreach ([1, 2, 3, 4] as $heat) {
            $this->assertSame($floor, $this->bounties()->chanceFor($heat), "heat {$heat}");
        }
    }

    /** And from there it climbs in a straight line to certain. */
    public function test_it_climbs_to_certainty(): void
    {
        $bounties = $this->bounties();
        $floor = (float) Config::get('checks.certainty_floor');

        $this->assertSame($floor, $bounties->chanceFor(5), 'the ramp starts at the floor');
        $this->assertEqualsWithDelta(0.4782, $bounties->chanceFor(50), 0.0005, 'half way');
        $this->assertSame(1.0, $bounties->chanceFor(100));
        $this->assertSame(1.0, $bounties->chanceFor(400), 'and stays there');
    }

    /**
     * CERTAIN IS ALLOWED HERE, and it is the one place in the game where it
     * is. This is not a check — nobody is attempting anything and there is
     * nothing to be good at — so the rule that no check resolves to a
     * certainty has nothing to say about it.
     */
    public function test_a_hundred_points_fires_on_the_worst_roll_there_is(): void
    {
        $character = $this->character(100);

        $this->assertSame([$character->id], array_map(
            static fn (Character $marked): int => $marked->id,
            $this->bounties(die: 10_000)->tonight(),
        ));
    }

    /** And the floor fires only on a roll inside it. */
    public function test_the_floor_is_the_floor(): void
    {
        $this->character(4);

        // 0.0085 of ten thousand is 85, and the roll is inside the bound.
        $this->assertCount(1, $this->bounties(die: 85)->tonight());
        $this->assertCount(0, $this->bounties(die: 86)->tonight());
    }

    // ---- who is rolled -----------------------------------------------------

    public function test_a_character_with_no_heat_is_never_rolled(): void
    {
        $this->character(0);

        $this->assertSame([], $this->bounties(die: 1)->tonight());
        $this->assertSame(0, Notification::query()->count());
    }

    // ---- what lands --------------------------------------------------------

    public function test_the_word_goes_out_as_a_message(): void
    {
        $character = $this->character(50);

        $this->bounties(die: 1)->tonight();

        $message = Notification::query()->sole();

        $this->assertSame($character->user_id, $message->user_id);
        $this->assertSame(Category::Street, $message->category);
        $this->assertStringContainsString('price on your head', $message->body);
    }

    /**
     * WORD THAT IS ALREADY OUT IS NOT NEWS. At a hundred heat the roll is
     * certain, and without a memory that was the same sentence every morning.
     */
    public function test_it_does_not_say_so_again_the_next_night(): void
    {
        $character = $this->character(100);

        $this->bounties(die: 10_000)->tonight();
        $this->travel(1)->days();

        $this->assertSame([], $this->bounties(die: 10_000)->tonight());
        $this->assertSame(1, Notification::query()->count());
        $this->assertNotNull($character->fresh()->bounty_word_at);
    }

    /** And once the cooldown has run, it is rolled like anybody else. */
    public function test_it_is_rolled_again_once_the_cooldown_runs_out(): void
    {
        $this->character(100);
        $days = (int) Config::get('cyberspace.heat.bounty.cooldown_days');

        $this->bounties(die: 10_000)->tonight();
        $this->travel($days - 1)->days();
        $this->assertSame([], $this->bounties(die: 10_000)->tonight(), 'a night short');

        $this->travel(1)->days();
        $this->assertCount(1, $this->bounties(die: 10_000)->tonight());
        $this->assertSame(2, Notification::query()->count());
    }

    /** A roll that misses writes nothing, so it starts no cooldown. */
    public function test_a_miss_leaves_no_memory(): void
    {
        $character = $this->character(4);

        $this->bounties(die: 10_000)->tonight();

        $this->assertNull($character->fresh()->bounty_word_at);
    }

    // ---- the command -------------------------------------------------------

    public function test_the_command_rolls_and_reports(): void
    {
        $this->character(100);

        $this->instance(Bounties::class, $this->bounties(die: 10_000));

        $this->artisan('characters:bounties')
            ->expectsOutputToContain('1 character(s) had word put out.')
            ->assertSuccessful();

        $this->assertSame(1, Notification::query()->count());
    }

    public function test_a_dry_run_decides_nothing(): void
    {
        $this->character(100);

        $this->instance(Bounties::class, $this->bounties(die: 1));

        $this->artisan('characters:bounties --dry-run')
            ->expectsOutputToContain('Nothing was written.')
            ->assertSuccessful();

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_a_dry_run_names_who_is_resting(): void
    {
        $this->character(100)->forceFill(['bounty_word_at' => now()])->save();

        $this->instance(Bounties::class, $this->bounties(die: 1));

        $this->artisan('characters:bounties --dry-run')
            ->expectsOutputToContain('word already out')
            ->expectsOutputToContain('0 character(s) would be rolled.')
            ->assertSuccessful();
    }

    // ---- and the screen ----------------------------------------------------

    /** A message with nowhere to lead is a row rather than a link. */
    public function test_a_street_message_is_not_a_link(): void
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['user_id' => $user->id, 'archetype' => 3, 'heat' => 100],
        ));

        (new Notifier)->send($character, Category::Street, 'bounty_word');

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSeeText('price on your head')
            ->assertDontSee('<a class="item-row"', false);
    }
}
