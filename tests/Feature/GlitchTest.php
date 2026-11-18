<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Glitch;
use App\Support\Text;
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

    /** Every line that plays the failure sound is a real log line of yours that fails (so it also ticks the page). */
    public function test_the_failure_sound_is_only_for_failures_the_log_knows(): void
    {
        foreach (config('glitch.failure_sound') as $key) {
            $this->assertSame('tick', Glitch::forLogKey($key), $key);
            $this->assertNotSame($key, Text::get($key), $key);
        }
    }

    public function test_the_success_sound_is_for_the_listed_successes_and_every_backdoor_kind(): void
    {
        foreach (config('glitch.success_sound') as $key) {
            $this->assertTrue(Glitch::playsSuccess($key));
            $this->assertNotSame($key, Text::get($key), $key);
        }

        $this->assertTrue(Glitch::playsSuccess('cyberspace.run.log.install.routing'));
        $this->assertFalse(Glitch::playsSuccess('cyberspace.run.log.install_fails'));
        $this->assertFalse(Glitch::playsSuccess('cyberspace.run.log.install_already'));
        $this->assertFalse(Glitch::playsSuccess(null));
    }

    public function test_the_noise_sound_is_for_noise_lines_the_log_knows(): void
    {
        foreach (config('glitch.noise_sound') as $key) {
            $this->assertNotSame($key, Text::get($key), $key);
        }
    }
}
