<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Flags;
use App\Checks\CheckRoller;
use App\City\CityClock;
use App\City\CityRefusal;
use App\City\EncounterRunner;
use App\City\Encounters;
use App\City\EncounterStart;
use App\City\Explorer;
use App\City\Traveller;
use App\City\TravelMode;
use App\Drugs\DrugLedger;
use App\Drugs\Family;
use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\CharacterEncounter;
use App\Models\CityActivity;
use App\Models\District;
use App\Models\Dose;
use App\Models\Drug;
use App\Models\Encounter;
use App\Models\EncounterChoice;
use App\Models\Item;
use App\Models\Location;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Narrative encounters, end to end. CITY.md, *Encounters*.
 *
 * Both dice are pinned: the spawn die (`Encounters`, integers) and the check die
 * (`CheckRoller`, a float in [0, 1)).
 */
final class EncounterTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        // The authored introduction arrives by migration; these tests count rows from nothing.
        Encounter::query()->delete();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3, 'creds' => 500],
        ));
        $this->character->forceFill(['district_id' => $this->district('dockside')->id])->save();
    }

    private function district(string $slug): District
    {
        return District::query()->where('slug', $slug)->firstOrFail();
    }

    /** Pin the spawn die. */
    private function spawnRolls(int $value): void
    {
        $this->app->bind(Encounters::class, fn ($app): Encounters => Encounters::fromConfig(
            $app->make(Resolver::class),
            static fn (int $min, int $max): int => min($max, max($min, $value)),
        ));
    }

    /** Pin the check die. */
    private function checkRolls(float $value): void
    {
        $this->app->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => $value));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array{body?: string, entry?: bool, ends?: bool, choices?: list<array<string, mixed>>}>  $nodes
     */
    private function encounter(string $slug, array $attributes, array $nodes): Encounter
    {
        $encounter = Encounter::create(array_merge([
            'slug' => $slug, 'title' => ucfirst($slug), 'starts_on' => 'travel', 'weight' => 100,
        ], $attributes));

        foreach ($nodes as $key => $spec) {
            $node = $encounter->nodes()->create([
                'node_key' => $key, 'body' => $spec['body'] ?? "Body of {$key}.",
                'entry' => $spec['entry'] ?? false, 'ends' => $spec['ends'] ?? false,
            ]);

            foreach ($spec['choices'] ?? [] as $position => $choice) {
                $outcomes = $choice['outcomes'] ?? [];
                unset($choice['outcomes']);
                $row = $node->choices()->create(array_merge(['position' => $position], $choice));

                foreach ($outcomes as $i => $outcome) {
                    $row->outcomes()->create(array_merge(['position' => $i, 'branch' => 'always'], $outcome));
                }
            }
        }

        return $encounter;
    }

    /** A one-choice encounter that ends on a second node. */
    private function simple(string $slug = 'alley', array $attributes = [], array $choice = []): Encounter
    {
        return $this->encounter($slug, $attributes, [
            'start' => ['entry' => true, 'choices' => [array_merge(['label' => 'Go on', 'next_key' => 'end'], $choice)]],
            'end' => ['ends' => true],
        ]);
    }

    private function runner(): EncounterRunner
    {
        return app(EncounterRunner::class);
    }

    private function open(Encounter $encounter): void
    {
        app(Encounters::class)->open($this->character, $encounter);
        $this->character->refresh();
    }

    private function choiceId(string $label): int
    {
        return (int) EncounterChoice::query()->where('label', $label)->value('id');
    }

    // ---- the draw ----------------------------------------------------------

    public function test_a_hyphenated_slug_still_makes_a_flag_a_flag_will_accept(): void
    {
        $flag = (new Encounter(['slug' => 'back-alley-deal']))->seenFlag();

        $this->assertSame('encounter_back_alley_deal', $flag);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,60}$/', $flag);
    }

    public function test_a_rare_encounter_is_rare_even_when_it_is_the_only_one(): void
    {
        $rare = $this->simple('rare', ['weight' => 2]);
        $this->spawnRolls(3);

        $this->assertNull(app(Encounters::class)->draw(collect([$rare])), 'weight 2 against a pool of 100 is a 2% pick');

        $this->spawnRolls(2);
        $this->assertTrue(app(Encounters::class)->draw(collect([$rare]))->is($rare));
    }

    public function test_the_draw_walks_the_weights(): void
    {
        $a = $this->simple('a', ['weight' => 30]);
        $b = $this->simple('b', ['weight' => 70]);

        $this->spawnRolls(30);
        $this->assertTrue(app(Encounters::class)->draw(collect([$a, $b]))->is($a));
        $this->spawnRolls(31);
        $this->assertTrue(app(Encounters::class)->draw(collect([$a, $b]))->is($b));
    }

    public function test_only_what_is_eligible_is_offered(): void
    {
        $this->simple('anywhere');
        $this->simple('uptown', ['district_id' => $this->district('downtown')->id]);
        $this->simple('flagged', ['required_flag' => 'knows_a_guy']);
        $this->simple('old', ['retired' => true]);
        $this->simple('explore_only', ['starts_on' => 'explore']);

        $slugs = fn () => app(Encounters::class)->eligible($this->character, EncounterStart::Travel, $this->character->district_id)->pluck('slug')->all();

        $this->assertSame(['anywhere'], $slugs());

        Flags::set($this->character, 'knows_a_guy');
        $this->assertSame(['anywhere', 'flagged'], $slugs());
    }

    public function test_a_once_encounter_sets_its_flag_and_is_not_offered_again(): void
    {
        $once = $this->simple('once_only', ['once' => true]);

        $this->open($once);
        $this->assertTrue(Flags::has($this->character, 'encounter_once_only'));
        $this->assertSame(
            [],
            app(Encounters::class)->eligible($this->character, EncounterStart::Travel, $this->character->district_id)->all(),
        );
    }

    public function test_an_encounter_with_no_entry_node_is_never_offered(): void
    {
        $this->encounter('headless', [], ['start' => ['choices' => []]]);

        $this->assertCount(0, app(Encounters::class)->eligible($this->character, EncounterStart::Travel, $this->character->district_id));
    }

    // ---- when one can happen ------------------------------------------------

    public function test_nothing_opens_for_somebody_in_cyberspace(): void
    {
        $this->simple();
        $this->spawnRolls(1);
        $this->character->forceFill(['grid_x' => 3, 'grid_y' => 3])->save();

        $this->assertNull(app(Encounters::class)->maybeOpen($this->character, EncounterStart::Travel));
        $this->assertNull($this->character->fresh()->current_encounter_id);
    }

    public function test_a_miss_of_the_spawn_roll_opens_nothing(): void
    {
        $this->simple();
        $this->spawnRolls(10_000);

        $this->assertNull(app(Encounters::class)->maybeOpen($this->character, EncounterStart::Travel));
    }

    public function test_the_chance_is_the_resolved_stat(): void
    {
        $this->assertEqualsWithDelta(0.05, app(Encounters::class)->chanceFor($this->character, EncounterStart::Travel), 1e-9);
        $this->assertEqualsWithDelta(0.08, app(Encounters::class)->chanceFor($this->character, EncounterStart::Explore), 1e-9);
    }

    // ---- answering one -------------------------------------------------------

    public function test_a_choice_moves_the_node_and_an_ending_closes_on_continue(): void
    {
        $this->open($this->simple());
        $this->assertNotNull($this->character->current_encounter_id);

        $step = $this->runner()->choose($this->character, $this->choiceId('Go on'));
        $this->assertFalse($step->closed);
        $this->assertSame('end', $this->character->fresh()->currentEncounter->node_key);

        $this->assertTrue($this->runner()->leave($this->character));
        $this->assertNull($this->character->fresh()->current_encounter_id);
        $this->assertDatabaseCount('character_encounters', 0);
    }

    public function test_a_choice_with_nowhere_to_go_closes_at_once(): void
    {
        $this->open($this->simple('shut', [], ['label' => 'Walk away', 'next_key' => null]));

        $step = $this->runner()->choose($this->character, $this->choiceId('Walk away'));

        $this->assertTrue($step->closed);
        $this->assertNull($this->character->fresh()->current_encounter_id);
    }

    public function test_a_choice_not_on_the_current_node_is_refused_so_a_double_submit_does_nothing(): void
    {
        $this->open($this->simple());
        $id = $this->choiceId('Go on');

        $this->runner()->choose($this->character, $id);

        $this->assertSame(CityRefusal::NotAChoice, $this->runner()->choose($this->character, $id));
        $this->assertSame(CityRefusal::NotAChoice, $this->runner()->choose($this->character, 999_999));
    }

    public function test_you_cannot_leave_from_a_node_that_does_not_end(): void
    {
        $this->open($this->simple());

        $this->assertSame(CityRefusal::NotAChoice, $this->runner()->leave($this->character));
    }

    public function test_a_flagged_choice_is_refused_without_its_flag(): void
    {
        $this->open($this->simple('gated', [], ['label' => 'Use the code', 'requires_flag' => 'has_code']));
        $id = $this->choiceId('Use the code');

        $this->assertSame(CityRefusal::NotAChoice, $this->runner()->choose($this->character, $id));

        Flags::set($this->character, 'has_code');
        $this->assertFalse($this->runner()->choose($this->character->fresh(), $id)->closed);
    }

    public function test_cost_is_charged_before_the_roll_and_an_empty_wallet_refuses(): void
    {
        $this->open($this->simple('toll', [], ['label' => 'Pay', 'cost' => 200]));

        $this->runner()->choose($this->character, $this->choiceId('Pay'));
        $this->assertSame(300, $this->character->fresh()->creds);
        $this->assertDatabaseHas('wallet_entries', ['character_id' => $this->character->id, 'reason' => 'encounter']);

        $broke = $this->simple('toll2', [], ['label' => 'Pay more', 'cost' => 9_999]);
        $this->character->forceFill(['current_encounter_id' => null])->save();
        $this->open($broke);

        $this->assertSame(CityRefusal::CannotPay, $this->runner()->choose($this->character, $this->choiceId('Pay more')));
        $this->assertSame(300, $this->character->fresh()->creds);
    }

    public function test_a_check_takes_its_branch_and_applies_only_that_branch_s_outcomes(): void
    {
        $build = fn (string $slug) => $this->encounter($slug, [], [
            'start' => ['entry' => true, 'choices' => [[
                'label' => "Try {$slug}", 'next_key' => 'lost', 'check_stat' => 'intellect', 'difficulty' => 20,
                'pass_key' => 'won', 'fail_key' => null,
                'outcomes' => [
                    ['branch' => 'pass', 'kind' => 'creds', 'amount' => 50],
                    ['branch' => 'fail', 'kind' => 'creds', 'amount' => -20],
                    ['branch' => 'always', 'kind' => 'flag', 'ref' => "tried_{$slug}"],
                ],
            ]]],
            'won' => ['ends' => true],
            'lost' => ['ends' => true],
        ]);

        // intellect 20 against difficulty 20 is a coin flip: a die of 0 passes, 0.99 fails.
        $this->checkRolls(0.0);
        $this->open($build('pass'));
        $this->runner()->choose($this->character, $this->choiceId('Try pass'));
        $this->assertSame(550, $this->character->fresh()->creds);
        $this->assertSame('won', $this->character->fresh()->currentEncounter->node_key);
        $this->assertTrue(Flags::has($this->character, 'tried_pass'));

        $this->runner()->leave($this->character);

        $this->checkRolls(0.99);
        $this->open($build('fail'));
        $this->runner()->choose($this->character->fresh(), $this->choiceId('Try fail'));
        $this->assertSame(530, $this->character->fresh()->creds);
        $this->assertSame('lost', $this->character->fresh()->currentEncounter->node_key, 'fail_key is null, so next_key');
    }

    public function test_the_button_s_chance_is_the_rolls_chance(): void
    {
        $this->open($this->simple('rolled', [], ['label' => 'Gamble', 'check_stat' => 'intellect', 'difficulty' => 20]));
        $choice = EncounterChoice::query()->where('label', 'Gamble')->firstOrFail();

        $this->assertEqualsWithDelta(0.5, $this->runner()->chanceOf($this->character, $choice), 1e-9);
    }

    public function test_an_unknown_stat_is_a_value_of_nothing_rather_than_a_crash(): void
    {
        $this->open($this->simple('typo', [], ['label' => 'Oops', 'check_stat' => 'no_such_stat', 'difficulty' => 10]));
        $choice = EncounterChoice::query()->where('label', 'Oops')->firstOrFail();

        $this->assertLessThan(0.5, $this->runner()->chanceOf($this->character, $choice));
    }

    public function test_outcomes_go_through_their_writers(): void
    {
        $item = Item::factory()->physical()->create(['name' => 'Lucky Charm']);

        $this->open($this->simple('haul', [], [
            'label' => 'Search', 'next_key' => null,
            'outcomes' => [
                ['kind' => 'item', 'amount' => 2, 'item_id' => $item->id],
                ['kind' => 'heat', 'amount' => 3],
                ['kind' => 'creds', 'amount' => 25],
            ],
        ]));

        $step = $this->runner()->choose($this->character, $this->choiceId('Search'));
        $character = $this->character->fresh();

        $this->assertTrue($step->closed);
        $this->assertSame(525, $character->creds);
        $this->assertEqualsWithDelta(3.0, (float) $character->heat, 1e-9);
        $this->assertSame(2, (int) $character->inventory()->where('item_id', $item->id)->sum('quantity'));
        $this->assertStringContainsString('Lucky Charm', implode(' ', $step->lines));
    }

    public function test_an_outcome_whose_item_has_since_been_deleted_goes_with_it(): void
    {
        $item = Item::factory()->physical()->create();
        $this->open($this->simple('gone', [], [
            'label' => 'Search', 'next_key' => null,
            'outcomes' => [['kind' => 'item', 'amount' => 1, 'item_id' => $item->id]],
        ]));
        $item->delete();

        $this->assertSame(0, DB::table('encounter_outcomes')->count(), 'the FK cascaded the outcome away');
        $this->assertTrue($this->runner()->choose($this->character, $this->choiceId('Search'))->closed);
    }

    private function drug(Family $family = Family::Stimulant, ?int $lethalAt = null): Item
    {
        $item = Item::factory()->create(['container' => 'physical', 'name' => 'Blue Pill', 'modifiers' => [
            ['op' => 'add', 'amount' => 5, 'target' => ['id' => 'focus']],
        ]]);
        Drug::query()->create(['item_id' => $item->id, 'family' => $family, 'duration_minutes' => 60, 'addictive' => 4.0, 'tolerance_gain' => 5.0, 'lethal_at' => $lethalAt]);

        return $item->fresh();
    }

    public function test_a_dose_outcome_takes_the_drug_through_the_one_door_and_leaves_no_copy_behind(): void
    {
        $drug = $this->drug();
        $this->open($this->simple('pill', [], [
            'label' => 'Take it', 'next_key' => null,
            'outcomes' => [['kind' => 'dose', 'item_id' => $drug->id]],
        ]));

        $step = $this->runner()->choose($this->character, $this->choiceId('Take it'));

        $this->assertTrue($step->closed);
        $this->assertStringContainsString('You take the Blue Pill.', implode(' ', $step->lines));
        $this->assertSame(1, Dose::query()->where('character_id', $this->character->id)->inEffect()->count());
        $this->assertSame(0, (int) $this->character->inventory()->where('item_id', $drug->id)->sum('quantity'), 'the copy was granted and spent');
        $this->assertGreaterThan(0, (float) DB::table('character_drug_use')->where('character_id', $this->character->id)->value('addiction'), 'the books moved: it is a real dose');
    }

    public function test_a_dose_does_not_spend_a_copy_the_player_already_owned(): void
    {
        $drug = $this->drug();
        app(ItemGranter::class)::grant($this->character, $drug, 3);
        $this->open($this->simple('pill', [], [
            'label' => 'Take it', 'next_key' => null,
            'outcomes' => [['kind' => 'dose', 'item_id' => $drug->id]],
        ]));

        $this->runner()->choose($this->character, $this->choiceId('Take it'));

        $this->assertSame(3, (int) $this->character->inventory()->where('item_id', $drug->id)->sum('quantity'));
    }

    public function test_tolerance_applies_to_an_encounter_dose_as_to_any_other(): void
    {
        $drug = $this->drug();
        // Somebody who has already built up a tolerance: 30 is past the first band (25), worth 10% off.
        (new DrugLedger)->for($this->character, Family::Stimulant)->forceFill(['tolerance' => 30.0])->save();

        $this->open($this->simple('pill', [], [
            'label' => 'Take it', 'next_key' => null,
            'outcomes' => [['kind' => 'dose', 'item_id' => $drug->id]],
        ]));
        $this->runner()->choose($this->character, $this->choiceId('Take it'));

        $this->assertEqualsWithDelta(0.9, (float) Dose::query()->where('character_id', $this->character->id)->value('potency'), 1e-9);
    }

    public function test_too_many_overdoses_and_the_floor_is_asked(): void
    {
        // A sedative family, a lethal threshold of 1: any excess stops the heart.
        $drug = $this->drug(Family::Sedative, lethalAt: 1);
        $limit = (int) (new DrugLedger)->capacityFor($this->character, Family::Sedative);

        $line = '';
        for ($i = 0; $i <= $limit; $i++) {
            $this->character->forceFill(['current_encounter_id' => null])->save();
            CharacterEncounter::query()->delete();
            $this->open($this->simple("dose_{$i}", [], [
                'label' => "Dose {$i}", 'next_key' => null,
                'outcomes' => [['kind' => 'dose', 'item_id' => $drug->id]],
            ]));
            $line = implode(' ', $this->runner()->choose($this->character->fresh(), $this->choiceId("Dose {$i}"))->lines);
        }

        $this->assertStringContainsString('It is too much for you.', $line);
        $this->assertNotNull($this->character->fresh()->waiting_until, 'the heart stopped: the same floor every bad ending uses');
    }

    // ---- what a screen does on arrival ----------------------------------------

    /** @param  list<array<string, mixed>>  $outcomes */
    private function withScreenOutcomes(string $slug, array $outcomes, string $on = 'end'): Encounter
    {
        $encounter = $this->simple($slug);
        $node = $encounter->nodes()->where('node_key', $on)->firstOrFail();

        foreach ($outcomes as $i => $outcome) {
            $node->outcomes()->create(array_merge(['position' => $i, 'branch' => 'always'], $outcome));
        }

        return $encounter;
    }

    public function test_a_screen_s_outcomes_happen_when_it_is_reached_and_are_told_with_the_choice(): void
    {
        $this->open($this->withScreenOutcomes('ambush', [
            ['kind' => 'creds', 'amount' => -40],
            ['kind' => 'flag', 'ref' => 'was_jumped'],
        ]));

        $this->assertSame(500, $this->character->fresh()->creds, 'nothing yet: the ending has not been reached');

        $step = $this->runner()->choose($this->character, $this->choiceId('Go on'));

        $this->assertFalse($step->closed, 'it is an ending, so it waits for Continue');
        $this->assertSame(460, $this->character->fresh()->creds);
        $this->assertTrue(Flags::has($this->character, 'was_jumped'));
        $this->assertStringContainsString('You lose 40 ¤.', implode(' ', $step->lines));
        $this->assertSame(460, $this->character->fresh()->creds);

        // Continuing applies nothing a second time.
        $this->runner()->leave($this->character);
        $this->assertSame(460, $this->character->fresh()->creds);
    }

    public function test_the_opening_screen_may_do_something_on_arrival_and_says_so_in_the_log(): void
    {
        $this->open($this->withScreenOutcomes('ambush', [['kind' => 'creds', 'amount' => 25]], on: 'start'));

        $this->assertSame(525, $this->character->fresh()->creds);
        $this->assertSame([['label' => '', 'lines' => ['You gain 25 ¤.']]], $this->character->fresh()->currentEncounter->log);

        $this->actingAs($this->character->user)->get(route('encounter.show'))->assertOk()->assertSeeText('You gain 25 ¤.');
    }

    public function test_a_screen_in_a_loop_does_it_every_time_it_is_reached(): void
    {
        $encounter = $this->encounter('loop', [], [
            'start' => ['entry' => true, 'choices' => [
                ['label' => 'Again', 'next_key' => 'start'],
                ['label' => 'Leave', 'next_key' => null],
            ]],
        ]);
        $encounter->nodes()->firstOrFail()->outcomes()->create(['position' => 0, 'branch' => 'always', 'kind' => 'creds', 'amount' => 10]);

        $this->open($encounter);                                        // arrival 1
        $this->runner()->choose($this->character, $this->choiceId('Again'));   // arrival 2
        $this->runner()->choose($this->character->fresh(), $this->choiceId('Again'));   // arrival 3

        $this->assertSame(530, $this->character->fresh()->creds);
    }

    public function test_a_choice_that_closes_does_not_arrive_anywhere(): void
    {
        $this->open($this->withScreenOutcomes('never', [['kind' => 'creds', 'amount' => 99]]));
        $this->character->refresh();

        $this->runner()->choose($this->character, $this->choiceId('Go on'));
        $this->runner()->leave($this->character->fresh());

        $this->assertSame(599, $this->character->fresh()->creds, 'once, on reaching the ending, and not again on leaving');
    }

    public function test_hit_point_damage_from_an_ending_screen_hurts_and_a_lethal_one_puts_them_on_the_floor(): void
    {
        $this->open($this->withScreenOutcomes('fist', [['kind' => 'damage', 'ref' => 'hitpoints', 'amount' => 3]]));
        $before = $this->character->fresh()->current_hitpoints;

        $this->runner()->choose($this->character, $this->choiceId('Go on'));

        $after = $this->character->fresh()->current_hitpoints;
        $this->assertLessThan($before, $after);
        $this->assertNull($this->character->fresh()->waiting_until, 'a scratch is not the floor');

        $this->runner()->leave($this->character->fresh());
        $this->open($this->withScreenOutcomes('killer', [['kind' => 'damage', 'ref' => 'hitpoints', 'amount' => 9_999]]));
        // Both encounters have a "Go on"; the newest is this one's.
        $this->runner()->choose($this->character->fresh(), (int) EncounterChoice::query()->latest('id')->value('id'));

        $this->assertNotNull($this->character->fresh()->waiting_until);
    }

    public function test_damage_that_empties_a_resource_puts_the_character_on_the_floor(): void
    {
        $this->open($this->simple('ouch', [], [
            'label' => 'Take the hit', 'next_key' => null,
            'outcomes' => [['kind' => 'damage', 'ref' => 'hitpoints', 'amount' => 9_999]],
        ]));

        $this->runner()->choose($this->character, $this->choiceId('Take the hit'));

        $this->assertNotNull($this->character->fresh()->waiting_until, 'the floor is asked after every damaging outcome');
    }

    public function test_a_node_removed_under_somebody_closes_the_encounter(): void
    {
        $encounter = $this->simple();
        $this->open($encounter);
        $encounter->nodes()->delete();

        $this->assertTrue($this->runner()->leave($this->character));
        $this->assertNull($this->character->fresh()->current_encounter_id);
    }

    public function test_deleting_the_definition_frees_the_character(): void
    {
        $encounter = $this->simple();
        $this->open($encounter);

        $encounter->delete();

        $this->assertNull($this->character->fresh()->current_encounter_id);
    }

    // ---- the doors ------------------------------------------------------------

    private function trip(TravelMode $mode = TravelMode::Pod): void
    {
        $this->character->forceFill(['creds' => 200])->save();
        app(Traveller::class)->begin($this->character, $this->district('downtown'), $mode);
    }

    public function test_a_travel_encounter_stops_the_trip_where_it_opened_and_refunds_the_rest(): void
    {
        $this->simple();
        $this->spawnRolls(1);
        $this->trip();
        $paid = (int) CityActivity::query()->find($this->character->id)->paid;
        $this->assertSame(75, $paid);

        $this->travel(5)->minutes();
        $messages = app(CityClock::class)->settle($this->character->fresh());

        $character = $this->character->fresh();
        $this->assertSame('old-town', District::query()->find($character->district_id)->slug, 'it opened on arriving in Old Town');
        $this->assertNotNull($character->current_encounter_id);
        $this->assertNull(CityActivity::query()->find($character->id), 'the trip is over');
        $this->assertSame(200 - 75 + 25, $character->creds, 'one district of three was not reached');
        $this->assertContains('encounter_waiting', array_column($messages, 0));
    }

    public function test_an_encounter_on_arrival_at_the_destination_arrives_first(): void
    {
        $this->simple();
        // Misses on the first arrival (Old Town), hits on the second (Downtown).
        $calls = 0;
        $this->app->bind(Encounters::class, fn ($app): Encounters => Encounters::fromConfig(
            $app->make(Resolver::class),
            static function (int $min, int $max) use (&$calls): int {
                return ++$calls === 1 ? 10_000 : 1;
            },
        ));
        $this->trip();

        $this->travel(5)->minutes();
        $messages = app(CityClock::class)->settle($this->character->fresh());

        $character = $this->character->fresh();
        $this->assertSame('downtown', District::query()->find($character->district_id)->slug);
        $this->assertNotNull($character->current_encounter_id);
        $this->assertSame(200 - 75, $character->creds, 'nothing to refund: they arrived');
        $this->assertSame(['travel_arrived', 'encounter_waiting'], array_column($messages, 0));
    }

    public function test_an_explore_pass_finds_first_and_then_an_encounter_may_follow(): void
    {
        $this->simple('wander', ['starts_on' => 'explore']);
        $this->spawnRolls(1);
        $this->checkRolls(0.99);
        app(Explorer::class)->begin($this->character);

        $this->travel(10)->minutes();
        $messages = app(CityClock::class)->settle($this->character->fresh());

        $this->assertSame(['encounter_waiting'], array_column($messages, 0), 'nothing found is silent; the encounter is not');
        $this->assertNotNull($this->character->fresh()->current_encounter_id);
    }

    public function test_a_place_offers_its_encounters_as_a_button_only_to_somebody_there(): void
    {
        $place = Location::create([
            'district_id' => $this->district('dockside')->id, 'slug' => 'back_room', 'name' => 'Back Room',
            'map_x' => 50, 'map_y' => 50, 'discovery' => 'open',
        ]);
        $e = $this->simple('whisper', ['starts_on' => 'place', 'location_id' => $place->id, 'title' => 'A whisper']);

        $this->actingAs($this->character->user)->get(route('city.place', 'back_room'))
            ->assertOk()->assertSeeText('A whisper');

        $this->actingAs($this->character->user)->post(route('city.place.encounter', ['back_room', $e->id]))
            ->assertRedirect(route('encounter.show'));
        $this->assertNotNull($this->character->fresh()->current_encounter_id);

        // Away from the district it is refused.
        $this->character->forceFill(['current_encounter_id' => null, 'district_id' => $this->district('downtown')->id])->save();
        DB::table('character_encounters')->delete();
        $this->assertSame(CityRefusal::NotHere, app(Encounters::class)->openAtPlace($this->character->fresh(), $place, $e->id));
    }

    // ---- the lock and the screen ------------------------------------------------

    public function test_an_open_encounter_owns_the_screen(): void
    {
        $this->open($this->simple());

        $this->actingAs($this->character->user)->get(route('city.index'))->assertRedirect(route('encounter.show'));
        $this->actingAs($this->character->user)->getJson(route('city.index'))
            ->assertStatus(409)->assertJsonPath('redirect', route('encounter.show'));
        $this->actingAs($this->character->user)->get(route('encounter.show'))->assertOk();
    }

    public function test_the_screen_shows_the_body_the_cost_and_a_band_word_with_the_figure_in_the_title(): void
    {
        $this->open($this->encounter('market', ['title' => 'A dodgy deal'], [
            'start' => ['body' => 'A man offers you a coat.', 'entry' => true, 'choices' => [
                ['label' => 'Haggle', 'cost' => 40, 'check_stat' => 'intellect', 'difficulty' => 20, 'next_key' => 'end'],
            ]],
            'end' => ['ends' => true],
        ]));

        $this->actingAs($this->character->user)->get(route('encounter.show'))
            ->assertOk()
            ->assertSeeText('A dodgy deal')
            ->assertSeeText('A man offers you a coat.')
            ->assertSeeText('Haggle')
            ->assertSeeText('costs 40 ¤')
            ->assertSeeText('Even odds')
            ->assertSee('title="50%"', false);
    }

    public function test_the_screens_answer_a_choice_and_continue_back_to_the_city(): void
    {
        $this->open($this->simple());

        $this->actingAs($this->character->user)
            ->post(route('encounter.choose'), ['choice' => $this->choiceId('Go on')])
            ->assertRedirect(route('encounter.show'));
        $this->actingAs($this->character->user)->post(route('encounter.leave'))->assertRedirect(route('city.index'));
        $this->assertNull($this->character->fresh()->current_encounter_id);
    }

    public function test_a_player_not_in_one_is_sent_back_to_the_city(): void
    {
        $this->actingAs($this->character->user)->get(route('encounter.show'))->assertRedirect(route('city.index'));
    }
}
