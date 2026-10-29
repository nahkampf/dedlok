<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The stash card of an eidetic imprint says when its skill is already injected.
 */
final class ImprintCardKnownTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_card_says_so_only_for_a_skill_already_injected(): void
    {
        $character = Character::factory()->create();
        $known = Skill::factory()->create(['rank' => 1]);
        $new = Skill::factory()->create(['rank' => 1]);

        foreach ([$known, $new] as $skill) {
            InventoryItem::create(['character_id' => $character->id, 'item_id' => Item::factory()->imprintFor($skill)->create()->id, 'quantity' => 1]);
        }

        $page = $this->actingAs($character->user)->get(route('inventory.index'));
        $this->assertStringNotContainsString('Skill already injected', $page->getContent());

        CharacterSkill::create(['character_id' => $character->id, 'skill_id' => $known->id, 'attained_level' => 1]);

        $page = $this->actingAs($character->fresh()->user)->get(route('inventory.index'));
        $this->assertSame(1, substr_count($page->getContent(), 'Skill already injected'), 'once, for the imprint whose skill is known');
    }
}
