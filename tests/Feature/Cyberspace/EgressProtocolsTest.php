<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Inventory\Imprint;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use App\Models\System;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Egress Protocols — the first thing in the game that helps you leave.
 *
 * The point of these is not the numbers, it is that the SIMULATOR never learns
 * the skill exists: the modifier targets the `egress` stat, the resolver does
 * the arithmetic, and the chance moves. Every future skill, implant or program
 * that helps somebody leave is the same row with a different source.
 */
final class EgressProtocolsTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));

        $this->equipReadyDeck($this->character);
        $this->character->forceFill([
            'grid_x' => 8, 'grid_y' => 8, 'egress_trace_at' => 0, 'egress_si_at' => 0,
        ])->save();
        $this->character->refresh();
    }

    // ---- the row itself ----------------------------------------------------

    public function test_the_skill_exists_as_the_brief_described_it(): void
    {
        $skill = $this->skill();

        $this->assertSame('Egress Protocols', $skill->title);
        $this->assertSame(8, $skill->rank);
        $this->assertSame('memory', $skill->primary_attribute);
        $this->assertSame('intellect', $skill->secondary_attribute);
        $this->assertTrue($skill->on_market, 'publicly available');
    }

    /** Five grants, one per level, every one +3% of base on the egress pool. */
    public function test_it_grants_three_per_cent_a_level(): void
    {
        $modifiers = $this->skill()->modifiers;

        $this->assertCount(5, $modifiers);
        $this->assertSame([1, 2, 3, 4, 5], array_column($modifiers, 'level'));

        foreach ($modifiers as $grant) {
            $this->assertSame('percentage_of_base', $grant['op']);
            $this->assertSame(3, $grant['amount']);
            $this->assertSame(['id' => 'egress'], $grant['target']);
        }
    }

    /**
     * The prerequisite, WHERE THE CATALOGUE EXISTS.
     *
     * The shipped skills are content in the database rather than rows a
     * migration writes, so a test database has only the ones a test made —
     * and the migration deliberately skips a requirement naming a skill that
     * is not there rather than failing. `SkillEffectTest` skips for the same
     * reason and in the same words.
     */
    public function test_it_requires_cyberdecks_at_three(): void
    {
        $required = Skill::query()->where('slug', 'cyberdecks')->value('id');

        if ($required === null) {
            $this->markTestSkipped('cyberdecks is not in this database');
        }

        $row = DB::table('skill_prerequisites')
            ->where('skill_id', $this->skill()->id)
            ->sole();

        $this->assertSame($required, $row->required_skill_id);
        $this->assertSame(3, $row->required_level);
    }

    /** One imprint per skill is what makes the injection gate mean anything. */
    public function test_its_book_is_on_the_shelf(): void
    {
        $book = Imprint::forSkill($this->skill());

        $this->assertNotNull($book);
        $this->assertTrue($book->on_market);
        $this->assertGreaterThan(0, $book->seed_quantity, 'and there is stock of it');
    }

    // ---- what it does ------------------------------------------------------

    /**
     * PERCENTAGES SUM AND APPLY ONCE, so three levels is +9% of base and not
     * 1.03 cubed. That is the resolver's rule and this is the proof it holds
     * for a skill's own per-level grants.
     */
    public function test_the_levels_sum_rather_than_compound(): void
    {
        $bare = $this->character->fresh()->stat('egress');

        $this->train(3);

        $trained = $this->character->fresh()->stat('egress');

        $this->assertSame(
            round($bare->base * 1.09, 1),
            round($trained->value, 1),
            'three grants of +3% of base',
        );
    }

    public function test_trained_out_it_is_fifteen_per_cent(): void
    {
        $bare = $this->character->fresh()->stat('egress')->base;

        $this->train(5);

        $this->assertSame(
            round($bare * 1.15, 1),
            round($this->character->fresh()->stat('egress')->value, 1),
        );
    }

    /** An injected but untrained skill grants nothing: level 0 is a real state. */
    public function test_knowing_it_untrained_grants_nothing(): void
    {
        $bare = $this->character->fresh()->stat('egress')->value;

        $this->train(0);

        $this->assertSame($bare, $this->character->fresh()->stat('egress')->value);
    }

    /**
     * AND IT MOVES THE CHANCE, which is the only reason any of it matters.
     * The simulator gains nothing: it asks for a stat and gets a bigger one.
     */
    public function test_it_makes_leaving_more_likely(): void
    {
        System::factory()->at(8, 8)->grade(5)->create(['address' => 'DEADBEEF']);

        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));
        $run = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;

        $simulator = Simulator::fromConfig(
            static fn (int $min, int $max): int => $max,
            app(Resolver::class),
            app(CheckRoller::class),
        );

        $before = $simulator->egressChance($this->character->fresh(), $run->fresh());

        $this->train(5);

        $after = $simulator->egressChance($this->character->fresh(), $run->fresh());

        $this->assertGreaterThan($before, $after);
    }

    /**
     * AND IT NAMES ITSELF ON THE CARD. The explanation reads the source off
     * the resolver's own breakdown, so the screen never learns what a skill
     * is — which is what makes the next modifier free.
     */
    public function test_the_working_names_the_skill(): void
    {
        System::factory()->at(8, 8)->grade(5)->create(['address' => 'DEADBEEF']);

        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));
        app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction);

        $this->train(4);

        $this->actingAs($this->character->fresh()->user)
            ->get(route('run.show'))
            ->assertOk()
            ->assertSee('Egress Protocols')
            ->assertSee('Evasion');
    }

    // ---- fixtures ----------------------------------------------------------

    private function skill(): Skill
    {
        return Skill::query()->where('slug', 'egressprotocols')->sole();
    }

    /** Know it, at this level. The imprint is the player's gate, not this test's. */
    private function train(int $level): void
    {
        CharacterSkill::query()->updateOrCreate(
            ['character_id' => $this->character->id, 'skill_id' => $this->skill()->id],
            ['attained_level' => $level, 'skill_points' => 0],
        );

        $this->character->refresh();
    }
}
