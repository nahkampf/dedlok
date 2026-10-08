<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Crafting\Crafting;
use App\Crafting\CraftingRefusal;
use App\Decks\Storage;
use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\Craft;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Skill;
use App\Programs\ProgramRunner;
use App\Support\Qb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** Code Crafting (CRAFTING.md): components and a design, over time, into a Script. */
final class CraftingTest extends TestCase
{
    use CarriesAWorkingDeck, RefreshDatabase;

    private Character $character;

    private Crafting $crafting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfMinute());
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character = $this->character->fresh();
        $this->crafting = Crafting::make();

        foreach (['code_crafting', 'script_crafting'] as $slug) {
            app(SkillInjector::class)->grant($this->character, Skill::query()->where('slug', $slug)->firstOrFail(), 1);
        }
    }

    private function item(string $slug): Item
    {
        return Item::query()->where('slug', $slug)->firstOrFail();
    }

    private function design(string $kind = 'original'): InventoryItem
    {
        return InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $this->item("dummy-temporary-program-design-{$kind}")->id, 'quantity' => 1]);
    }

    /** One component file of a given item, grade and (optionally) operating system. */
    private function file(string $slug, int $grade = 1, ?string $os = null): InventoryItem
    {
        return InventoryItem::create([
            'character_id' => $this->character->id, 'item_id' => $this->item($slug)->id, 'quantity' => 1,
            'extracted_file' => [
                'label' => 'x', 'size_qb' => 0.1, 'encryption_skill_id' => null, 'value_sealed' => 10, 'value_broken' => 20, 'grade' => $grade,
                'origin' => ['system_type' => 'relay', 'os_slug' => $os, 'os_title' => $os, 'ids_level' => 0, 'ice_level' => 0],
            ],
        ]);
    }

    /** The whole recipe of the first Script: 3 Memory Dumps (any grade), a Kernel Log (AuraOS), 2 Temporary Files, a Cache File. */
    private function stockRecipe(int $dumpGrade = 1): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->file('memory-dump', $dumpGrade);
        }

        $this->file('kernel-log', 3, 'auroos');
        $this->file('temporary-file');
        $this->file('temporary-file');
        $this->file('cache-file');
    }

    public function test_starting_spends_the_components_and_locks_the_build_in_with_the_skills_of_the_moment(): void
    {
        $this->stockRecipe();
        $design = $this->design();

        $craft = $this->crafting->start($this->character, $design);

        $this->assertInstanceOf(Craft::class, $craft);
        $this->assertSame(0, InventoryItem::query()->where('character_id', $this->character->id)->whereNotNull('extracted_file')->count(), 'every component is spent');
        $this->assertSame(1, InventoryItem::query()->whereKey($design->id)->count(), 'a Design Original is kept');
        $this->assertSame(558, $craft->required_seconds, 'Code Crafting 1 (-4%) and Script Crafting 1 (-3%) take 7% off, worked out when the build started');
    }

    public function test_a_design_copy_is_spent_by_the_craft(): void
    {
        $this->stockRecipe();
        $copy = $this->design('copy');

        $this->assertInstanceOf(Craft::class, $this->crafting->start($this->character, $copy));
        $this->assertSame(0, InventoryItem::query()->whereKey($copy->id)->count());
    }

    public function test_the_lowest_grade_that_qualifies_is_spent_first_and_a_higher_one_asks_first(): void
    {
        $this->stockRecipe();
        $high = $this->file('memory-dump', 6);
        $design = $this->design();

        // Three grade 1 dumps are enough, so the grade 6 one is not touched.
        $this->assertInstanceOf(Craft::class, $this->crafting->start($this->character, $design));
        $this->assertSame(1, InventoryItem::query()->whereKey($high->id)->count(), 'the better file was not burned');

        // Now only high grades are left to spend: it is allowed, but only once the player has said so.
        $this->crafting->cancel($this->character, Craft::query()->firstOrFail());
        $this->file('memory-dump', 5);
        $this->file('memory-dump', 5);
        foreach (['kernel-log' => 'auroos', 'temporary-file' => null, 'cache-file' => null] as $slug => $os) {
            $this->file($slug, 3, $os);
        }
        $this->file('temporary-file');

        $this->assertSame(CraftingRefusal::WastesGrade, $this->crafting->start($this->character, $design));
        $this->assertInstanceOf(Craft::class, $this->crafting->start($this->character, $design, confirmWaste: true));
    }

    public function test_it_is_refused_for_missing_components_a_missing_skill_a_full_slot_and_no_working_deck(): void
    {
        $design = $this->design();
        $this->assertSame(CraftingRefusal::MissingComponents, $this->crafting->start($this->character, $design));

        $this->stockRecipe();
        $this->assertInstanceOf(Craft::class, $this->crafting->start($this->character, $design));

        $this->stockRecipe();
        $this->assertSame(CraftingRefusal::NoFreeSlot, $this->crafting->start($this->character, $design), 'one slot until a skill says otherwise');

        $other = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($other);
        $row = InventoryItem::create(['character_id' => $other->id, 'item_id' => $this->item('dummy-temporary-program-design-original')->id, 'quantity' => 1]);
        $this->assertSame(CraftingRefusal::NoSkill, $this->crafting->start($other->fresh(), $row));
    }

    public function test_a_filter_on_origin_only_accepts_files_that_came_from_there(): void
    {
        $this->stockRecipe();
        $kernel = InventoryItem::query()->where('item_id', $this->item('kernel-log')->id)->sole();
        $kernel->forceFill(['extracted_file' => [...$kernel->extracted_file, 'origin' => [...$kernel->extracted_file['origin'], 'os_slug' => 'prismv']]])->save();

        $this->assertSame(CraftingRefusal::MissingComponents, $this->crafting->start($this->character, $this->design()), 'the Kernel Log is for another operating system');
    }

    public function test_the_clock_runs_only_while_the_deck_is_ready_and_a_deck_change_credits_the_time_before_it(): void
    {
        $this->stockRecipe();
        $craft = $this->crafting->start($this->character, $this->design());

        $this->travel(120)->seconds();
        $this->crafting->settle($this->character);
        $this->assertSame(120, $craft->fresh()->progress_seconds);

        // Putting the deck down: stopAll settles first, so the two minutes before it count and the ones after do not.
        $this->travel(60)->seconds();
        app(ProgramRunner::class)->stopAll($this->character, $this->character->equippedDeck);
        $this->assertSame(180, $craft->fresh()->progress_seconds);

        $this->character->forceFill(['equipped_deck_id' => null])->save();
        $this->travel(300)->seconds();
        $this->crafting->settle($this->character->fresh());
        $this->assertSame(180, $craft->fresh()->progress_seconds, 'nothing is built with no deck');
    }

    public function test_a_finished_build_delivers_its_program_and_says_so(): void
    {
        $this->stockRecipe();
        $this->crafting->start($this->character, $this->design());

        $this->travel(601)->seconds();
        $this->assertSame(1, $this->crafting->settle($this->character->fresh()));

        $this->assertSame(0, Craft::query()->count());
        $this->assertSame(1, (int) InventoryItem::query()->where('character_id', $this->character->id)->where('item_id', $this->item('dummy-temporary-program')->id)->sum('quantity'));
        $this->assertSame('Built: DUMMY_TEMPORARY_PROGRAM', Notification::query()->latest('id')->firstOrFail()->title);
    }

    public function test_a_finished_build_with_no_room_waits_holding_its_slot_until_there_is_room(): void
    {
        $this->stockRecipe();
        $this->crafting->start($this->character, $this->design());
        // Fill the deck to within a tenth: not over (an overfull deck will not run, and the clock would stop), but no room for 0.4 QB.
        $filler = InventoryItem::create([
            'character_id' => $this->character->id,
            'item_id' => Item::factory()->data()->unstackable()->create(['size' => Qb::of(Storage::free($this->character->fresh()) - 0.1)])->id,
            'quantity' => 1,
        ]);

        $this->travel(601)->seconds();
        $this->assertSame(0, $this->crafting->settle($this->character->fresh()));
        $this->assertTrue(Craft::query()->sole()->isFinished(), 'done, and still holding its slot');

        $filler->delete();
        $this->assertSame(1, $this->crafting->settle($this->character->fresh()));
        $this->assertSame(0, Craft::query()->count());
    }

    public function test_giving_a_build_up_forfeits_the_components(): void
    {
        $this->stockRecipe();
        $this->crafting->start($this->character, $this->design());

        $this->assertTrue($this->crafting->cancel($this->character, Craft::query()->sole()));
        $this->assertSame(0, InventoryItem::query()->where('character_id', $this->character->id)->whereNotNull('extracted_file')->count());
    }

    public function test_the_deck_panel_shows_the_designs_and_the_builds(): void
    {
        $this->stockRecipe();
        $this->design();

        $this->actingAs($this->character->user)->get(route('deck.show', $this->character->equipped_deck_id))
            ->assertOk()->assertDontSee('isNotEmpty()', false)->assertSee('DUMMY_TEMPORARY_PROGRAM')->assertSee('Design Original')->assertSee('Memory Dump (grade 1 or higher)');
    }

    public function test_components_of_one_kind_and_origin_are_one_slot_in_the_stash_but_stay_separate_rows(): void
    {
        $this->file('kernel-log', 1, 'auroos');
        $this->file('kernel-log', 3, 'auroos');
        $this->file('kernel-log', 2, 'auroos');
        $this->file('kernel-log', 2, 'prismv');
        $this->file('cache-file');

        $html = $this->actingAs($this->character->user)->get(route('inventory.index'))->assertOk()->getContent();

        // Three AuraOS Kernel Logs are one slot (and so one group card); the Prism-V one and the Cache File are slots of their own.
        $this->assertSame(1, substr_count($html, 'data-inspect="#group-'), 'one group slot');
        $this->assertSame(2, substr_count($html, 'data-inspect="#detail-') - $this->nonComponentSlots($html), 'the Prism-V log and the Cache File keep a slot each');
        $this->assertStringContainsString('Kernel Log (auroos)', $html);
        $this->assertStringContainsString('3 files', $html);
        $this->assertSame(5, InventoryItem::query()->where('character_id', $this->character->id)->whereNotNull('extracted_file')->count(), 'nothing was merged');
    }

    /** Slots that are not these five components (the deck and its modules are in the stash too). */
    private function nonComponentSlots(string $html): int
    {
        $components = InventoryItem::query()->where('character_id', $this->character->id)->whereNotNull('extracted_file')->pluck('id')->all();
        preg_match_all('/data-inspect="#detail-(\d+)"/', $html, $found);

        return count(array_filter($found[1], fn (string $id): bool => ! in_array((int) $id, $components, true)));
    }
}
