<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\RunEnding;
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
 * A defeat leaves its run live until it is resolved, so two requests for the same turn both reach the resolution with a
 * run they believe live. Noodle was skimmed four times and lost a deck's files and six modules to one piece of ICE.
 */
final class ResolveEndingOnceTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    public function test_a_defeat_resolved_twice_costs_once(): void
    {
        Config::set('cyberspace.size', 16);

        $ice = Ice::query()->where('level', 6)->sole();
        $ice->forceFill(['defeat_tags' => [['tag' => 'skim', 'level' => 3]]])->save();
        System::factory()->at(6, 6)->grade(1)->withIce($ice)->create(['address' => 'ABCD0001']);

        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3, 'creds' => 1000]));
        $this->equipReadyDeck($character);
        $character->forceFill(['grid_id' => 1, 'grid_x' => 6, 'grid_y' => 6])->save();

        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 1.0));
        $run = app(RunStarter::class)->start($character->fresh(), RunPurpose::Extraction)->run;
        $this->assertNotNull($run->ice_id);

        $simulator = Simulator::fromConfig(static fn (int $min, int $max): int => $max, app(Resolver::class), app(CheckRoller::class));

        // Two requests, each holding its own copy of the run, neither yet knowing the other has ended it.
        $first = $run->fresh();
        $second = $run->fresh();

        $simulator->resolveEnding($character->fresh(), $first, RunEnding::Defeat);
        $simulator->resolveEnding($character->fresh(), $second, RunEnding::Defeat);

        $this->assertSame(400, $character->fresh()->creds, 'skim III took 60% once, not twice');
    }
}
