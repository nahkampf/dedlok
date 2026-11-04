<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\AttackTag;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\Ice;
use App\Models\Run;
use App\Models\System;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * The Eject attack tag (docs/CYBERSPACE.md, *Subnets*, step 5).
 */
final class EjectTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    private System $door;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.view_range', 2);

        DB::table('grids')->insert(['id' => 2, 'slug' => 'mil', 'name' => 'Military', 'prefix' => 'mil', 'size' => 8, 'entry_x' => 4, 'entry_y' => 4, 'created_at' => now(), 'updated_at' => now()]);

        $this->door = System::factory()->at(2, 2)->create(['type' => SystemType::Gateway, 'address' => 'DEADBEEF']);
        $mirror = System::factory()->at(4, 4)->create(['type' => SystemType::Gateway, 'address' => 'mil:0001', 'grid_id' => 2]);
        $this->door->update(['links_to_system_id' => $mirror->id]);
        $mirror->update(['links_to_system_id' => $this->door->id]);

        $ice = Ice::query()->where('level', 6)->sole();
        $ice->forceFill(['attack_tags' => [['tag' => 'eject', 'level' => 2]], 'skill' => 100])->save();
        System::factory()->at(6, 6)->grade(1)->withIce($ice)->create(['address' => 'mil:00AA', 'grid_id' => 2]);

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_id' => 2, 'grid_x' => 6, 'grid_y' => 6])->save();
    }

    /** @param  list<int>  $dice */
    private function strike(array $dice, string $address = 'mil:00AA'): ?RunEnding
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 1.0));
        $run = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;
        $this->assertNotNull($run->ice_id, 'the machine woke its ICE');

        $simulator = Simulator::fromConfig(function (int $min, int $max) use (&$dice): int {
            return array_shift($dice) ?? $max;
        }, app(Resolver::class), app(CheckRoller::class));

        $ending = $simulator->strikeIce($this->character->fresh(), $run);

        if ($ending !== null) {
            $simulator->resolveEnding($this->character->fresh(), $run, $ending);
        }

        return $ending;
    }

    public function test_a_damaging_hit_that_rolls_under_the_chance_throws_you_out_to_the_gateway_still_jacked_in(): void
    {
        $before = (float) $this->character->current_synaptic_integrity;

        // The first die lands the hit (1 <= skill), the second is under the chance.
        $ending = $this->strike([1, 1]);

        $this->assertSame(RunEnding::Ejected, $ending);
        $character = $this->character->fresh();
        $this->assertNull($character->current_run_id);
        $this->assertSame(1, (int) $character->grid_id);
        $this->assertSame([2, 2], [$character->grid_x, $character->grid_y]);
        $this->assertLessThan($before - 6, (float) $character->current_synaptic_integrity + 0.01, 'the hit and the ejection both cost SI');
        $this->assertSame('ejected', Run::query()->latest('id')->first()->getRawOriginal('ending'));
    }

    public function test_a_roll_over_the_chance_leaves_you_in_the_fight(): void
    {
        $this->assertNull($this->strike([1, 99]));
        $this->assertSame(2, (int) $this->character->fresh()->grid_id);
        $this->assertNotNull($this->character->fresh()->current_run_id);
    }

    public function test_it_does_nothing_on_the_main_grid(): void
    {
        $this->character->forceFill(['grid_id' => 1, 'grid_x' => 8, 'grid_y' => 8])->save();
        System::query()->where('address', 'mil:00AA')->update(['grid_id' => 1, 'x' => 8, 'y' => 8, 'address' => 'DEADBEE0']);

        $this->assertNull($this->strike([1, 1]));
    }

    public function test_being_emptied_by_the_ejection_is_the_ordinary_floor(): void
    {
        $this->character->forceFill(['current_synaptic_integrity' => 8])->save();
        Ice::query()->where('level', 6)->update(['attack' => 5]);

        $ending = $this->strike([1, 1]);
        $character = $this->character->fresh();

        $this->assertSame(RunEnding::Ejected, $ending);
        $this->assertFalse($character->isJackedIn());
        $this->assertSame(1, (int) $character->grid_id);
        $this->assertLessThanOrEqual(0, (float) $character->current_synaptic_integrity);
    }

    public function test_the_levels_are_priced(): void
    {
        $this->assertSame(20, AttackTag::Eject->levelValue(2)['chance']);
        $this->assertTrue(AttackTag::Eject->isLevelled());
    }
}
