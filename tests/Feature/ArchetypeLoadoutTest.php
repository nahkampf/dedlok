<?php

namespace Tests\Feature;

use App\Archetypes\LoadoutGranter;
use App\Http\Controllers\CharacterCreationController;
use App\Inventory\InjectionRefusal;
use App\Inventory\SkillInjector;
use App\Models\Archetype;
use App\Models\ArchetypeItem;
use App\Models\ArchetypeSkill;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Item;
use App\Models\Setting;
use App\Models\Skill;
use App\Models\SkillPrerequisite;
use App\Models\User;
use App\Skills\SkillProgression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What an archetype hands a character on the way in.
 *
 * Three things with three different homes: cash on the character row, skills
 * through the injector, items as inventory rows. The thing worth testing about
 * all three is that they land together — the grant runs inside the transaction
 * that creates the character — and that the skills land in a state training
 * would have produced, points and level agreeing.
 */
class ArchetypeLoadoutTest extends TestCase
{
    use RefreshDatabase;

    private const POINTS = 150;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put(CharacterCreationController::POINTS_SETTING, (string) self::POINTS, 'character_creation');
    }

    /** A valid creation payload whose attributes sum to exactly the pool. */
    private function payload(int $archetype = 1, array $overrides = []): array
    {
        $attributes = Character::startingAttributes(self::POINTS);
        $attributes[Character::ATTRIBUTES[0]] += self::POINTS - array_sum($attributes);

        return array_merge($attributes, ['handle' => 'zer0c00l', 'archetype' => $archetype], $overrides);
    }

    // ---- cash ------------------------------------------------------------

    public function test_a_new_character_starts_with_nothing_by_default(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload())
            ->assertRedirect(route('home'));

        $character = Character::firstOrFail();

        $this->assertSame(0, $character->creds);
        $this->assertSame(0, $character->characterSkills()->count());
        $this->assertSame(0, $character->inventory()->count());
    }

    public function test_the_archetype_hands_over_its_starting_creds(): void
    {
        Archetype::whereKey(1)->update(['starting_creds' => 2500]);

        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(1));

        $this->assertSame(2500, Character::firstOrFail()->creds);
    }

    public function test_creds_are_the_chosen_archetypes_and_not_another(): void
    {
        Archetype::whereKey(1)->update(['starting_creds' => 2500]);
        // Offered as well as defined: only the deck jockey ships offered, so
        // anything else a test wants to choose has to be put on the menu.
        Archetype::whereKey(2)->update(['starting_creds' => 40, 'playable' => true]);

        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(2));

        $this->assertSame(40, Character::firstOrFail()->creds);
    }

    // ---- skills ----------------------------------------------------------

    public function test_a_starting_skill_lands_on_the_sheet_injected_and_untrained(): void
    {
        $skill = Skill::factory()->create();
        ArchetypeSkill::create(['archetype_id' => 1, 'skill_id' => $skill->id, 'level' => 0]);

        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(1));

        $progress = CharacterSkill::firstOrFail();

        $this->assertSame($skill->id, $progress->skill_id);
        $this->assertSame(0, $progress->skill_points);
        $this->assertSame(0, $progress->attained_level);

        // Level 0 is a real state, and the training gate is a row-exists check.
        $this->assertTrue(Character::firstOrFail()->hasInjected($skill));
    }

    public function test_a_granted_level_seeds_exactly_the_points_that_level_costs(): void
    {
        $skill = Skill::factory()->rank(2)->create();
        ArchetypeSkill::create(['archetype_id' => 1, 'skill_id' => $skill->id, 'level' => 2]);

        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(1));

        $progress = CharacterSkill::firstOrFail();

        $this->assertSame($skill->pointsForLevel(2), $progress->skill_points);
        $this->assertSame(2, $progress->attained_level);

        // The stored level and the level the points imply must agree, or
        // skills:validate reports drift the moment the character is made.
        $this->assertSame($progress->attained_level, $progress->fresh()->derivedLevel());
    }

    public function test_granting_a_skill_spends_no_book(): void
    {
        $skill = Skill::factory()->create();
        ArchetypeSkill::create(['archetype_id' => 1, 'skill_id' => $skill->id, 'level' => 1]);

        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(1));

        // No imprint was owned and none is left behind: the grant is not an
        // injection with the imprint quietly handed over first.
        $this->assertSame(0, Character::firstOrFail()->inventory()->count());
    }

    public function test_the_injector_refuses_to_grant_a_skill_already_known(): void
    {
        $character = Character::factory()->create();
        $skill = Skill::factory()->create();
        $injector = app(SkillInjector::class);

        $this->assertTrue($injector->grant($character, $skill, 2)->succeeded());

        $second = $injector->grant($character, $skill, 4);

        $this->assertFalse($second->succeeded());
        $this->assertSame(InjectionRefusal::AlreadyKnown, $second->refusal);

        // And the progress it already had is untouched.
        $this->assertSame(2, CharacterSkill::firstOrFail()->attained_level);
    }

    public function test_a_level_outside_the_progression_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SkillInjector::class)->grant(
            Character::factory()->create(),
            Skill::factory()->create(),
            SkillProgression::fromConfig()->maxLevel() + 1,
        );
    }

    // ---- items -----------------------------------------------------------

    public function test_starting_items_arrive_loose_in_the_inventory(): void
    {
        $item = Item::factory()->physical()->create(['stackable' => true]);
        ArchetypeItem::create(['archetype_id' => 1, 'item_id' => $item->id, 'quantity' => 3]);

        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(1));

        $character = Character::firstOrFail();
        $rows = $character->inventory()->get();

        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows->first()->quantity);
        $this->assertNull($rows->first()->parent_id);

        // Which inventory it shows up in is the item's own business.
        $this->assertCount(1, $character->inventoryIn(Item::CONTAINER_PHYSICAL));
        $this->assertCount(0, $character->inventoryIn(Item::CONTAINER_DATA));
    }

    public function test_an_unstackable_item_arrives_as_one_row_per_copy(): void
    {
        $deck = Item::factory()->physical()->create(['stackable' => false]);
        ArchetypeItem::create(['archetype_id' => 1, 'item_id' => $deck->id, 'quantity' => 2]);

        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(1));

        $rows = Character::firstOrFail()->inventory()->get();

        $this->assertCount(2, $rows);
        $this->assertSame([1, 1], $rows->pluck('quantity')->all());
    }

    public function test_a_granted_imprint_can_still_be_injected(): void
    {
        $skill = Skill::factory()->create();
        $book = Item::factory()->imprintFor($skill)->create();
        ArchetypeItem::create(['archetype_id' => 1, 'item_id' => $book->id, 'quantity' => 1]);

        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(1));

        $character = Character::firstOrFail();
        $entry = $character->inventory()->firstOrFail();

        $this->assertTrue(app(SkillInjector::class)->inject($character, $entry)->succeeded());
        $this->assertTrue($character->hasInjected($skill));
    }

    // ---- all three together ---------------------------------------------

    public function test_the_whole_loadout_lands_with_the_character(): void
    {
        $skill = Skill::factory()->create();
        $item = Item::factory()->physical()->create(['stackable' => true]);

        Archetype::whereKey(3)->update(['starting_creds' => 900, 'playable' => true]);
        ArchetypeSkill::create(['archetype_id' => 3, 'skill_id' => $skill->id, 'level' => 1]);
        ArchetypeItem::create(['archetype_id' => 3, 'item_id' => $item->id, 'quantity' => 1]);

        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(3))
            ->assertRedirect(route('home'));

        $character = Character::firstOrFail();

        $this->assertSame(900, $character->creds);
        $this->assertSame(1, $character->characterSkills()->count());
        $this->assertSame(1, $character->inventory()->count());
    }

    public function test_a_character_with_no_archetype_is_granted_nothing(): void
    {
        $character = Character::factory()->create(['archetype' => null, 'creds' => 0]);

        app(LoadoutGranter::class)->grant($character, null);

        $this->assertSame(0, $character->fresh()->creds);
        $this->assertSame(0, $character->characterSkills()->count());
    }

    public function test_editing_a_loadout_does_not_reach_a_character_who_already_exists(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(1));

        $skill = Skill::factory()->create();
        Archetype::whereKey(1)->update(['starting_creds' => 5000]);
        ArchetypeSkill::create(['archetype_id' => 1, 'skill_id' => $skill->id, 'level' => 3]);

        $character = Character::firstOrFail();

        $this->assertSame(0, $character->creds);
        $this->assertSame(0, $character->characterSkills()->count());
    }

    // ---- the creation screen --------------------------------------------

    public function test_the_card_shows_what_the_archetype_hands_over(): void
    {
        $skill = Skill::factory()->create(['title' => 'Cold Boot Intrusion']);
        $item = Item::factory()->physical()->create(['name' => 'Chipped Deck', 'stackable' => false]);

        Archetype::whereKey(1)->update(['starting_creds' => 1250]);
        ArchetypeSkill::create(['archetype_id' => 1, 'skill_id' => $skill->id, 'level' => 2]);
        ArchetypeItem::create(['archetype_id' => 1, 'item_id' => $item->id, 'quantity' => 1]);

        $response = $this->actingAs(User::factory()->create())->get(route('character.create'));

        $response->assertOk();
        $response->assertSee('You start with');
        $response->assertSee('1 250');
        $response->assertSee('Cold Boot Intrusion');

        // Two of five lit, drawn with the kit's segmented meter rather than
        // spelled out — the number stays in the label for anyone who cannot
        // see the segments.
        $response->assertSee('--segs:5;--v:0.4;--fill:var(--ok)', false);
        $response->assertSee('Level 2 of 5');
        $response->assertSee('Chipped Deck');
        $response->assertSee('×1');
    }

    public function test_a_skill_granted_at_level_zero_draws_an_empty_meter(): void
    {
        $skill = Skill::factory()->create(['title' => 'Cold Boot Intrusion']);
        ArchetypeSkill::create(['archetype_id' => 1, 'skill_id' => $skill->id, 'level' => 0]);

        $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            // Granted and untrained: the skill is listed, and nothing is lit.
            ->assertSee('--segs:5;--v:0;--fill:var(--ok)', false)
            ->assertSee('Injected, untrained')
            ->assertDontSee('Level 0');
    }

    public function test_the_meter_has_one_segment_per_level_the_curve_allows(): void
    {
        $skill = Skill::factory()->create();
        ArchetypeSkill::create([
            'archetype_id' => 1,
            'skill_id' => $skill->id,
            'level' => SkillProgression::fromConfig()->maxLevel(),
        ]);

        // A full grant fills the bar exactly, whatever the curve's ceiling is.
        $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            ->assertSee('--segs:'.SkillProgression::fromConfig()->maxLevel().';--v:1;', false);
    }

    public function test_an_archetype_that_hands_over_nothing_shows_no_loadout_block(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            ->assertOk()
            ->assertDontSee('You start with');
    }

    // ---- the balance -----------------------------------------------------

    public function test_the_stash_shows_the_balance(): void
    {
        $user = User::factory()->create();
        Character::factory()->create(['user_id' => $user->id, 'creds' => 12345]);

        $this->actingAs($user)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('12 345');
    }

    // ---- the prerequisite that makes a granted level reachable -----------

    public function test_a_prerequisite_may_be_granted_alongside_the_skill_it_gates(): void
    {
        $base = Skill::factory()->create();
        $advanced = Skill::factory()->create();
        SkillPrerequisite::create([
            'skill_id' => $advanced->id,
            'required_skill_id' => $base->id,
            'required_level' => 1,
        ]);

        ArchetypeSkill::create(['archetype_id' => 1, 'skill_id' => $base->id, 'level' => 1]);
        ArchetypeSkill::create(['archetype_id' => 1, 'skill_id' => $advanced->id, 'level' => 1]);

        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(1));

        $character = Character::firstOrFail();

        $this->assertSame(2, $character->characterSkills()->count());
        $this->assertTrue($character->canLearn($advanced));
    }
}
