<?php

namespace Tests\Feature;

use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThrottleMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The assertions below quote an exact Retry-After. That value is
         * computed as (window end - now), so on a slow run the clock ticks
         * between the request that opens the window and the one that trips it,
         * and 60 becomes 59. Freezing time removes the race rather than
         * loosening the assertion.
         */
        $this->freezeTime();
    }

    /** Exhaust the limiter on the available-handles endpoint (throttle:30,1). */
    private function exhaustLimiter(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson(route('handles.available'));
        }
    }

    public function test_json_throttle_message_is_translated_not_the_framework_literal(): void
    {
        $this->exhaustLimiter();

        $response = $this->getJson(route('handles.available'))->assertStatus(429);

        $response->assertJsonPath('message', 'Too many requests. Try again in 60 seconds.');
        $this->assertStringNotContainsString('Too Many Attempts', $response->getContent());
    }

    public function test_it_preserves_the_rate_limit_headers(): void
    {
        $this->exhaustLimiter();

        $this->getJson(route('handles.available'))
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', 30)
            ->assertHeader('X-RateLimit-Remaining', 0);
    }

    public function test_browser_requests_get_the_themed_429_page(): void
    {
        $this->exhaustLimiter();

        $this->get(route('handles.available'))
            ->assertStatus(429)
            ->assertSee(Text::get('errors.throttle.title'))
            ->assertSee('Too many requests. Try again in 60 seconds.');
    }
}
