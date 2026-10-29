<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CharacterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_table_has_every_attribute_column(): void
    {
        foreach (Character::ATTRIBUTES as $attribute) {
            $this->assertTrue(
                Schema::hasColumn('characters', $attribute),
                "characters table is missing the [{$attribute}] column"
            );
        }

        $this->assertCount(10, Character::ATTRIBUTES);
    }

    /**
     * The migrations hardcode their own column lists so they stay historical.
     * This catches the model and the schema drifting apart — it is what caught
     * the resource columns being added without the model knowing about them.
     */
    public function test_the_model_and_the_schema_agree_on_the_attribute_list(): void
    {
        $columns = Schema::getColumnListing('characters');
        $expected = [
            'id', 'user_id', 'handle',
            // The only column on this row the game never reads: the
            // player's own words about themselves.
            'bio',
            'archetype',
            // Where the body is: always set. See CITY.md.
            'district_id',
            ...Character::ATTRIBUTES,
            ...array_keys(Character::RESOURCES),
            // Money, named on its own because it is neither: stored and
            // mutable like a resource, but with no derived maximum to clamp
            // against. See the Character docblock.
            'creds',
            // What the world knows about this character, from traces that
            // completed. NOT the run's thermal load, which is a different
            // quantity that used to share the word.
            'heat',
            // Which deck is live. A column here rather than a flag on the
            // inventory row, so "only one at a time" is not a rule anybody
            // has to enforce — one column holds one value. NULL is carrying
            // no deck, which is the ordinary state. NOT jacked out: that is
            // the pair below, and equipping was never either of these.
            'equipped_deck_id',
            // Where they are standing in cyberspace, and therefore whether
            // they are in it at all. Null together or set together — the
            // schema's CHECK says so — because half a position is not a
            // place. Jacked out is the ordinary state.
            'grid_x', 'grid_y',
            // The run they are inside, or NULL. A column for the reason
            // equipped_deck_id is one: "only one at a time" is not a rule
            // anybody enforces when one column holds one value. It is also
            // what App\Http\Middleware\EnsureRunIsFinished reads on every
            // request, which is why it is a column and not a lookup.
            'current_run_id',
            // The hunter they are fighting, or NULL. Same reason as
            // current_run_id: App\Http\Middleware\EnsureHuntIsResolved reads
            // it on every request.
            'current_hunt_id', 'current_fight_id', 'current_encounter_id',
            // The two weapons they are holding, one for each range, or NULL: bare hands.
            'equipped_ranged_id', 'equipped_melee_id',
            // The two standing instructions: when to pull out of a run. A play
            // style rather than a per-run choice, which is why they live on the
            // character and persist. Zero turns one off.
            'egress_trace_at', 'egress_si_at',
            // On the floor, and what put them there. Non-null waiting_until is
            // what "down" means — one column, so nothing can disagree about
            // whether somebody is up. The cause is JSON because none of what it
            // describes is built, and columns modelled against an unwritten
            // simulator get modelled wrong.
            'waiting_until', 'waiting_cause',
            // When word last went out that somebody would pay for them. The
            // bounty roll's memory, so certain odds are not news every night.
            'bounty_word_at',
            // The sum on their head, and the grade it will be priced from.
            'bounty', 'bounty_grade',
            // When Tasks & Missions was last opened: the rail badge's clock.
            'tasks_seen_at',
            // The third resource, from nothing up to fatigue_max. See App\Characters\Fatigue.
            'current_fatigue', 'current_fullness', 'mood', 'collapse_pending',
            // Their home, or NULL for the fallback.
            'rent_due_at', 'raid_cooldown_until', 'safehouse_id',
            // Safehouse training sessions: the Work out and Study meters, and Simstim's daily allowance.
            // Background programs stopped at the briefing for the run now under way, to start again when it ends.
            'suspended_programs',
            'workout_progress', 'study_progress', 'simstim_seconds', 'simstim_day',
            // The grid the body is on, or was last on (grids; 1 is the main grid).
            'grid_id',
            // Where a jack-in resumes on a subnet (NULL: the main portal).
            'resume_x', 'resume_y', 'virus_damage', 'virus_turns', 'virus_elapsed',
            'created_at', 'updated_at',
        ];

        sort($columns);
        sort($expected);

        $this->assertSame($expected, $columns);
    }

    public function test_a_user_has_one_character(): void
    {
        $user = User::factory()->create();
        $character = Character::factory()->for($user)->create();

        $this->assertTrue($user->fresh()->character->is($character));
        $this->assertTrue($character->user->is($user));
    }

    public function test_a_user_cannot_have_two_characters(): void
    {
        $user = User::factory()->create();
        Character::factory()->for($user)->create();

        $this->expectException(QueryException::class);
        Character::factory()->for($user)->create();
    }

    public function test_handles_are_unique_across_characters(): void
    {
        Character::factory()->create(['handle' => 'zer0c00l']);

        $this->expectException(QueryException::class);
        Character::factory()->create(['handle' => 'zer0c00l']);
    }

    public function test_deleting_a_user_deletes_their_character(): void
    {
        $character = Character::factory()->create();
        $this->assertDatabaseCount('characters', 1);

        $character->user->delete();

        $this->assertDatabaseCount('characters', 0);
    }

    public function test_attributes_default_to_zero(): void
    {
        $user = User::factory()->create();
        $character = Character::create(['user_id' => $user->id, 'handle' => 'fresh']);

        foreach (Character::ATTRIBUTES as $attribute) {
            $this->assertSame(0, $character->fresh()->{$attribute});
        }
    }

    public function test_archetype_is_a_nullable_integer(): void
    {
        $character = Character::factory()->create(['archetype' => null]);
        $this->assertNull($character->fresh()->archetype);

        $character->update(['archetype' => 3]);
        $this->assertSame(3, $character->fresh()->archetype);
    }

    public function test_the_model_clamps_scores_into_range(): void
    {
        $character = Character::factory()->create(['intellect' => 250, 'muscle' => -20]);

        $this->assertSame(Character::ATTRIBUTE_MAX, $character->fresh()->intellect);
        $this->assertSame(Character::ATTRIBUTE_MIN, $character->fresh()->muscle);
    }

    public function test_attribute_rules_cover_every_attribute(): void
    {
        $rules = Character::attributeRules();

        $this->assertSame(Character::ATTRIBUTES, array_keys($rules));

        // The default rules carry the LIFETIME bounds: 0..100.
        foreach ($rules as $rule) {
            $this->assertContains('min:'.Character::ATTRIBUTE_MIN, $rule);
            $this->assertContains('max:'.Character::ATTRIBUTE_MAX, $rule);
        }

        // The creation rules are tighter at both ends: 1..50.
        foreach (Character::creationAttributeRules() as $rule) {
            $this->assertContains('min:'.Character::CREATION_ATTRIBUTE_MIN, $rule);
            $this->assertContains('max:'.Character::CREATION_ATTRIBUTE_MAX, $rule);
        }
    }

    public function test_attribute_scores_returns_all_ten(): void
    {
        $character = Character::factory()->maxed()->create();

        $scores = $character->attributeScores();

        $this->assertSame(Character::ATTRIBUTES, array_keys($scores));
        $this->assertSame(array_fill(0, 10, Character::ATTRIBUTE_MAX), array_values($scores));
    }

    /**
     * The clamp above protects ordinary code; the CHECK constraints protect
     * against raw writes that bypass Eloquent entirely. SQLite cannot add a
     * constraint via ALTER TABLE, so this only runs against MySQL.
     */
    public function test_the_database_rejects_an_out_of_range_raw_write(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints are only installed on MySQL; see the migration.');
        }

        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('characters')->insert([
            'user_id' => $user->id,
            'handle' => 'raw',
            'intellect' => 101,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
