<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Cyberspace\RunPurpose;
use App\Models\Character;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A button pressed under a lock says why it did nothing, instead of silently landing on the lock's own screen. */
final class LockedPressTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_press_during_a_run_is_told_why(): void
    {
        $character = Character::factory()->create();
        $run = Run::query()->create([
            'character_id' => $character->id,
            'address' => 'DEADBEEF',
            'x' => 5, 'y' => 5,
            'purpose' => RunPurpose::Extraction->value,
            'turn' => 1,
            'detected' => false,
            'started_at' => now(),
        ]);
        $character->forceFill(['current_run_id' => $run->id, 'grid_x' => 5, 'grid_y' => 5])->save();

        $this->actingAs($character->user)
            ->delete(route('cyberspace.abandon'), ['address' => 'DEADBEEF'])
            ->assertRedirect(route('run.show'))
            ->assertSessionHas('error');

        $this->actingAs($character->user)->get(route('inventory.index'))
            ->assertRedirect(route('run.show'))
            ->assertSessionMissing('error');
    }
}
