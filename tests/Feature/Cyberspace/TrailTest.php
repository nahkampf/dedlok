<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Navigator;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Models\Character;
use App\Models\Ice;
use App\Models\System;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** The trail says why somebody left cyberspace, and costs a request nothing when it is off. */
final class TrailTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character = $this->character->fresh();
        System::factory()->at(8, 8)->grade(1)->withIce(Ice::query()->where('level', 1)->sole())->create(['address' => 'DEADBEEF']);
    }

    public function test_a_jack_out_is_written_with_its_state_and_who_asked(): void
    {
        Config::set('cyberspace.trail', true);

        $events = [];
        $channel = \Mockery::mock();
        $channel->shouldReceive('info')->andReturnUsing(function (string $event, array $context) use (&$events): void {
            $events[$event] = $context;
        });
        Log::shouldReceive('channel')->with('cyberspace')->andReturn($channel);

        Navigator::fromConfig(app(Resolver::class))->jackOut($this->character);

        $this->assertArrayHasKey('jack_out', $events);
        $this->assertSame('8,8', $events['jack_out']['at']);
        $this->assertTrue($events['jack_out']['deck']['ready']);
        $this->assertNotEmpty($events['jack_out']['callers']);
    }

    public function test_a_run_end_is_written_before_and_after(): void
    {
        Config::set('cyberspace.trail', true);

        $events = [];
        $channel = \Mockery::mock();
        $channel->shouldReceive('info')->andReturnUsing(function (string $event, array $context) use (&$events): void {
            $events[] = $event;
        });
        Log::shouldReceive('channel')->with('cyberspace')->andReturn($channel);

        $run = app(RunStarter::class)->start($this->character, RunPurpose::Extraction)->run;
        app(RunStarter::class)->end($this->character->fresh(), $run, RunEnding::Egress);

        $this->assertSame(['run_end_begin', 'run_end_done'], $events);
    }

    public function test_off_it_writes_nothing(): void
    {
        Config::set('cyberspace.trail', false);
        Log::shouldReceive('channel')->never();

        Navigator::fromConfig(app(Resolver::class))->jackOut($this->character);

        $this->assertFalse($this->character->fresh()->isJackedIn());
    }
}
