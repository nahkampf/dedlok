<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Models\Character;
use App\Models\Ice;
use App\Models\System;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * A turn in a system costs a point of fatigue, whatever it comes to.
 */
final class RunTurnFatigueTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    public function test_a_turn_tires(): void
    {
        Config::set('cyberspace.size', 16);

        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($character);
        $character->forceFill(['grid_x' => 8, 'grid_y' => 8, 'egress_trace_at' => 0, 'egress_si_at' => 0])->save();

        System::factory()->at(8, 8)->grade(1)->withIce(Ice::query()->where('level', 1)->sole())->withTracer(2, 4)->create(['address' => 'DEADBEEF']);

        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));
        $run = app(RunStarter::class)->start($character->fresh(), RunPurpose::Extraction)->run;

        $simulator = Simulator::fromConfig(static fn (int $min, int $max): int => $max, app(Resolver::class), app(CheckRoller::class));
        $simulator->take($character->fresh(), $run->fresh(), RunAction::Egress);

        $this->assertSame(1, $character->fresh()->current_fatigue);
    }
}
