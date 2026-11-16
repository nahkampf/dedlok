<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Glitch;
use Illuminate\Support\Arr;
use Tests\TestCase;

class GlitchTest extends TestCase
{
    public function test_every_log_key_is_a_real_line_and_a_real_weight(): void
    {
        foreach (config('glitch.log') as $key => $level) {
            $this->assertTrue(Arr::has(config('strings'), $key), "{$key} is not a line the game writes");
            $this->assertContains($level, [Glitch::TICK, Glitch::HIT, Glitch::CRASH]);
        }
    }

    public function test_a_log_line_looks_up_by_its_dotted_key(): void
    {
        $this->assertSame('hit', Glitch::forLogKey('cyberspace.run.log.ice_hits'));
        $this->assertNull(Glitch::forLogKey('cyberspace.run.log.hold'));
        $this->assertNull(Glitch::forLogKey(null));
    }

    public function test_the_strongest_line_wins(): void
    {
        $this->assertNull(Glitch::strongest([['text' => 'nothing']]));
        $this->assertSame('hit', Glitch::strongest([['fx' => 'tick'], ['fx' => 'hit'], ['fx' => 'tick']]));
        $this->assertSame('crash', Glitch::strongest([['fx' => 'hit'], ['fx' => 'crash']]));
    }
}
