<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** The front page's two small numbers under the version, and the last-active mark they read. */
final class PlayerCountsTest extends TestCase
{
    use RefreshDatabase;

    private function player(?string $lastActive): User
    {
        $user = User::factory()->create();
        $user->forceFill(['last_active_at' => $lastActive])->save();
        Character::factory()->create(['user_id' => $user->id, 'archetype' => 3]);

        return $user;
    }

    public function test_the_page_counts_players_and_the_active_ones(): void
    {
        Cache::forget('home.player_counts');
        $this->player(now()->subDays(1)->toDateTimeString());
        $this->player(now()->subDays(6)->toDateTimeString());
        $this->player(now()->subDays(8)->toDateTimeString());
        $this->player(null);
        User::factory()->create();   // registered and never made a character: not a player yet

        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#Total players:\s*<span style="color:var\(--ok\)">4</span>#', $html);
        $this->assertMatchesRegularExpression('#Active players last 7 days:\s*<span style="color:var\(--ok\)">2</span>#', $html);
    }

    public function test_a_request_marks_the_account_active_and_the_inbox_poll_does_not(): void
    {
        $user = $this->player(null);

        $this->actingAs($user)->getJson(route('notifications.unread'))->assertOk();
        $this->assertNull($user->fresh()->last_active_at, 'an open tab polling is not somebody playing');

        $this->actingAs($user->fresh())->get(route('character.index'))->assertOk();
        $first = $user->fresh()->last_active_at;
        $this->assertNotNull($first);

        // Within a few minutes it is not written again; after them it is.
        $this->travel(2)->minutes();
        $this->actingAs($user->fresh())->get(route('character.index'));
        $this->assertEquals($first, $user->fresh()->last_active_at);

        $this->travel(10)->minutes();
        $this->actingAs($user->fresh())->get(route('character.index'));
        $this->assertTrue($user->fresh()->last_active_at->gt($first));
    }
}
