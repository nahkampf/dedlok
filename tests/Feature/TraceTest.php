<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Support\Trace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class TraceTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_named_handle_is_traced_and_the_work_still_returns(): void
    {
        $noodle = Character::factory()->create(['handle' => 'Noodle']);
        $other = Character::factory()->create(['handle' => 'Other']);

        Config::set('trace.handles', ['noodle']);

        $this->assertTrue(Trace::enabledFor($noodle));
        $this->assertFalse(Trace::enabledFor($other));
        $this->assertSame(7, Trace::scope($other, 'x', fn () => 7));
        $this->assertSame(7, Trace::scope($noodle, 'x', fn () => 7));
        $this->assertFalse(Trace::active(), 'the subject is released afterwards');
    }

    public function test_state_describes_the_character_without_throwing(): void
    {
        $state = Trace::state(Character::factory()->create(['handle' => 'Noodle']));

        $this->assertSame('Noodle', $state['handle']);
        $this->assertArrayHasKey('running', $state);
    }
}
