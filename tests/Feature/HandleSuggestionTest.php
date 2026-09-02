<?php

namespace Tests\Feature;

use App\Models\SuggestedHandle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HandleSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_whole_available_pool(): void
    {
        SuggestedHandle::create(['handle' => 'alpha', 'in_use' => false]);
        SuggestedHandle::create(['handle' => 'bravo', 'in_use' => false]);

        $this->getJson(route('handles.available'))
            ->assertOk()
            ->assertJson(['count' => 2])
            ->assertJsonPath('handles', ['alpha', 'bravo']);
    }

    public function test_it_omits_handles_already_in_use(): void
    {
        SuggestedHandle::create(['handle' => 'taken', 'in_use' => true]);
        SuggestedHandle::create(['handle' => 'free', 'in_use' => false]);

        $this->getJson(route('handles.available'))
            ->assertOk()
            ->assertJsonPath('handles', ['free']);
    }

    public function test_it_omits_handles_that_would_fail_validation(): void
    {
        SuggestedHandle::create(['handle' => 'has space', 'in_use' => false]);
        SuggestedHandle::create(['handle' => 'g0odhandle', 'in_use' => false]);

        $this->getJson(route('handles.available'))
            ->assertOk()
            ->assertJsonPath('handles', ['g0odhandle']);
    }

    public function test_an_empty_pool_returns_an_empty_list_not_an_error(): void
    {
        $this->getJson(route('handles.available'))
            ->assertOk()
            ->assertJson(['handles' => [], 'count' => 0]);
    }

    public function test_requesting_it_does_not_reserve_anything(): void
    {
        SuggestedHandle::create(['handle' => 'ghost', 'in_use' => false]);

        $this->getJson(route('handles.available'))->assertOk();

        $this->assertDatabaseHas('suggest_hacker_handles', ['handle' => 'ghost', 'in_use' => false]);
    }

    public function test_the_pool_is_cached_so_repeat_requests_do_not_query(): void
    {
        SuggestedHandle::create(['handle' => 'cached', 'in_use' => false]);
        Cache::forget(SuggestedHandle::CACHE_KEY);

        $this->getJson(route('handles.available'))->assertOk();   // warms the cache

        DB::enableQueryLog();
        $this->getJson(route('handles.available'))->assertOk()->assertJsonPath('handles', ['cached']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $touchedPool = collect($queries)->contains(
            fn (array $q) => str_contains($q['query'], 'suggest_hacker_handles')
        );

        $this->assertFalse($touchedPool, 'second request should be served from cache');
    }

    public function test_writing_to_the_pool_busts_the_cache(): void
    {
        SuggestedHandle::create(['handle' => 'first', 'in_use' => false]);
        $this->getJson(route('handles.available'))->assertJsonPath('handles', ['first']);

        SuggestedHandle::create(['handle' => 'second', 'in_use' => false]);
        $this->getJson(route('handles.available'))->assertJsonPath('handles', ['first', 'second']);

        SuggestedHandle::where('handle', 'first')->first()->update(['in_use' => true]);
        $this->getJson(route('handles.available'))->assertJsonPath('handles', ['second']);

        SuggestedHandle::where('handle', 'second')->first()->delete();
        $this->getJson(route('handles.available'))->assertJsonPath('handles', []);
    }

    public function test_query_builder_writes_do_not_bust_the_cache_but_the_ttl_recovers(): void
    {
        SuggestedHandle::create(['handle' => 'alpha', 'in_use' => false]);
        SuggestedHandle::create(['handle' => 'bravo', 'in_use' => false]);

        $this->getJson(route('handles.available'))->assertJsonPath('handles', ['alpha', 'bravo']);

        // A query-builder delete bypasses Eloquent model events entirely, so
        // the saved/deleted hooks never run and the cache stays warm.
        SuggestedHandle::where('handle', 'alpha')->delete();

        $this->getJson(route('handles.available'))
            ->assertJsonPath('handles', ['alpha', 'bravo'], 'stale until the TTL lapses — this is expected');

        // The TTL is the backstop that makes that staleness bounded.
        $this->travel(SuggestedHandle::CACHE_TTL_MINUTES + 1)->minutes();

        $this->getJson(route('handles.available'))->assertJsonPath('handles', ['bravo']);
    }

    public function test_flush_cache_recovers_immediately(): void
    {
        SuggestedHandle::create(['handle' => 'alpha', 'in_use' => false]);
        SuggestedHandle::create(['handle' => 'bravo', 'in_use' => false]);
        $this->getJson(route('handles.available'))->assertJsonPath('handles', ['alpha', 'bravo']);

        SuggestedHandle::where('handle', 'alpha')->delete();
        SuggestedHandle::flushCache();

        $this->getJson(route('handles.available'))->assertJsonPath('handles', ['bravo']);
    }

    public function test_the_character_creation_page_offers_the_button(): void
    {
        // The handle now lives on character creation, not registration.
        $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            ->assertOk()
            ->assertSee('id="suggest-handle"', false)
            ->assertSee('↺', false);
    }

    public function test_the_register_page_no_longer_asks_for_a_handle(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertDontSee('id="suggest-handle"', false)
            ->assertDontSee('name="handle"', false);
    }
}
