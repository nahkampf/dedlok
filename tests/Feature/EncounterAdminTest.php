<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\City\EncounterGraph;
use App\City\Encounters;
use App\Drugs\Family;
use App\Models\Character;
use App\Models\Drug;
use App\Models\Encounter;
use App\Models\EncounterOutcome;
use App\Models\Item;
use App\Models\Npc;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The encounter editor: what it saves, and everything it refuses that SQL cannot.
 */
final class EncounterAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // The authored introduction arrives by migration; these tests count encounters from nothing.
        Encounter::query()->delete();

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    /** A valid payload: an entry node with a rolled choice, and two endings. */
    private function payload(array $override = []): array
    {
        return array_replace_recursive([
            'slug' => 'back_alley',
            'title' => 'A back alley',
            'intro' => 'It is dark.',
            'starts_on' => 'travel',
            'weight' => 5,
            'entry' => 0,
            'nodes' => [
                0 => [
                    'key' => 'start', 'body' => 'Somebody steps out.',
                    'choices' => [
                        0 => [
                            'label' => 'Talk', 'cost' => 10, 'next_key' => 'fled',
                            'check_stat' => 'intellect', 'difficulty' => 20, 'pass_key' => 'won',
                            'outcomes' => [
                                0 => ['branch' => 'pass', 'kind' => 'creds', 'amount' => 50],
                                1 => ['branch' => 'always', 'kind' => 'flag', 'ref' => 'met_somebody'],
                                2 => ['branch' => 'always', 'kind' => ''],
                            ],
                        ],
                        1 => ['label' => ''],
                    ],
                ],
                1 => ['key' => 'won', 'body' => 'You win.', 'ends' => 1],
                2 => ['key' => 'fled', 'body' => 'You flee.', 'ends' => 1],
                3 => ['key' => '', 'body' => ''],
            ],
        ], $override);
    }

    private function save(array $payload): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.encounters.store'), $payload);
    }

    public function test_the_screens_render(): void
    {
        $this->save($this->payload())->assertRedirect();
        $encounter = Encounter::query()->firstOrFail();

        foreach ([route('admin.encounters.index'), route('admin.encounters.create'), route('admin.encounters.edit', $encounter)] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }

        $this->actingAs($this->admin)->get(route('admin.encounters.edit', $encounter))
            ->assertSee('Somebody steps out.')->assertSee('met_somebody', false)
            ->assertSeeText('Help')->assertSeeText('Equal stat and difficulty is a coin flip');
    }

    public function test_roll_a_stat_is_a_dropdown_of_the_registry_with_the_saved_one_selected(): void
    {
        $this->save($this->payload())->assertRedirect();
        $encounter = Encounter::query()->firstOrFail();

        $html = $this->actingAs($this->admin)->get(route('admin.encounters.edit', $encounter))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<select[^>]+name="nodes\[0\]\[choices\]\[0\]\[check_stat\]"/', $html);
        $this->assertStringContainsString('<option value="intellect" selected>', $html);
        $this->assertStringContainsString('<option value="exploration"', $html);
        $this->assertStringContainsString('value="check.', $html, 'skills\' checks are listed');
    }

    public function test_weight_is_a_slider_with_a_word_for_how_rare_it_is(): void
    {
        $this->save($this->payload(['weight' => 5]))->assertRedirect();
        $encounter = Encounter::query()->firstOrFail();

        $html = $this->actingAs($this->admin)->get(route('admin.encounters.edit', $encounter))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input class="range" type="range" name="weight" min="1" max="100"[^>]*value="5"/', $html);
        $this->assertStringContainsString('Rare · 5 in 100', $html, 'the label is true before any script runs');
        $this->assertStringContainsString('data-format="{band} · {value} in 100"', $html);
        $this->assertStringContainsString('Ultra rare', $html, 'the bands the script reads');

        $this->save($this->payload(['slug' => 'too_heavy', 'weight' => 101]))->assertSessionHasErrors('weight');
    }

    public function test_the_form_offers_a_plus_for_a_screen_a_choice_and_an_outcome(): void
    {
        $this->save($this->payload())->assertRedirect();
        $html = $this->actingAs($this->admin)->get(route('admin.encounters.edit', Encounter::query()->firstOrFail()))->getContent();

        foreach (['__I__', '__J__', '__K__'] as $token) {
            $this->assertStringContainsString('data-add-row data-token="'.$token.'"', $html);
            $this->assertStringContainsString('['.$token.']', $html, 'and the template that button clones');
        }
    }

    public function test_every_screen_choice_and_outcome_has_a_remove_button(): void
    {
        $this->save($this->payload())->assertRedirect();
        $html = $this->actingAs($this->admin)->get(route('admin.encounters.edit', Encounter::query()->firstOrFail()))->getContent();

        foreach (['Remove this screen', 'Remove this choice', 'Remove this outcome'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        // Rows that exist and the templates that new ones are cloned from both carry one.
        $this->assertGreaterThanOrEqual(6, substr_count($html, 'data-remove-row'));
    }

    public function test_a_screen_taken_out_of_the_middle_leaves_a_gap_in_the_indexes_and_still_saves(): void
    {
        // What the form posts after the second screen's ✕: indexes 0 and 3, with nothing at 1 or 2.
        $this->save([
            'slug' => 'gappy', 'title' => 'Gappy', 'intro' => '', 'starts_on' => 'travel', 'weight' => 5, 'entry' => 0,
            'nodes' => [
                0 => ['key' => 'start', 'body' => 'Go.', 'choices' => [0 => ['label' => 'Leave', 'next_key' => 'end', 'cost' => 0]]],
                3 => ['key' => 'end', 'body' => 'Done.', 'ends' => 1],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $encounter = Encounter::query()->where('slug', 'gappy')->firstOrFail();
        $this->assertSame(['end', 'start'], $encounter->nodes()->orderBy('node_key')->pluck('node_key')->all());

        // And the screen taken out on a later save is gone.
        $this->actingAs($this->admin)->put(route('admin.encounters.update', $encounter), [
            'title' => 'Gappy', 'intro' => '', 'starts_on' => 'travel', 'weight' => 5, 'entry' => 0,
            'nodes' => [0 => ['key' => 'start', 'body' => 'Go.', 'ends' => 1]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['start'], $encounter->nodes()->pluck('node_key')->all());
    }

    public function test_the_editor_has_a_graph_accordion_with_its_script_and_key(): void
    {
        foreach ([route('admin.encounters.create'), null] as $url) {
            if ($url === null) {
                $this->save($this->payload())->assertRedirect();
                $url = route('admin.encounters.edit', Encounter::query()->firstOrFail());
            }

            $this->actingAs($this->admin)->get($url)->assertOk()
                ->assertSee('id="encounter-graph"', false)
                ->assertSee('assets/js/encounter-graph.js?v=', false)
                ->assertSeeText('Not connected yet')
                ->assertSeeText('Opens here')
                ->assertSeeText('On fail');
        }
    }

    public function test_only_an_admin_gets_in(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.encounters.index'))->assertForbidden();
    }

    public function test_it_saves_the_whole_graph_and_skips_blank_rows(): void
    {
        $this->save($this->payload())->assertRedirect()->assertSessionHasNoErrors();

        $encounter = Encounter::query()->where('slug', 'back_alley')->firstOrFail();
        $this->assertSame(3, $encounter->nodes()->count(), 'the blank node was skipped');
        $this->assertSame('start', $encounter->nodes()->where('entry', true)->value('node_key'));

        $choice = $encounter->nodes()->where('node_key', 'start')->firstOrFail()->choices()->firstOrFail();
        $this->assertSame(10, $choice->cost);
        $this->assertSame('won', $choice->pass_key);
        $this->assertSame(2, $choice->outcomes()->count(), 'the blank outcome was skipped');
        $this->assertSame(1, $encounter->nodes()->where('node_key', 'start')->firstOrFail()->choices()->count(), 'the blank choice was skipped');
    }

    public function test_missing_screens_come_back_as_one_message_with_the_form_as_typed(): void
    {
        // Three ways to point at a screen that is not there: next, pass and fail — and one of them twice.
        $payload = $this->payload();
        $payload['nodes'][0]['choices'][0]['next_key'] = 'gone';
        $payload['nodes'][0]['choices'][0]['pass_key'] = 'away';
        $payload['nodes'][0]['choices'][0]['fail_key'] = 'gone';
        $payload['title'] = 'Kept as typed';

        $this->actingAs($this->admin)->from(route('admin.encounters.create'))
            ->post(route('admin.encounters.store'), $payload)
            ->assertRedirect(route('admin.encounters.create'))
            ->assertSessionHasErrors(['nodes' => 'Missing screens: gone, away'])
            ->assertSessionHasInput('title', 'Kept as typed');

        $this->assertSame(0, Encounter::query()->count());

        // And the page it lands on shows the message and every field as it was typed.
        $this->actingAs($this->admin)->followingRedirects()->from(route('admin.encounters.create'))
            ->post(route('admin.encounters.store'), $payload)
            ->assertOk()
            ->assertSeeText('Missing screens: gone, away')
            ->assertSee('Kept as typed')
            ->assertSee('Somebody steps out.');
    }

    public function test_pointing_at_a_screen_that_exists_by_the_end_is_not_missing(): void
    {
        // An ending's choices are ignored, so a bad key hiding there is not reported.
        $payload = $this->payload();
        $payload['nodes'][1]['choices'] = [0 => ['label' => 'ghost', 'next_key' => 'nowhere']];

        $this->save($payload)->assertSessionHasNoErrors();
    }

    public function test_an_injury_or_damage_picked_from_its_group_supplies_the_target(): void
    {
        $outcomes = [
            2 => ['branch' => 'always', 'kind' => 'injury:neural_damage', 'amount' => 3, 'ref' => ''],
            3 => ['branch' => 'always', 'kind' => 'damage:hitpoints', 'amount' => 4, 'ref' => ''],
            // The text field, edited after picking, wins over what the pick said.
            4 => ['branch' => 'always', 'kind' => 'damage:hitpoints', 'amount' => 5, 'ref' => 'synaptic_integrity'],
        ];

        $this->save($this->payload(['nodes' => [0 => ['choices' => [0 => ['outcomes' => $outcomes]]]]]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('encounter_outcomes', ['kind' => 'injury', 'ref' => 'neural_damage']);
        $this->assertDatabaseHas('encounter_outcomes', ['kind' => 'damage', 'ref' => 'hitpoints', 'amount' => 4]);
        $this->assertDatabaseHas('encounter_outcomes', ['kind' => 'damage', 'ref' => 'synaptic_integrity', 'amount' => 5]);

        // And the editor shows them as the picks, in their groups.
        $html = $this->actingAs($this->admin)->get(route('admin.encounters.edit', Encounter::query()->firstOrFail()))->getContent();
        $this->assertStringContainsString('<optgroup label="Injury">', $html);
        $this->assertStringContainsString('<optgroup label="Damage">', $html);
        $this->assertStringContainsString('<option value="injury:neural_damage" selected>', $html);
        $this->assertStringContainsString('<option value="damage:synaptic_integrity" selected>', $html);
        $this->assertStringNotContainsString('withdrawal_opiate', $html, 'drug withdrawals are not offered');
    }

    public function test_a_screen_whose_key_is_still_only_the_prefilled_default_is_a_blank_row(): void
    {
        $payload = $this->payload();
        $payload['nodes'][3] = ['key' => 'back_alley_', 'body' => ''];

        $this->save($payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3, Encounter::query()->firstOrFail()->nodes()->count());

        // But the same key WITH text is a real screen, and judged as one.
        $payload = $this->payload(['slug' => 'second']);
        $payload['nodes'][3] = ['key' => 'second_', 'body' => 'Now it has text.', 'ends' => 1];
        $this->save($payload)->assertSessionHasNoErrors();
        $this->assertSame(4, Encounter::query()->where('slug', 'second')->firstOrFail()->nodes()->count());
    }

    public function test_a_saved_encounter_is_playable(): void
    {
        $this->save($this->payload())->assertRedirect();
        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $encounter = Encounter::query()->firstOrFail();

        app(Encounters::class)->open($character, $encounter);

        $this->actingAs($character->user)->get(route('encounter.show'))->assertOk()->assertSeeText('Somebody steps out.');
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusals(): iterable
    {
        yield 'no entry node' => [['entry' => 99], 'entry'];
        yield 'a target that is not a screen' => [['nodes' => [0 => ['choices' => [0 => ['next_key' => 'nowhere']]]]], 'nodes'];
        yield 'a stat that does not exist' => [['nodes' => [0 => ['choices' => [0 => ['check_stat' => 'no_such_stat']]]]], 'nodes.0.choices.0.check_stat'];
        yield 'a branch outcome on a choice with no roll' => [['nodes' => [0 => ['choices' => [0 => ['check_stat' => '', 'difficulty' => '', 'pass_key' => '']]]]], 'nodes.0.choices.0.outcomes.0.branch'];
        yield 'an item outcome with no item' => [['nodes' => [0 => ['choices' => [0 => ['outcomes' => [2 => ['kind' => 'item', 'amount' => 1]]]]]]], 'nodes.0.choices.0.outcomes.2.item_id'];
        yield 'a damage outcome against nothing' => [['nodes' => [0 => ['choices' => [0 => ['outcomes' => [2 => ['kind' => 'damage', 'amount' => 3, 'ref' => 'luck']]]]]]], 'nodes.0.choices.0.outcomes.2.ref'];
        yield 'a drug withdrawal, which DrugLedger alone writes' => [['nodes' => [0 => ['choices' => [0 => ['outcomes' => [2 => ['kind' => 'injury', 'amount' => 3, 'ref' => 'withdrawal_opiate']]]]]]], 'nodes.0.choices.0.outcomes.2.ref'];
        yield 'an injury that is not defined' => [['nodes' => [0 => ['choices' => [0 => ['outcomes' => [2 => ['kind' => 'injury', 'amount' => 3, 'ref' => 'sunburn']]]]]]], 'nodes.0.choices.0.outcomes.2.ref'];
        yield 'a screen that is neither an ending nor has a choice' => [['nodes' => [1 => ['ends' => 0]]], 'nodes.1.choices'];
        yield 'a place encounter with no place' => [['starts_on' => 'place'], 'location_id'];
        yield 'a bad slug' => [['slug' => 'Back Alley'], 'slug'];
        yield 'a weight of nothing' => [['weight' => 0], 'weight'];
    }

    /** @param  array<string, mixed>  $override */
    #[DataProvider('refusals')]
    public function test_it_refuses(array $override, string $field): void
    {
        $this->save($this->payload($override))->assertSessionHasErrors($field);
        $this->assertSame(0, Encounter::query()->count());
    }

    public function test_it_refuses_a_trap(): void
    {
        // Both screens lead only to each other: nobody who enters can ever leave.
        $trap = [
            'entry' => 0,
            'nodes' => [
                0 => ['key' => 'a', 'body' => 'A', 'choices' => [0 => ['label' => 'to b', 'next_key' => 'b', 'check_stat' => '', 'difficulty' => '', 'pass_key' => '', 'outcomes' => []]]],
                1 => ['key' => 'b', 'body' => 'B', 'ends' => 0, 'choices' => [0 => ['label' => 'to a', 'next_key' => 'a']]],
                2 => ['key' => '', 'body' => ''],
            ],
        ];
        unset($this->payload()['nodes'][3]);

        $this->save([...$this->payload(), 'nodes' => $trap['nodes']])->assertSessionHasErrors('nodes');
        $this->assertSame(0, Encounter::query()->count());
    }

    public function test_a_loop_with_a_way_out_is_fine(): void
    {
        $nodes = [
            0 => ['key' => 'a', 'body' => 'A', 'choices' => [
                0 => ['label' => 'again', 'next_key' => 'a'],
                1 => ['label' => 'leave', 'next_key' => ''],
            ]],
        ];

        $this->save([...$this->payload(), 'nodes' => $nodes, 'entry' => 0])->assertSessionHasNoErrors();
        $this->assertSame(1, Encounter::query()->count());
    }

    public function test_a_screen_owns_outcomes_and_they_round_trip_through_the_editor(): void
    {
        // On an ENDING, which is the point: its choices are ignored but its own outcomes still happen.
        $payload = $this->payload(['nodes' => [2 => ['outcomes' => [
            0 => ['branch' => 'always', 'kind' => 'damage:hitpoints', 'amount' => 5],
            1 => ['branch' => 'always', 'kind' => ''],
        ]]]]);

        $this->save($payload)->assertSessionHasNoErrors();

        $fled = Encounter::query()->firstOrFail()->nodes()->where('node_key', 'fled')->firstOrFail();
        $this->assertSame(1, $fled->outcomes()->count(), 'the blank one was skipped');
        $this->assertDatabaseHas('encounter_outcomes', ['node_id' => $fled->id, 'choice_id' => null, 'kind' => 'damage', 'ref' => 'hitpoints']);

        $html = $this->actingAs($this->admin)->get(route('admin.encounters.edit', Encounter::query()->firstOrFail()))->getContent();
        $this->assertStringContainsString('name="nodes[2][outcomes][0][kind]"', $html);
        $this->assertStringContainsString('<option value="damage:hitpoints" selected>', $html);

        // Saving again replaces them rather than doubling them.
        $again = $payload;
        unset($again['slug']);
        $this->actingAs($this->admin)->put(route('admin.encounters.update', Encounter::query()->firstOrFail()), $again)->assertSessionHasNoErrors();
        $this->assertSame(1, EncounterOutcome::query()->whereNotNull('node_id')->count());
    }

    public function test_a_screen_s_outcome_cannot_be_on_pass_or_fail(): void
    {
        $this->save($this->payload(['nodes' => [1 => ['outcomes' => [0 => ['branch' => 'pass', 'kind' => 'creds', 'amount' => 5]]]]]))
            ->assertSessionHasErrors('nodes.1.outcomes.0.branch');
    }

    public function test_deleting_a_screen_takes_its_outcomes_with_it(): void
    {
        $this->save($this->payload(['nodes' => [2 => ['outcomes' => [0 => ['branch' => 'always', 'kind' => 'creds', 'amount' => 5]]]]]))->assertSessionHasNoErrors();
        $this->assertSame(1, EncounterOutcome::query()->whereNotNull('node_id')->count());

        Encounter::query()->firstOrFail()->nodes()->delete();

        $this->assertSame(0, EncounterOutcome::query()->whereNotNull('node_id')->count());
    }

    public function test_a_dose_outcome_needs_a_drug(): void
    {
        $plain = Item::factory()->physical()->create();
        $drug = Item::factory()->physical()->create();
        Drug::query()->create(['item_id' => $drug->id, 'family' => Family::Stimulant, 'duration_minutes' => 30, 'addictive' => 1.0, 'tolerance_gain' => 1.0]);

        $with = fn (?int $id) => $this->payload(['nodes' => [0 => ['choices' => [0 => ['outcomes' => [2 => ['kind' => 'dose', 'item_id' => $id]]]]]]]);

        $this->save($with(null))->assertSessionHasErrors('nodes.0.choices.0.outcomes.2.item_id');
        $this->save($with($plain->id))->assertSessionHasErrors('nodes.0.choices.0.outcomes.2.item_id');
        $this->assertSame(0, Encounter::query()->count());

        $this->save($with($drug->id))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('encounter_outcomes', ['kind' => 'dose', 'item_id' => $drug->id, 'amount' => null]);
    }

    public function test_an_item_outcome_with_an_item_saves(): void
    {
        $item = Item::factory()->physical()->create();
        $payload = $this->payload(['nodes' => [0 => ['choices' => [0 => ['outcomes' => [2 => ['kind' => 'item', 'amount' => 2, 'item_id' => $item->id]]]]]]]);

        $this->save($payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('encounter_outcomes', ['kind' => 'item', 'item_id' => $item->id]);
    }

    public function test_saving_again_replaces_the_graph_and_leaves_somebody_mid_encounter_where_they_are(): void
    {
        $this->save($this->payload())->assertRedirect();
        $encounter = Encounter::query()->firstOrFail();
        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        app(Encounters::class)->open($character, $encounter);

        // A slug is permanent: a different one is refused, and an empty one is ignored rather than written.
        $this->actingAs($this->admin)->put(route('admin.encounters.update', $encounter), $this->payload(['slug' => 'other']))
            ->assertSessionHasErrors('slug');
        $this->actingAs($this->admin)->put(route('admin.encounters.update', $encounter), $this->payload(['slug' => null]))
            ->assertSessionHasNoErrors();
        $this->assertSame('back_alley', $encounter->fresh()->slug);

        $payload = $this->payload();
        unset($payload['slug']);
        $payload['title'] = 'Renamed';
        $this->actingAs($this->admin)->put(route('admin.encounters.update', $encounter), $payload)->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $encounter->fresh()->title);
        $this->assertSame(3, $encounter->nodes()->count(), 'replaced, not doubled');
        $this->assertNotNull($character->fresh()->current_encounter_id);
        $this->actingAs($character->user)->get(route('encounter.show'))->assertOk()->assertSeeText('Somebody steps out.');
    }

    public function test_deleting_lets_anybody_inside_out(): void
    {
        $this->save($this->payload());
        $encounter = Encounter::query()->firstOrFail();
        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        app(Encounters::class)->open($character, $encounter);

        $this->actingAs($this->admin)->delete(route('admin.encounters.destroy', $encounter))->assertRedirect();

        $this->assertNull($character->fresh()->current_encounter_id);
    }

    // ---- a fight choice -------------------------------------------------------------------

    private function thug(): Npc
    {
        return Npc::create(['slug' => 'thug', 'name' => 'Thug', 'grade' => 2, 'hitpoints' => 40]);
    }

    /** A payload whose only way out of the opening screen is a fight. */
    private function fightPayload(Npc $npc, array $choice = []): array
    {
        return [
            'slug' => 'ambush', 'title' => 'Ambush', 'starts_on' => 'travel', 'weight' => 5, 'entry' => 0,
            'nodes' => [
                0 => ['key' => 'start', 'body' => 'Somebody steps out.', 'choices' => [
                    0 => array_replace(['label' => 'Fight', 'cost' => 5, 'requires_flag' => 'armed', 'fight_npc_id' => $npc->id, 'win_key' => 'won', 'lose_key' => 'lost', 'flee_key' => 'away'], $choice),
                ]],
                1 => ['key' => 'won', 'body' => 'You win.', 'ends' => 1, 'outcomes' => [0 => ['kind' => 'creds', 'amount' => 100]]],
                2 => ['key' => 'lost', 'body' => 'You wake in the gutter.', 'ends' => 1],
                3 => ['key' => 'away', 'body' => 'You slip away.', 'ends' => 1],
            ],
        ];
    }

    public function test_a_fight_choice_saves_its_opponent_and_three_ways_out_and_shows_them_back(): void
    {
        $npc = $this->thug();
        $this->save($this->fightPayload($npc))->assertRedirect();

        $choice = Encounter::query()->sole()->nodes()->where('node_key', 'start')->sole()->choices()->sole();

        $this->assertTrue($choice->isFight());
        $this->assertSame($npc->id, $choice->fight_npc_id);
        $this->assertSame(['won', 'lost', 'away'], [$choice->win_key, $choice->lose_key, $choice->flee_key]);
        $this->assertNull($choice->next_key);
        $this->assertSame(5, $choice->cost, 'a fight may still cost');
        $this->assertSame('armed', $choice->requires_flag, 'and be gated');
        $this->assertSame('Thug', $choice->opponent->name);

        $html = $this->actingAs($this->admin)->get(route('admin.encounters.edit', Encounter::query()->sole()))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="'.$npc->id.'"[^>]*selected/', $html);
        $this->assertMatchesRegularExpression('/name="nodes\[0\]\[choices\]\[0\]\[lose_key\]"[^>]*value="lost"/', $html);
        $this->assertStringContainsString('data-text-win=', $html, 'the graph is told what to call its fight edges');
    }

    public function test_the_three_ways_out_may_close_the_encounter(): void
    {
        $this->save($this->fightPayload($this->thug(), ['win_key' => '', 'lose_key' => '', 'flee_key' => '']))->assertRedirect();

        $choice = Encounter::query()->sole()->nodes()->where('node_key', 'start')->sole()->choices()->sole();
        $this->assertSame([null, null, null], [$choice->win_key, $choice->lose_key, $choice->flee_key]);
    }

    public function test_a_fight_choice_refuses_what_it_cannot_also_be(): void
    {
        $npc = $this->thug();

        foreach ([
            'a roll' => ['check_stat' => 'intellect', 'difficulty' => 10],
            'a next screen' => ['next_key' => 'won'],
            'an outcome of its own' => ['outcomes' => [0 => ['kind' => 'creds', 'amount' => 5]]],
        ] as $why => $extra) {
            $this->save($this->fightPayload($npc, $extra))->assertSessionHasErrors('nodes.0.choices.0.fight_npc_id');
            $this->assertSame(0, Encounter::query()->count(), $why);
        }
    }

    public function test_a_fight_choice_names_every_missing_screen_and_an_unknown_opponent(): void
    {
        $npc = $this->thug();

        $this->save($this->fightPayload($npc, ['win_key' => 'nowhere', 'flee_key' => 'also_nowhere']))
            ->assertSessionHasErrors(['nodes']);
        $this->assertStringContainsString('nowhere, also_nowhere', (string) session('errors')->first('nodes'));

        $this->save($this->fightPayload($npc, ['fight_npc_id' => 999999]))->assertSessionHasErrors('nodes.0.choices.0.fight_npc_id');
    }

    public function test_the_graph_counts_a_fights_three_ways_as_ways_out(): void
    {
        $fight = static fn (?string $win, ?string $lose, ?string $flee): array => ['next' => null, 'pass' => null, 'fail' => null, 'check' => false, 'fight' => ['win' => $win, 'lose' => $lose, 'flee' => $flee]];

        // A screen whose only button is a fight that loops back to itself on every ending is a trap...
        $this->assertSame(['a'], (new EncounterGraph(['a' => ['ends' => false, 'choices' => [$fight('a', 'a', 'a')]]]))->stuck());
        // ...but one that can lose to an ending is not, and neither is one that may close the encounter.
        $this->assertSame([], (new EncounterGraph(['a' => ['ends' => false, 'choices' => [$fight('a', 'z', 'a')]], 'z' => ['ends' => true, 'choices' => []]]))->stuck());
        $this->assertSame([], (new EncounterGraph(['a' => ['ends' => false, 'choices' => [$fight('a', 'a', null)]]]))->stuck());
        $this->assertSame(['x', 'y', null], EncounterGraph::targets($fight('x', 'y', null)));
    }

    public function test_an_opponent_an_encounter_uses_cannot_be_deleted(): void
    {
        $npc = $this->thug();
        $this->save($this->fightPayload($npc))->assertRedirect();

        $this->actingAs($this->admin)->delete(route('admin.npcs.destroy', $npc))
            ->assertSessionHas('error', 'An encounter still fights it: Ambush. Change that button first.');
        $this->assertSame(1, Npc::query()->count());

        // The database says the same, whatever the controller does.
        $this->expectException(QueryException::class);
        $npc->delete();
    }

    public function test_an_opponent_nobody_fights_can_be_deleted(): void
    {
        $npc = $this->thug();

        $this->actingAs($this->admin)->delete(route('admin.npcs.destroy', $npc))->assertRedirect(route('admin.npcs.index'));
        $this->assertSame(0, Npc::query()->count());
    }

    public function test_the_graph_finds_a_trap_and_not_a_loop(): void
    {
        $choice = fn (?string $to): array => ['next' => $to, 'pass' => null, 'fail' => null, 'check' => false];

        $trap = new EncounterGraph([
            'a' => ['ends' => false, 'choices' => [$choice('b')]],
            'b' => ['ends' => false, 'choices' => [$choice('a')]],
            'c' => ['ends' => true, 'choices' => []],
        ]);
        $this->assertSame(['a', 'b'], $trap->stuck());

        $loop = new EncounterGraph([
            'a' => ['ends' => false, 'choices' => [$choice('a'), $choice('z')]],
            'z' => ['ends' => true, 'choices' => []],
        ]);
        $this->assertSame([], $loop->stuck());

        $closes = new EncounterGraph(['a' => ['ends' => false, 'choices' => [$choice(null)]]]);
        $this->assertSame([], $closes->stuck(), 'a choice with nowhere to go is a way out');
    }
}
