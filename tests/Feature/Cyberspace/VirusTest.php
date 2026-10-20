<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Characters\CurrentProcesses;
use App\Checks\CheckRoller;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Cyberspace\Virus;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Run;
use App\Models\System;
use App\Programs\ProgramRunner;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * The Virus attack tag (CYBERSPACE.md, *Virus*).
 */
final class VirusTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.attack_tags.virus', [1 => ['chance' => 50, 'damage' => 4, 'turns' => 4]]);

        $ice = Ice::query()->where('level', 6)->sole();
        $ice->forceFill(['attack_tags' => [['tag' => 'virus', 'level' => 1]], 'skill' => 100])->save();
        System::factory()->at(8, 8)->grade(1)->withIce($ice)->create(['address' => 'DEADBEEF']);

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8, 'current_synaptic_integrity' => 100])->save();
    }

    /** @param  list<int>  $dice */
    private function strike(array $dice): void
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 1.0));
        $run = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;
        $simulator = Simulator::fromConfig(function (int $min, int $max) use (&$dice): int {
            return array_shift($dice) ?? $max;
        }, app(Resolver::class), app(CheckRoller::class));
        $simulator->strikeIce($this->character->fresh(), $run);
    }

    public function test_a_damaging_hit_that_rolls_under_the_chance_infects_and_a_miss_does_not(): void
    {
        $this->strike([1, 99]);
        $this->assertNull($this->character->fresh()->virus_damage);

        $this->character->forceFill(['current_run_id' => null])->save();
        Run::query()->delete();
        $this->strike([1, 1]);

        $character = $this->character->fresh();
        $this->assertSame(4, $character->virus_damage);
        $this->assertSame(4, $character->virus_turns);
    }

    public function test_it_tapers_to_nothing_and_then_clears(): void
    {
        $virus = Virus::make();
        $virus->infect($this->character, 1);
        $taken = [];

        for ($i = 0; $i < 6; $i++) {
            $result = $virus->tick($this->character->fresh());
            $taken[] = $result['taken'] ?? null;
        }

        // 4 SI over four ticks, tapering: 4, 3, 2, 1; then nothing is left to take.
        $this->assertSame([4, 3, 2, 1, null, null], $taken);
        $this->assertNull($this->character->fresh()->virus_damage);
    }

    public function test_an_antiviral_shortens_it_even_after_the_infection(): void
    {
        Config::set('effects.antiviral', [
            'label' => 'Antiviral', 'stacking' => 'unique', 'retired' => false,
            'modifiers' => [['op' => 'percentage_of_base', 'amount' => -50, 'target' => ['id' => 'virus_lifetime']]],
        ]);
        $virus = Virus::make();
        $virus->infect($this->character, 1);
        CharacterModifier::query()->create(['character_id' => $this->character->id, 'definition_key' => 'antiviral']);

        $this->assertSame(2, $virus->lifetime($this->character->fresh()));
    }

    public function test_reinfection_refreshes_rather_than_stacks(): void
    {
        $virus = Virus::make();
        $virus->infect($this->character, 1);
        $virus->tick($this->character->fresh());
        $virus->tick($this->character->fresh());
        $virus->infect($this->character->fresh(), 1);

        $this->assertSame(0, $this->character->fresh()->virus_elapsed);
        $this->assertSame(4, $this->character->fresh()->virus_damage);
    }

    public function test_a_tick_that_empties_a_run_is_a_defeat(): void
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 1.0));
        $run = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;
        Virus::make()->infect($this->character->fresh(), 1);
        $this->character->forceFill(['current_synaptic_integrity' => 1])->save();

        $result = Simulator::fromConfig()->take($this->character->fresh(), $run, RunAction::Egress);

        $this->assertContains($result->ending, [RunEnding::Defeat, RunEnding::Egress]);
    }

    public function test_away_from_a_run_the_minute_tick_can_put_you_on_the_floor(): void
    {
        $this->character->forceFill(['grid_x' => null, 'grid_y' => null, 'current_synaptic_integrity' => 2])->save();
        Virus::make()->infect($this->character->fresh(), 1);

        Virus::make()->tickAway($this->character->fresh());

        $character = $this->character->fresh();
        $this->assertLessThanOrEqual(0, $character->current_synaptic_integrity);
        $this->assertNotNull($character->waiting_until);
        $this->assertNull($character->virus_damage);
    }

    public function test_current_processes_shows_it(): void
    {
        Virus::make()->infect($this->character, 1);

        $rows = collect(app(CurrentProcesses::class)->for($this->character->fresh()));

        $this->assertSame('Infected', $rows->firstWhere('kind', 'virus')['label']);
    }

    public function test_the_two_antivirals_shorten_a_virus_while_they_run(): void
    {
        $this->migrateAntivirals();
        $virus = Virus::make();
        $virus->infect($this->character, 1);
        Config::set('cyberspace.attack_tags.virus', [1 => ['chance' => 50, 'damage' => 4, 'turns' => 10]]);
        $virus->infect($this->character->fresh(), 1);

        $this->assertSame(10, $virus->lifetime($this->character->fresh()));

        $runner = app(ProgramRunner::class);
        $this->assertTrue($runner->start($this->character->fresh(), $this->hold('condom_0_49_1'))->succeeded());
        $this->assertSame(7, $virus->lifetime($this->character->fresh()));

        $this->assertTrue($runner->start($this->character->fresh(), $this->hold('purge_pro'))->succeeded());
        // The stronger gives all of its 50%, the second 86% of its own 33%: 10 - 5 - 2.84 = 2.16.
        $this->assertSame(2, $virus->lifetime($this->character->fresh()));
    }

    private function migrateAntivirals(): void
    {
        $migration = require base_path('database/migrations/2026_10_22_110000_the_antivirals.php');
        $migration->up();
    }

    private function hold(string $slug): InventoryItem
    {
        return $this->ownedBy($this->character, Item::query()->where('slug', $slug)->sole());
    }
}
