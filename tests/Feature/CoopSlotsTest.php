<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterModifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/** `coop_slots`: zero is the ordinary state and the gate; a modifier adds a slot each. */
final class CoopSlotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_nobody_has_a_slot_until_something_grants_one(): void
    {
        $character = Character::factory()->create();

        $this->assertSame(0, $character->coopSlots());
    }

    public function test_each_granted_slot_adds_one(): void
    {
        $character = Character::factory()->create();

        Config::set('effects.coordinating', [
            'label' => 'Coordinating',
            'stacking' => 'unique',
            'retired' => false,
            'modifiers' => [['op' => 'add', 'amount' => 3, 'target' => ['id' => 'coop_slots']]],
        ]);
        CharacterModifier::query()->create(['character_id' => $character->id, 'definition_key' => 'coordinating']);

        $this->assertSame(3, $character->fresh()->coopSlots());
    }
}
