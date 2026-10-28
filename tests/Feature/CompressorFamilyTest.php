<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Decks\Storage;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * A Compressor adds to the deck's storage while it runs, and a skill that grants `family.compressor` makes that more.
 */
final class CompressorFamilyTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);

        $item = Item::factory()->program(ProgramKind::Utility, qflops: 0.1, memory: 0.1, storage: 0.1, background: true, modifiers: [
            ['op' => 'percentage_of_base', 'amount' => 15, 'target' => ['id' => 'deck_storage']],
        ])->create();
        $item->program->forceFill(['family' => 'compressor'])->save();

        $row = InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
        $this->assertTrue(app(ProgramRunner::class)->start($this->character->fresh(), $row)->succeeded());
    }

    private function storage(): float
    {
        return Storage::capacity($this->character->fresh());
    }

    public function test_the_compressor_adds_fifteen_per_cent_of_the_decks_storage(): void
    {
        $plain = $this->withoutCompressor();
        $this->assertEqualsWithDelta($plain * 1.15, $this->storage(), 0.1);
    }

    public function test_a_skill_granting_the_family_stat_scales_it(): void
    {
        $with = $this->storage();
        $plain = $this->withoutCompressor();

        $skill = Skill::factory()->create(['modifiers' => [['op' => 'add', 'amount' => 20, 'target' => ['id' => 'family.compressor']]]]);
        CharacterSkill::create(['character_id' => $this->character->id, 'skill_id' => $skill->id, 'attained_level' => 1]);

        // 15% scaled by 1.20 is 18%.
        $this->assertEqualsWithDelta($plain * 1.18, $this->storage(), 0.1);
        $this->assertGreaterThan($with, $this->storage());
    }

    /** Storage with the compressor stopped, and the compressor started again. */
    private function withoutCompressor(): float
    {
        $runner = app(ProgramRunner::class);
        $character = $this->character->fresh();
        $runner->stopAll($character, $character->equippedDeck);
        $plain = $this->storage();
        $row = InventoryItem::query()->where('character_id', $character->id)->whereHas('item.program', fn ($q) => $q->where('family', 'compressor'))->first();
        $runner->start($this->character->fresh(), $row);

        return $plain;
    }
}
