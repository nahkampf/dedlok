<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Missions\MissionAction;
use App\Missions\MissionState;
use App\Models\Character;
use App\Models\Entity;
use App\Models\Exchange;
use App\Models\Mission;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Tasks & Missions screen: the jobs taken and not yet finished, plus the two placeholders. */
final class TasksAndMissionsTest extends TestCase
{
    use RefreshDatabase;

    private Character $me;

    private Entity $entity;

    private Exchange $desk;

    private int $placed = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = Character::factory()->create(['archetype' => 3]);
        $this->entity = Entity::query()->interactable()->firstOrFail();
        $deskSystem = System::factory()->at(8, 8)->ownedBy($this->entity)->create(['type' => 'exchange', 'grade' => 1]);
        $this->desk = Exchange::query()->create(['system_id' => $deskSystem->id, 'grade' => 1, 'agent' => 'Mr Nakata', 'min_standing' => 0]);
    }

    private function job(Character $who, MissionAction $action, MissionState $state, ?System $target = null): Mission
    {
        $mission = Mission::query()->create([
            'character_id' => $who->id, 'entity_id' => $this->entity->id, 'exchange_id' => $this->desk->id,
            'action' => $action, 'state' => $state, 'quantity_required' => $action === MissionAction::Supply ? 4 : 0,
            'creds_reward' => 123, 'standing_reward' => 0.02, 'loyalty_reward' => 2, 'penalty' => 0.02, 'offered_at' => now(),
            'accepted_at' => $state === MissionState::Offered ? null : now(),
            'expires_at' => $state === MissionState::Offered ? null : now()->addHours(5),
        ]);

        if ($action->designatesTargets()) {
            $target ??= System::factory()->at(10 + $this->placed++, 12)->create(['type' => 'silo', 'grade' => 2]);
            $mission->targets()->create(['system_id' => $target->id, 'address' => $target->address]);
        }

        return $mission;
    }

    private function page(): string
    {
        return $this->actingAs($this->me->user->fresh())->get(route('tasks.index'))->assertOk()->getContent();
    }

    public function test_the_rail_has_tasks_and_missions_and_the_screen_has_three_headings(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('href="'.route('tasks.index').'"', $html);
        $this->assertStringContainsString('Tasks &amp; Missions', $html);
        foreach (['>Missions<', '>Journal<', '>Tasks<'] as $heading) {
            $this->assertStringContainsString($heading, $html);
        }
        $this->assertSame(2, substr_count($html, 'Not yet implemented.'), 'the journal and the tasks');
        $this->assertStringContainsString('No missions accepted.', $html);
    }

    public function test_only_accepted_jobs_are_listed_with_their_details(): void
    {
        $target = System::factory()->at(30, 30)->create(['type' => 'silo', 'grade' => 3]);
        $taken = $this->job($this->me, MissionAction::Burn, MissionState::Accepted, $target);
        $this->job($this->me, MissionAction::Scan, MissionState::Offered);
        $this->job($this->me, MissionAction::Plant, MissionState::Failed);
        $this->job($this->me, MissionAction::Plant, MissionState::Completed);
        $this->job($this->me, MissionAction::Plant, MissionState::Voided);
        $this->job(Character::factory()->create(['archetype' => 3]), MissionAction::Burn, MissionState::Accepted);

        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'class="card card--select"'), 'one live job, nobody else\'s');
        $this->assertStringContainsString(MissionAction::Burn->label(), $html);
        $this->assertStringContainsString($target->address, $html, 'the system by address');
        $this->assertStringContainsString($this->desk->system->address, $html, 'and where the desk is');
        $this->assertStringContainsString('Mr Nakata', $html);
        $this->assertStringContainsString((string) $this->entity->name, $html);
        $this->assertStringContainsString('123', $html, 'what it pays');
        $this->assertStringNotContainsString('Accept', $html);
        $this->assertStringNotContainsString(route('missions.complete', $taken), $html, 'handed in at the desk, not here');
        $this->assertStringNotContainsString(route('missions.abandon', $taken), $html);
    }

    public function test_the_job_running_out_first_is_listed_first(): void
    {
        $late = $this->job($this->me, MissionAction::Burn, MissionState::Accepted);
        $late->forceFill(['expires_at' => now()->addHours(20)])->save();
        $soon = $this->job($this->me, MissionAction::Plant, MissionState::Accepted);
        $soon->forceFill(['expires_at' => now()->addHour()])->save();

        $html = $this->page();

        $this->assertLessThan(strpos($html, MissionAction::Burn->label()), strpos($html, MissionAction::Plant->label()));
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('tasks.index'))->assertRedirect();
    }
}
