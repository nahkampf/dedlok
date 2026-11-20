<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** A 400 is logged with its reason and what the request carried, and still renders the error page. */
final class BadRequestLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_symfony_cannot_read_is_logged_and_still_400s(): void
    {
        Log::spy();
        $character = Character::factory()->create();

        $this->actingAs($character->user)
            ->post(route('cyberspace.abandon'), ['_method' => ['DELETE'], 'address' => 'DEADBEEF'])
            ->assertStatus(400);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_starts_with($message, '400:')
            && $context['path'] === 'cyberspace/reading'
            && ($context['fields']['address'] ?? null) === 'DEADBEEF')->once();
    }
}
