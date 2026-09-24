<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Defences;
use App\Cyberspace\ExchangePlacer;
use App\Cyberspace\Grid;
use App\Cyberspace\Probe;
use App\Cyberspace\ProbeRefusal;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\Entity;
use App\Models\Exchange;
use App\Models\System;
use App\Models\SystemContact;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * The one kind of system nothing can be done TO.
 *
 * `SystemType::isInteractable()` used to be one question — scanned, intruded,
 * burned, owned — and said in its own docblock that it would become four the
 * day something answered no to only some of them. An Exchange is that day: no
 * to a run, a probe and a burn, and YES to being owned, because whose desk it
 * is is the whole reason to stand on one.
 */
final class ExchangeTest extends TestCase
{
    use CarriesAWorkingDeck, RefreshDatabase;

    private Character $character;

    private Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));

        $this->entity = Entity::query()->interactable()->firstOrFail();
    }

    private function desk(int $grade = 1): System
    {
        $system = System::factory()->at(8, 8)->ownedBy($this->entity)->create([
            'type' => SystemType::Exchange,
        ]);

        Exchange::query()->create([
            'system_id' => $system->id,
            'grade' => $grade,
            'agent' => 'Wren Achebe',
            'min_standing' => (float) Config::get("cyberspace.exchanges.gate.{$grade}", 0),
        ]);

        return $system->fresh();
    }

    // ---- the four questions ------------------------------------------------

    public function test_a_desk_is_owned_but_not_run_against_scanned_or_defended(): void
    {
        $type = SystemType::Exchange;

        $this->assertTrue($type->isOwned(), 'somebody answers for a desk');
        $this->assertTrue($type->isExchange());
        $this->assertFalse($type->isInteractable(), 'you do not break into a shopfront');
        $this->assertFalse($type->isScannable());
        $this->assertFalse($type->isDefended());

        // And the portal still answers no to all four, which is what the old
        // single question was for.
        $this->assertFalse(SystemType::Portal->isOwned());
        $this->assertFalse(SystemType::Portal->isInteractable());
    }

    public function test_a_run_against_a_desk_is_refused(): void
    {
        $this->desk();
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();

        $result = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction);

        $this->assertNull($result->run);
    }

    public function test_a_desk_cannot_be_scanned(): void
    {
        $system = $this->desk();
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();

        $result = app(Probe::class)->scan($this->character->fresh());

        $this->assertSame(ProbeRefusal::NotScannable, $result->refusal);
        $this->assertNotNull($system->fresh());
    }

    public function test_a_desk_is_given_no_defences(): void
    {
        $defences = Defences::roll(SystemType::Exchange, 1, static fn (int $min, int $max): int => $max);

        // Every column the roll would have filled comes back empty, the same
        // answer the portal gets and for the same reason.
        $this->assertSame(Defences::none()->columns(), $defences->columns());
    }

    // ---- what a desk knows about itself ------------------------------------

    public function test_the_grade_decides_the_work_and_the_gate(): void
    {
        $desk = $this->desk(4)->exchange;

        $this->assertSame([6, 8], $desk->targetGrades());
        $this->assertSame(40.0, $desk->min_standing);
        $this->assertFalse($desk->willDealWith(39.0));
        $this->assertTrue($desk->willDealWith(40.0));
    }

    /** Its own grade means difficulty, and nothing may be done to a desk. */
    public function test_a_desks_system_grade_stays_at_the_floor(): void
    {
        $placer = ExchangePlacer::fromConfig(fn (int $min, int $max): int => $min);
        $placer->run();

        $system = System::query()->where('type', SystemType::Exchange)->firstOrFail();

        $this->assertSame(1, $system->grade);
        $this->assertNotNull($system->exchange);
    }

    // ---- placing them ------------------------------------------------------

    public function test_every_interactable_entity_gets_the_desks_config_gives_it(): void
    {
        ExchangePlacer::fromConfig()->run();

        $wanted = (array) Config::get('cyberspace.exchanges.grades', []);

        foreach (Entity::query()->interactable()->get() as $entity) {
            $expected = (int) ($wanted[$entity->name] ?? Config::get('cyberspace.exchanges.default_grades', 3));

            $desks = Exchange::query()
                ->whereHas('system', fn ($query) => $query->where('owner_id', $entity->id))
                ->count();

            $this->assertSame($expected, $desks, "{$entity->name} keeps the wrong number of desks");
        }
    }

    /**
     * A TOP-UP AND NEVER A RE-ROLL. An Exchange is the one system somebody is
     * promised will be where they left it, so a second pass places nothing.
     */
    public function test_running_it_twice_places_nothing_the_second_time(): void
    {
        $first = ExchangePlacer::fromConfig()->run();
        $before = System::query()->where('type', SystemType::Exchange)->pluck('address')->sort()->values();

        $second = ExchangePlacer::fromConfig()->run();

        $this->assertNotSame([], $first);
        $this->assertSame([], $second);
        $this->assertEquals(
            $before,
            System::query()->where('type', SystemType::Exchange)->pluck('address')->sort()->values(),
        );
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $would = ExchangePlacer::fromConfig()->run(dryRun: true);

        $this->assertNotSame([], $would);
        $this->assertSame(0, Exchange::query()->count());
    }

    /**
     * A SIGHTING MAY BE OF A DESK, and the first one placed near the portal
     * proved it the hard way: `chk_systems_type` was widened for the new type
     * and `chk_contacts_type` was not, so the sweep that runs on every arrival
     * turned JACKING IN into a 500 — every player's first act, broken by a
     * constraint two tables from the change that caused it.
     *
     * The vocabulary is written down in SQL three times: `systems`,
     * `system_contacts` and `loot_tables`. The third is deliberately narrow —
     * a desk holds nothing and is never raided.
     */
    public function test_an_exchange_can_be_seen_and_remembered(): void
    {
        $system = $this->desk();

        SystemContact::query()->create([
            'character_id' => $this->character->id,
            'x' => 8,
            'y' => 8,
            'address' => $system->address,
            'name' => $system->displayName(),
            'type' => $system->type,
            'owner' => $this->entity->name,
            'seen_at' => now(),
            'first_seen_at' => now(),
        ]);

        $contact = SystemContact::query()->where('character_id', $this->character->id)->sole();

        $this->assertSame(SystemType::Exchange, $contact->type);
    }

    // ---- the screen --------------------------------------------------------

    /**
     * MISSIONS & LOYALTY, reached by standing on the desk. There is no id in
     * the route: being there is the whole of the permission, which is what
     * makes the walk to a high-grade agent worth making.
     */
    public function test_the_desk_introduces_itself(): void
    {
        $system = $this->desk(1);
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();

        $this->actingAs($this->character->user)
            ->get(route('exchange.show'))
            ->assertOk()
            ->assertSeeText($this->entity->name)
            ->assertSeeText($this->entity->category->label())
            ->assertSeeText('Wren Achebe')
            // The work is real now and the board is rolled on the way past.
            ->assertSeeText(Text::get('missions.board'))
            ->assertSeeText(Text::get('missions.active'))
            // The store is still designed and not built, so it says so — and
            // it is the only half that does.
            ->assertSeeText(Text::get('exchange.store'))
            ->assertSeeText(Text::get('exchange.not_built'));

        $this->assertNotNull($system->exchange);
    }

    /** ONE GATE: an agent who will not talk to you will not sell to you. */
    public function test_a_desk_that_wants_standing_says_so(): void
    {
        $this->desk(4);
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();

        $this->actingAs($this->character->user)
            ->get(route('exchange.show'))
            ->assertOk()
            ->assertSeeText(Text::get('exchange.unwelcome'));
    }

    public function test_standing_anywhere_else_is_sent_back_to_the_map(): void
    {
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 4, 'grid_y' => 4])->save();

        $this->actingAs($this->character->user)
            ->get(route('exchange.show'))
            ->assertRedirect(route('cyberspace.index'));
    }

    /** Higher grades sit deeper, the way system grade already does. */
    public function test_the_higher_the_grade_the_further_from_the_portal(): void
    {
        Config::set('cyberspace.size', 64);

        ExchangePlacer::fromConfig()->run();

        $portal = Grid::fromConfig()->portal();

        $near = Exchange::query()->where('grade', 1)->with('system')->get()
            ->map(fn (Exchange $desk): int => $desk->system->position()->distanceTo($portal))
            ->avg();

        $far = Exchange::query()->where('grade', '>=', 4)->with('system')->get()
            ->map(fn (Exchange $desk): int => $desk->system->position()->distanceTo($portal))
            ->avg();

        $this->assertNotNull($far, 'somebody runs a grade 4 desk');
        $this->assertGreaterThan($near, $far);
    }
}
