<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Entity;
use App\Standing\StandingLedger;
use App\Standing\StandingReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StandingTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tab_lists_the_entity_and_the_change(): void
    {
        $character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));
        $entity = Entity::query()->interactable()->firstOrFail();

        (new StandingLedger)->record($character, $entity, StandingReason::Burned);

        $this->actingAs($character->user)->get(route('standing.index'))
            ->assertOk()
            ->assertSeeText($entity->name)
            ->assertSeeText(StandingReason::Burned->label());
    }

    public function test_an_empty_tab_says_so(): void
    {
        $character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));

        $this->actingAs($character->user)->get(route('standing.index'))
            ->assertOk()->assertSeeText('Nobody has an opinion of you yet.');
    }
}
