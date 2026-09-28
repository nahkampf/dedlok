<?php

namespace Tests\Feature\Admin;

use App\Inventory\Imprint;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Models\SkillPrerequisite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The skill creator and editor.
 *
 * A skill is never only a skill: it is a row, a set of graph edges, and a
 * book. The book is the part most easily forgotten and the part that decides
 * whether the skill can be learned at all, so most of this is about the two
 * staying together — and about the graph staying acyclic, which is the one
 * error no single row can show.
 */
class SkillAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'cryptography',
            'title' => 'Cryptography',
            'description' => 'Breaking what was meant to hold.',
            'category' => 'intrusion',
            'rank' => 4,
            'primary_attribute' => 'intellect',
            'secondary_attribute' => 'focus',
            'base_cost' => 900,
            'on_market' => '1',
        ], $overrides);
    }

    // ---- creating, and the book that comes with it ------------------------

    public function test_creating_a_skill_creates_its_book(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/skills', $this->payload())
            ->assertRedirect();

        $skill = Skill::where('slug', 'cryptography')->firstOrFail();
        $book = Imprint::forSkill($skill);

        $this->assertNotNull($book);
        $this->assertSame('imprint_cryptography', $book->slug);
        $this->assertSame(Item::CONTAINER_DATA, $book->container);
        $this->assertSame(Item::USE_INJECT_SKILL, $book->use_op);
        $this->assertSame($skill->id, $book->use_skill_id);
    }

    /** Exactly the seeder's rules, because it is exactly the seeder's code. */
    public function test_the_book_derives_its_numbers_from_the_rank(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/skills', $this->payload(['rank' => 4, 'base_cost' => 900]))
            ->assertRedirect();

        $book = Imprint::forSkill(Skill::where('slug', 'cryptography')->firstOrFail());

        $this->assertSame(2, $book->tier);              // log2(4) + 1, capped at 2
        $this->assertSame('rare', $book->rarity->value); // rank 4
        $this->assertSame(900, $book->base_cost);        // from the skill
        $this->assertSame(8, $book->seed_quantity);      // 32 / 4
        $this->assertSame(4, $book->size);               // QB equal to the rank
    }

    public function test_a_book_has_no_name_of_its_own(): void
    {
        $this->actingAs($this->admin)->post('/admin/skills', $this->payload())->assertRedirect();

        $skill = Skill::where('slug', 'cryptography')->firstOrFail();
        $book = Imprint::forSkill($skill);

        $this->assertNull($book->name);
        $this->assertSame('Eidetic Imprint: Cryptography', $book->displayName());
    }

    public function test_a_slug_must_be_unique_and_well_formed(): void
    {
        Skill::factory()->create(['slug' => 'taken']);

        $this->actingAs($this->admin)
            ->post('/admin/skills', $this->payload(['slug' => 'taken']))
            ->assertSessionHasErrors('slug');

        $this->actingAs($this->admin)
            ->post('/admin/skills', $this->payload(['slug' => 'Not A Slug']))
            ->assertSessionHasErrors('slug');
    }

    public function test_a_rank_outside_the_scale_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/skills', $this->payload(['rank' => 3]))
            ->assertSessionHasErrors('rank');
    }

    /** Governing on one attribute twice earns points from it twice. */
    public function test_the_two_attributes_must_differ(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/skills', $this->payload([
                'primary_attribute' => 'intellect',
                'secondary_attribute' => 'intellect',
            ]))
            ->assertSessionHasErrors('secondary_attribute');
    }

    // ---- prerequisites ----------------------------------------------------

    public function test_requirements_are_saved(): void
    {
        $hacking = Skill::factory()->create(['slug' => 'hacking']);

        $this->actingAs($this->admin)
            ->post('/admin/skills', $this->payload([
                'prerequisites' => [
                    ['required_skill_id' => $hacking->id, 'required_level' => 3],
                ],
            ]))
            ->assertRedirect();

        $skill = Skill::where('slug', 'cryptography')->firstOrFail();

        $this->assertDatabaseHas('skill_prerequisites', [
            'skill_id' => $skill->id,
            'required_skill_id' => $hacking->id,
            'required_level' => 3,
        ]);
    }

    public function test_saving_replaces_the_requirements_wholesale(): void
    {
        $skill = Skill::factory()->create(['slug' => 'cryptography']);
        $old = Skill::factory()->create();
        $new = Skill::factory()->create();

        SkillPrerequisite::create([
            'skill_id' => $skill->id, 'required_skill_id' => $old->id, 'required_level' => 2,
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/skills/{$skill->slug}", $this->payload([
                'slug' => null,
                'prerequisites' => [
                    ['required_skill_id' => $new->id, 'required_level' => 1],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('skill_prerequisites', ['required_skill_id' => $old->id]);
        $this->assertDatabaseHas('skill_prerequisites', ['required_skill_id' => $new->id]);
    }

    public function test_a_skill_cannot_require_itself(): void
    {
        $skill = Skill::factory()->create(['slug' => 'cryptography']);

        $this->actingAs($this->admin)
            ->put("/admin/skills/{$skill->slug}", $this->payload([
                'slug' => null,
                'prerequisites' => [
                    ['required_skill_id' => $skill->id, 'required_level' => 1],
                ],
            ]))
            ->assertSessionHasErrors('prerequisites.0.required_skill_id');
    }

    public function test_the_same_skill_cannot_be_required_twice(): void
    {
        $skill = Skill::factory()->create(['slug' => 'cryptography']);
        $hacking = Skill::factory()->create();

        $this->actingAs($this->admin)
            ->put("/admin/skills/{$skill->slug}", $this->payload([
                'slug' => null,
                'prerequisites' => [
                    ['required_skill_id' => $hacking->id, 'required_level' => 1],
                    ['required_skill_id' => $hacking->id, 'required_level' => 3],
                ],
            ]))
            ->assertSessionHasErrors('prerequisites.0.required_skill_id');
    }

    /**
     * The error no single row can show: A needs B, B needs A, and neither can
     * ever be learned. Nothing about either row looks wrong on its own.
     */
    public function test_a_requirement_that_closes_a_loop_is_refused(): void
    {
        $a = Skill::factory()->create(['slug' => 'alpha']);
        $b = Skill::factory()->create(['slug' => 'beta']);

        SkillPrerequisite::create([
            'skill_id' => $b->id, 'required_skill_id' => $a->id, 'required_level' => 1,
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/skills/{$a->slug}", $this->payload([
                'slug' => null,
                'prerequisites' => [
                    ['required_skill_id' => $b->id, 'required_level' => 1],
                ],
            ]))
            ->assertSessionHasErrors('prerequisites');

        $this->assertDatabaseMissing('skill_prerequisites', ['skill_id' => $a->id]);
    }

    /** A longer loop is the same error, and just as invisible per row. */
    public function test_a_three_step_loop_is_refused(): void
    {
        $a = Skill::factory()->create(['slug' => 'alpha']);
        $b = Skill::factory()->create(['slug' => 'beta']);
        $c = Skill::factory()->create(['slug' => 'gamma']);

        SkillPrerequisite::create(['skill_id' => $b->id, 'required_skill_id' => $a->id, 'required_level' => 1]);
        SkillPrerequisite::create(['skill_id' => $c->id, 'required_skill_id' => $b->id, 'required_level' => 1]);

        $this->actingAs($this->admin)
            ->put("/admin/skills/{$a->slug}", $this->payload([
                'slug' => null,
                'prerequisites' => [
                    ['required_skill_id' => $c->id, 'required_level' => 1],
                ],
            ]))
            ->assertSessionHasErrors('prerequisites');
    }

    /** A diamond is not a cycle, and the editor must not confuse the two. */
    public function test_a_diamond_dependency_is_allowed(): void
    {
        $root = Skill::factory()->create();
        $left = Skill::factory()->create();
        $right = Skill::factory()->create();

        SkillPrerequisite::create(['skill_id' => $left->id, 'required_skill_id' => $root->id, 'required_level' => 1]);
        SkillPrerequisite::create(['skill_id' => $right->id, 'required_skill_id' => $root->id, 'required_level' => 1]);

        $this->actingAs($this->admin)
            ->post('/admin/skills', $this->payload([
                'prerequisites' => [
                    ['required_skill_id' => $left->id, 'required_level' => 1],
                    ['required_skill_id' => $right->id, 'required_level' => 1],
                ],
            ]))
            ->assertSessionHasNoErrors();
    }

    // ---- editing ----------------------------------------------------------

    public function test_the_book_follows_the_skills_cost_and_listing(): void
    {
        $skill = Skill::factory()->create(['slug' => 'cryptography', 'base_cost' => 100]);
        $book = Item::factory()->imprintFor($skill)->create(['base_cost' => 100, 'on_market' => true]);

        $this->actingAs($this->admin)
            ->put("/admin/skills/{$skill->slug}", $this->payload([
                'slug' => null,
                'base_cost' => 7_500,
                'on_market' => null,
            ]))
            ->assertSessionHasNoErrors();

        $book->refresh();

        $this->assertSame(7_500, $book->base_cost);
        $this->assertFalse($book->on_market);
    }

    public function test_a_slug_cannot_be_changed(): void
    {
        $skill = Skill::factory()->create(['slug' => 'cryptography']);

        $this->actingAs($this->admin)
            ->put("/admin/skills/{$skill->slug}", $this->payload(['slug' => 'renamed']))
            ->assertSessionHasErrors('slug');

        $this->assertSame('cryptography', $skill->fresh()->slug);
    }

    // ---- deleting ---------------------------------------------------------

    /**
     * items.use_skill_id restricts rather than cascades, so the book has to go
     * first or the database refuses the whole delete.
     */
    public function test_deleting_a_skill_takes_its_book_and_everyones_progress(): void
    {
        $character = Character::factory()->create();
        $skill = Skill::factory()->create(['slug' => 'cryptography']);
        $book = Item::factory()->imprintFor($skill)->create();

        $row = InventoryItem::factory()->ownedBy($character)->of($book)->create();
        CharacterSkill::create([
            'character_id' => $character->id, 'skill_id' => $skill->id,
            'skill_points' => 500, 'attained_level' => 1,
        ]);

        $this->actingAs($this->admin)
            ->delete("/admin/skills/{$skill->slug}")
            ->assertRedirect(route('admin.skills.index'));

        $this->assertDatabaseMissing('skills', ['id' => $skill->id]);
        $this->assertDatabaseMissing('items', ['id' => $book->id]);
        $this->assertDatabaseMissing('inventory_items', ['id' => $row->id]);
        $this->assertDatabaseMissing('character_skills', ['skill_id' => $skill->id]);
    }

    public function test_deleting_a_skill_clears_requirements_naming_it(): void
    {
        $skill = Skill::factory()->create(['slug' => 'cryptography']);
        $dependent = Skill::factory()->create();

        SkillPrerequisite::create([
            'skill_id' => $dependent->id, 'required_skill_id' => $skill->id, 'required_level' => 1,
        ]);

        $this->actingAs($this->admin)->delete("/admin/skills/{$skill->slug}")->assertRedirect();

        $this->assertDatabaseMissing('skill_prerequisites', ['required_skill_id' => $skill->id]);
        $this->assertDatabaseHas('skills', ['id' => $dependent->id]);
    }

    public function test_the_edit_screen_reports_what_a_delete_would_reach(): void
    {
        $character = Character::factory()->create();
        $skill = Skill::factory()->create(['slug' => 'cryptography']);
        Item::factory()->imprintFor($skill)->create();

        CharacterSkill::create([
            'character_id' => $character->id, 'skill_id' => $skill->id,
            'skill_points' => 0, 'attained_level' => 0,
        ]);

        $this->actingAs($this->admin)
            ->get("/admin/skills/{$skill->slug}/edit")
            ->assertOk()
            ->assertSee('its imprint')
            // escape defaults to true: Blade renders the apostrophe as &#039;.
            ->assertSee("1 character's progress in it");
    }

    /** A skill with no book cannot be learned; the screen has to say so. */
    public function test_a_skill_without_a_book_is_flagged(): void
    {
        $skill = Skill::factory()->create(['slug' => 'orphan']);

        $this->actingAs($this->admin)
            ->get("/admin/skills/{$skill->slug}/edit")
            ->assertOk()
            ->assertSee('This skill has no imprint');
    }
}
