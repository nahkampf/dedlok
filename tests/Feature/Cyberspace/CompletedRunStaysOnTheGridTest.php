<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Models\Character;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Egressing after the run got what it came for ends it as COMPLETED, and that is not a bad ending: the player is
 * still standing where they were. It used to be sent through the ejection with the defeats and burnouts, because the
 * controller asked "is it not an egress?" rather than "is it bad?".
 */
final class CompletedRunStaysOnTheGridTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        // Every check passes, so the egress roll is not the thing under test.
        $this->app->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character = $this->character->fresh();
        System::factory()->at(8, 8)->grade(1)->create(['address' => 'DEADBEEF']);
    }

    public function test_egressing_after_a_successful_extraction_leaves_them_on_the_grid(): void
    {
        $run = app(RunStarter::class)->start($this->character, RunPurpose::Extraction)->run;
        $run->forceFill(['extracted' => true])->save();

        $this->actingAs($this->character->user)->post(route('run.act'), ['action' => 'egress'])
            ->assertRedirect(route('cyberspace.index'))
            ->assertSessionHas('status');

        $this->assertSame(RunEnding::Completed->value, $run->fresh()->ending);
        $this->assertTrue($this->character->fresh()->isJackedIn(), 'still on the grid');
        $this->assertSame(40, (int) $this->character->fresh()->current_synaptic_integrity);
    }

    public function test_a_plain_egress_leaves_them_on_the_grid_too(): void
    {
        $run = app(RunStarter::class)->start($this->character, RunPurpose::Extraction)->run;

        $this->actingAs($this->character->user)->post(route('run.act'), ['action' => 'egress'])->assertRedirect(route('cyberspace.index'));

        $this->assertSame(RunEnding::Egress->value, $run->fresh()->ending);
        $this->assertTrue($this->character->fresh()->isJackedIn());
    }
}
