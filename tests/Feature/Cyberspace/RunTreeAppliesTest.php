<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\SystemType;
use App\Cyberspace\Tree\RunTree;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RunTreeAppliesTest extends TestCase
{
    use RefreshDatabase;

    public function test_off_no_system_walks_a_tree(): void
    {
        config(['features.intrusion_runs_v2' => false]);

        $this->assertFalse(RunTree::appliesTo(System::factory()->at(1, 1)->type(SystemType::Silo)->make(['grid_id' => 1])));
    }

    public function test_on_only_silo_compute_and_relay_on_the_main_grid_walk_one(): void
    {
        config(['features.intrusion_runs_v2' => true]);

        foreach ([SystemType::Silo, SystemType::Compute, SystemType::Relay] as $type) {
            $this->assertTrue(RunTree::appliesTo(System::factory()->at(1, 1)->type($type)->make(['grid_id' => 1])), $type->value);
        }

        foreach ([SystemType::Gateway, SystemType::Exchange, SystemType::Fortress, SystemType::Portal] as $type) {
            $this->assertFalse(RunTree::appliesTo(System::factory()->at(1, 1)->type($type)->make(['grid_id' => 1])), $type->value);
        }

        $this->assertFalse(RunTree::appliesTo(System::factory()->at(1, 1)->type(SystemType::Silo)->make(['grid_id' => 3])));
        $this->assertFalse(RunTree::appliesTo(System::factory()->at(1, 1)->type(SystemType::Silo)->make(['grid_id' => 1, 'owner_character_id' => 7])));
    }
}
