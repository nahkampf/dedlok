<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Models\Character;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MyBackdoorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_modal_lists_the_systems_a_backdoor_is_held_on(): void
    {
        $character = Character::factory()->create();
        $held = System::factory()->at(5, 5)->create(['grade' => 3]);
        $other = System::factory()->at(6, 6)->create();
        (new Backdoors)->install($character, $held, BackdoorKind::Siphon);

        $this->actingAs($character->user)->get(route('cyberspace.backdoors'))
            ->assertOk()
            ->assertSee($held->address)
            ->assertSee(BackdoorKind::Siphon->label())
            ->assertDontSee($other->address);
    }

    public function test_it_says_so_when_there_are_none(): void
    {
        $character = Character::factory()->create();

        $this->actingAs($character->user)->get(route('cyberspace.backdoors'))
            ->assertOk()->assertSee('You hold no backdoors.');
    }
}
