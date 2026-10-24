<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Flags;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\Entity;
use App\Models\Notification;
use App\Models\System;
use App\Standing\StandingLedger;
use App\Standing\StandingReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Lockjaw (entity 125, whatever it is called) hands over the darknet key at 10 standing, once, with a message naming the gateway. */
final class StandingGrantsTest extends TestCase
{
    use RefreshDatabase;

    private Entity $lockjaw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockjaw = Entity::query()->firstOrNew(['id' => 125]);
        $this->lockjaw->forceFill(['name' => 'Renamed', 'category' => 'corporation', 'interactable' => true, 'business' => 'Arms', 'description' => 'Test.'])->save();

        DB::table('grids')->insert(['id' => 3, 'slug' => 'darknet', 'name' => 'The Darknet', 'prefix' => 'dark', 'size' => 8, 'entry_x' => 4, 'entry_y' => 4, 'created_at' => now(), 'updated_at' => now()]);
        $main = System::factory()->at(2, 2)->create(['type' => SystemType::Gateway, 'address' => '6F59D096']);
        $inside = System::factory()->at(4, 4)->create(['type' => SystemType::Gateway, 'address' => 'dark:5E63', 'grid_id' => 3]);
        $main->update(['links_to_system_id' => $inside->id]);
        $inside->update(['links_to_system_id' => $main->id]);
    }

    public function test_reaching_ten_gives_the_key_once_and_says_where_the_gateway_is(): void
    {
        $character = Character::factory()->create();
        $ledger = new StandingLedger;

        $ledger->record($character, $this->lockjaw, StandingReason::MissionCompleted, 9.0);
        $this->assertFalse(Flags::has($character, 'darknet_access'));

        $ledger->record($character, $this->lockjaw, StandingReason::MissionCompleted, 1.0);
        $this->assertTrue(Flags::has($character, 'darknet_access'));

        $ledger->record($character, $this->lockjaw, StandingReason::MissionCompleted, 1.0);

        $messages = Notification::query()->where('user_id', $character->user_id)->get();
        $this->assertCount(1, $messages);
        $this->assertSame('Darknet acesss', $messages[0]->title);
        $this->assertStringContainsString('Di spot deh deh: 6F59D096.', $messages[0]->body);
    }
}
