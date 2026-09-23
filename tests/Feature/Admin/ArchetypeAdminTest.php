<?php

namespace Tests\Feature\Admin;

use App\Admin\DeletionImpact;
use App\Models\Archetype;
use App\Models\ArchetypeItem;
use App\Models\ArchetypeSkill;
use App\Models\Character;
use App\Models\Item;
use App\Models\Skill;
use App\Models\SkillPrerequisite;
use App\Models\User;
use App\Skills\SkillProgression;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authoring an archetype's loadout.
 *
 * The editor is three routes on purpose — no create, no destroy — because an
 * archetype id is written into every character row that chose it. What is
 * worth testing is the loadout: that it is replaced wholesale rather than
 * merged, and that the two rules no CHECK constraint can express are enforced.
 */
class ArchetypeAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** The whole form, with the identity fields the request requires. */
    private function payload(Archetype $archetype, array $overrides = []): array
    {
        return array_merge([
            'name' => $archetype->name,
            'role' => $archetype->role,
            'description' => $archetype->description,
            'starting_creds' => 0,
            'playable' => $archetype->playable ? '1' : '',
            'skills' => [],
            'items' => [],
        ], $overrides);
    }

    // ---- the shape of the resource ---------------------------------------

    public function test_the_roster_is_listed(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.archetypes.index'))
            ->assertOk()
            ->assertSee('deckjockey')
            ->assertSee('Deck Jockey');
    }

    public function test_there_is_no_way_to_create_or_destroy_one(): void
    {
        // Ids are permanent because character rows store them, so the roster
        // arrives by migration and leaves the same way.
        $this->assertFalse(app('router')->has('admin.archetypes.create'));
        $this->assertFalse(app('router')->has('admin.archetypes.store'));
        $this->assertFalse(app('router')->has('admin.archetypes.destroy'));
        $this->assertFalse(app('router')->has('admin.archetypes.show'));
    }

    public function test_a_player_cannot_reach_the_editor(): void
    {
        $archetype = Archetype::findOrFail(1);
        $user = User::factory()->create();
        Character::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get(route('admin.archetypes.edit', $archetype))->assertForbidden();
        $this->actingAs($user)
            ->put(route('admin.archetypes.update', $archetype), $this->payload($archetype))
            ->assertForbidden();
    }

    // ---- identity --------------------------------------------------------

    public function test_it_saves_the_identity_fields(): void
    {
        $archetype = Archetype::findOrFail(1);

        $this->actingAs($this->admin())
            ->put(route('admin.archetypes.update', $archetype), $this->payload($archetype, [
                'name' => 'Deck Jockey',
                'role' => 'Intrusion',
                'description' => 'You go where the data is.',
                'starting_creds' => 1500,
            ]))
            ->assertSessionHasNoErrors();

        $archetype->refresh();

        $this->assertSame('Intrusion', $archetype->role);
        $this->assertSame(1500, $archetype->starting_creds);
    }

    public function test_the_slug_is_refused_rather_than_ignored(): void
    {
        $archetype = Archetype::findOrFail(1);

        $this->actingAs($this->admin())
            ->put(route('admin.archetypes.update', $archetype), $this->payload($archetype, [
                'slug' => 'renamed',
            ]))
            ->assertSessionHasErrors('slug');

        $this->assertSame('deckjockey', $archetype->fresh()->slug);
    }

    public function test_the_id_is_refused_too(): void
    {
        $archetype = Archetype::findOrFail(1);

        $this->actingAs($this->admin())
            ->put(route('admin.archetypes.update', $archetype), $this->payload($archetype, ['id' => 99]))
            ->assertSessionHasErrors('id');

        $this->assertNotNull(Archetype::find(1));
    }

    // ---- offering and withdrawing ----------------------------------------

    public function test_an_archetype_is_withdrawn_by_a_checkbox_and_not_a_delete(): void
    {
        $archetype = Archetype::findOrFail(1);

        $this->assertTrue($archetype->playable);

        $this->actingAs($this->admin())
            ->put(route('admin.archetypes.update', $archetype), $this->payload($archetype, [
                'playable' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($archetype->fresh()->playable);

        // Withdrawn, not gone: the row is still there for everyone who chose it.
        $this->assertNotNull(Archetype::find(1));
    }

    public function test_an_archetype_can_be_put_back_on_the_menu(): void
    {
        $archetype = Archetype::findOrFail(2);

        $this->assertFalse($archetype->playable);

        $this->actingAs($this->admin())
            ->put(route('admin.archetypes.update', $archetype), $this->payload($archetype, [
                'playable' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($archetype->fresh()->playable);
    }

    public function test_the_list_says_which_are_offered(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.archetypes.index'))
            ->assertOk()
            ->assertSee(Text::get('admin.archetypes.offered.no'));
    }

    // ---- the loadout -----------------------------------------------------

    public function test_it_saves_starting_skills_and_items(): void
    {
        $archetype = Archetype::findOrFail(1);
        $skill = Skill::factory()->create();
        $item = Item::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.archetypes.update', $archetype), $this->payload($archetype, [
                'skills' => [['skill_id' => $skill->id, 'level' => 2]],
                'items' => [['item_id' => $item->id, 'quantity' => 4]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('archetype_skills', [
            'archetype_id' => 1, 'skill_id' => $skill->id, 'level' => 2,
        ]);
        $this->assertDatabaseHas('archetype_items', [
            'archetype_id' => 1, 'item_id' => $item->id, 'quantity' => 4,
        ]);
    }

    public function test_the_loadout_is_replaced_wholesale_and_not_merged(): void
    {
        $archetype = Archetype::findOrFail(1);
        $old = Skill::factory()->create();
        $new = Skill::factory()->create();

        ArchetypeSkill::create(['archetype_id' => 1, 'skill_id' => $old->id, 'level' => 1]);
        ArchetypeItem::create([
            'archetype_id' => 1,
            'item_id' => Item::factory()->create()->id,
            'quantity' => 1,
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.archetypes.update', $archetype), $this->payload($archetype, [
                'skills' => [['skill_id' => $new->id, 'level' => 0]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('archetype_skills', ['skill_id' => $old->id]);
        $this->assertDatabaseHas('archetype_skills', ['skill_id' => $new->id, 'level' => 0]);

        // An empty repeater means "grant nothing", not "leave it be".
        $this->assertSame(0, ArchetypeItem::count());
    }

    public function test_the_same_skill_cannot_be_granted_twice(): void
    {
        $archetype = Archetype::findOrFail(1);
        $skill = Skill::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.archetypes.update', $archetype), $this->payload($archetype, [
                'skills' => [
                    ['skill_id' => $skill->id, 'level' => 1],
                    ['skill_id' => $skill->id, 'level' => 3],
                ],
            ]))
            ->assertSessionHasErrors('skills.1.skill_id');

        $this->assertSame(0, ArchetypeSkill::count());
    }

    public function test_a_level_above_the_progression_is_refused(): void
    {
        $archetype = Archetype::findOrFail(1);
        $skill = Skill::factory()->create();
        $tooHigh = SkillProgression::fromConfig()->maxLevel() + 1;

        $this->actingAs($this->admin())
            ->put(route('admin.archetypes.update', $archetype), $this->payload($archetype, [
                'skills' => [['skill_id' => $skill->id, 'level' => $tooHigh]],
            ]))
            ->assertSessionHasErrors('skills.0.level');
    }

    public function test_a_quantity_below_one_is_refused(): void
    {
        $archetype = Archetype::findOrFail(1);

        $this->actingAs($this->admin())
            ->put(route('admin.archetypes.update', $archetype), $this->payload($archetype, [
                'items' => [['item_id' => Item::factory()->create()->id, 'quantity' => 0]],
            ]))
            ->assertSessionHasErrors('items.0.quantity');
    }

    // ---- the rule the schema cannot express ------------------------------

    public function test_a_trained_grant_needs_its_prerequisites_granted_too(): void
    {
        $archetype = Archetype::findOrFail(1);
        $base = Skill::factory()->create(['title' => 'Intrusion']);
        $advanced = Skill::factory()->create(['title' => 'Black ICE']);

        SkillPrerequisite::create([
            'skill_id' => $advanced->id,
            'required_skill_id' => $base->id,
            'required_level' => 2,
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.archetypes.update', $archetype), $this->payload($archetype, [
                'skills' => [['skill_id' => $advanced->id, 'level' => 1]],
            ]))
            ->assertSessionHasErrors('skills');

        $this->assertSame(0, ArchetypeSkill::count());
    }

    public function test_granting_the_prerequisite_as_well_is_accepted(): void
    {
        $archetype = Archetype::findOrFail(1);
        $base = Skill::factory()->create();
        $advanced = Skill::factory()->create();

        SkillPrerequisite::create([
            'skill_id' => $advanced->id,
            'required_skill_id' => $base->id,
            'required_level' => 2,
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.archetypes.update', $archetype), $this->payload($archetype, [
                'skills' => [
                    ['skill_id' => $base->id, 'level' => 2],
                    ['skill_id' => $advanced->id, 'level' => 1],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ArchetypeSkill::count());
    }

    public function test_level_zero_is_exempt_because_injection_never_checked_prerequisites(): void
    {
        $archetype = Archetype::findOrFail(1);
        $base = Skill::factory()->create();
        $advanced = Skill::factory()->create();

        SkillPrerequisite::create([
            'skill_id' => $advanced->id,
            'required_skill_id' => $base->id,
            'required_level' => 2,
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.archetypes.update', $archetype), $this->payload($archetype, [
                'skills' => [['skill_id' => $advanced->id, 'level' => 0]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ArchetypeSkill::count());
    }

    // ---- the two dropdowns -----------------------------------------------

    public function test_the_skill_list_is_grouped_by_category(): void
    {
        Skill::factory()->create(['category' => 'intrusion', 'title' => 'Cold Boot']);
        Skill::factory()->create(['category' => 'fabrication', 'title' => 'Soldering']);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.archetypes.edit', Archetype::findOrFail(1)));

        $response->assertOk();
        $response->assertSee('<optgroup label="intrusion">', false);
        $response->assertSee('<optgroup label="fabrication">', false);
    }

    public function test_the_item_list_is_grouped_by_what_kind_of_thing_it_is(): void
    {
        $skill = Skill::factory()->create();

        Item::factory()->imprintFor($skill)->create();
        Item::factory()->physical()->cyberdeck()->create(['name' => 'Chipped Deck']);
        Item::factory()->physical()->create(['name' => 'Crowbar']);
        Item::factory()->data()->create(['name' => 'Loose File']);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.archetypes.edit', Archetype::findOrFail(1)));

        $response->assertOk();
        $response->assertSee('<optgroup label="Carried">', false);
        $response->assertSee('<optgroup label="On the deck">', false);
        $response->assertSee('<optgroup label="Cyberdeck">', false);
        $response->assertSee('<optgroup label="Imprints">', false);
    }

    public function test_a_group_with_nothing_in_it_is_not_offered(): void
    {
        Item::factory()->physical()->create();

        $this->actingAs($this->admin())
            ->get(route('admin.archetypes.edit', Archetype::findOrFail(1)))
            ->assertSee('<optgroup label="Carried">', false)
            ->assertDontSee('<optgroup label="Power supply">', false);
    }

    public function test_a_granted_row_opens_on_what_it_grants(): void
    {
        $archetype = Archetype::findOrFail(1);
        $skill = Skill::factory()->create();
        $item = Item::factory()->physical()->create();

        ArchetypeSkill::create(['archetype_id' => 1, 'skill_id' => $skill->id, 'level' => 3]);
        ArchetypeItem::create(['archetype_id' => 1, 'item_id' => $item->id, 'quantity' => 2]);

        $response = $this->actingAs($this->admin())->get(route('admin.archetypes.edit', $archetype));

        $response->assertSee('<option value="'.$skill->id.'" selected>', false);
        $response->assertSee('<option value="'.$item->id.'" selected>', false);
    }

    // ---- what a delete would take with it --------------------------------

    public function test_deleting_an_item_reports_the_loadouts_it_would_empty(): void
    {
        $item = Item::factory()->create();
        ArchetypeItem::create(['archetype_id' => 1, 'item_id' => $item->id, 'quantity' => 1]);
        ArchetypeItem::create(['archetype_id' => 2, 'item_id' => $item->id, 'quantity' => 1]);

        $this->assertSame(2, DeletionImpact::forItem($item)->counts()['archetype_loadouts']);
    }

    public function test_deleting_a_skill_reports_the_loadouts_naming_it(): void
    {
        $skill = Skill::factory()->create();
        ArchetypeSkill::create(['archetype_id' => 1, 'skill_id' => $skill->id, 'level' => 0]);

        $this->assertSame(1, DeletionImpact::forSkill($skill)->counts()['archetype_loadouts']);
    }

    public function test_deleting_an_item_takes_the_grant_with_it(): void
    {
        $item = Item::factory()->create();
        ArchetypeItem::create(['archetype_id' => 1, 'item_id' => $item->id, 'quantity' => 1]);

        $this->actingAs($this->admin())
            ->delete(route('admin.items.destroy', $item))
            ->assertRedirect(route('admin.items.index'));

        $this->assertSame(0, ArchetypeItem::count());
    }
}
