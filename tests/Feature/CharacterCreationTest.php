<?php

namespace Tests\Feature;

use App\Http\Controllers\CharacterCreationController;
use App\Models\Archetype;
use App\Models\ArchetypePerk;
use App\Models\Character;
use App\Models\Setting;
use App\Models\SuggestedHandle;
use App\Models\User;
use App\Rules\ValidHandle;
use App\Stats\Modifier;
use App\Stats\StatRegistry;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterCreationTest extends TestCase
{
    use RefreshDatabase;

    private const POINTS = 75;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put(CharacterCreationController::POINTS_SETTING, (string) self::POINTS, 'character_creation');
    }

    /** A valid payload whose attributes sum to exactly the pool. */
    private function payload(array $overrides = []): array
    {
        $attributes = Character::startingAttributes(self::POINTS);
        $attributes[Character::ATTRIBUTES[0]] += self::POINTS - array_sum($attributes);

        return array_merge($attributes, ['handle' => 'zer0c00l', 'archetype' => 1], $overrides);
    }

    // ---- registration no longer creates a character ---------------------

    public function test_registration_creates_only_the_user(): void
    {
        $this->post(route('register'), [
            'email' => 'v@nightcity.test',
            'password' => 'correct-horse-battery-42',
            'password_confirmation' => 'correct-horse-battery-42',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('characters', 0);
    }

    // ---- the gate --------------------------------------------------------

    public function test_a_user_without_a_character_is_redirected_from_anywhere(): void
    {
        $this->actingAs(User::factory()->create());

        // Any route in the web group, not just the landing page.
        $this->get(route('home'))->assertRedirect(route('character.create'));
        $this->get(route('login'))->assertRedirect(route('character.create'));
    }

    public function test_the_creation_page_itself_is_reachable(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            ->assertOk();
    }

    public function test_logout_stays_reachable_without_a_character(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertGuest();
    }

    public function test_guests_are_not_redirected(): void
    {
        $this->get(route('login'))->assertOk();
        $this->get(route('register'))->assertOk();
    }

    public function test_a_user_with_a_character_passes_through(): void
    {
        $character = Character::factory()->create();

        $this->actingAs($character->user)->get(route('home'))->assertOk();
    }

    public function test_json_callers_get_a_conflict_instead_of_a_redirect(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('home'))
            ->assertStatus(409)
            ->assertJsonStructure(['message', 'redirect']);
    }

    // ---- the form --------------------------------------------------------

    public function test_it_shows_every_archetype_and_attribute(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('character.create'));

        foreach (Archetype::roster() as $archetype) {
            $response->assertSee($archetype->name);
            $response->assertSee('value="'.$archetype->id.'"', false);
        }

        foreach (Character::ATTRIBUTES as $attribute) {
            $response->assertSee('name="'.$attribute.'"', false);
            $response->assertSee(Text::get("character.names.{$attribute}"));
        }

        $response->assertSee(Text::get('character.attributes.physical'));
        $response->assertSee(Text::get('character.attributes.mental'));
    }

    public function test_the_pool_comes_from_the_setting(): void
    {
        Setting::put(CharacterCreationController::POINTS_SETTING, '123', 'character_creation');

        $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            ->assertSee('data-points="123"', false);
    }

    public function test_a_missing_setting_falls_back_rather_than_breaking_the_page(): void
    {
        Setting::where('key', CharacterCreationController::POINTS_SETTING)->first()->delete();

        $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            ->assertOk()
            ->assertSee('data-points="'.CharacterCreationController::DEFAULT_ATTRIBUTE_POINTS.'"', false);
    }

    // ---- storing ---------------------------------------------------------

    public function test_it_creates_the_character(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('character.store'), $this->payload())
            ->assertRedirect(route('home'));

        $character = $user->fresh()->character;

        $this->assertSame('zer0c00l', $character->handle);
        $this->assertSame(1, $character->archetype);
        $this->assertSame(self::POINTS, array_sum($character->attributeScores()));
    }

    public function test_the_pool_must_be_spent_exactly(): void
    {
        $user = User::factory()->create();

        // Under budget, but every attribute still at or above the creation floor.
        $this->actingAs($user)
            ->post(route('character.store'), $this->payload([
                Character::ATTRIBUTES[0] => Character::CREATION_ATTRIBUTE_MIN,
            ]))
            ->assertSessionHasErrors('attributes');

        // Over budget, but every attribute still within the cap — so the sum
        // rule fires rather than a per-attribute max error.
        $this->actingAs($user)
            ->post(route('character.store'), $this->payload([Character::ATTRIBUTES[0] => Character::CREATION_ATTRIBUTE_MAX]))
            ->assertSessionHasErrors('attributes');

        $this->assertDatabaseCount('characters', 0);
    }

    public function test_the_server_rejects_an_overspend_even_though_the_browser_prevents_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('character.store'), array_merge(
                // Max out every attribute at the creation cap: 500 against a pool of 75.
                array_fill_keys(Character::ATTRIBUTES, Character::CREATION_ATTRIBUTE_MAX),
                ['handle' => 'cheater', 'archetype' => 1],
            ))
            ->assertSessionHasErrors('attributes');

        $this->assertDatabaseCount('characters', 0);
    }

    public function test_zero_is_rejected_at_creation(): void
    {
        // A skill governed by a zeroed attribute would train at 0 SP/minute
        // and could never complete, so a character may not start with one.
        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(['meat' => 0]))
            ->assertSessionHasErrors('meat');

        $this->assertDatabaseCount('characters', 0);
    }

    public function test_the_sliders_cannot_reach_zero(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            ->assertOk()
            ->assertSee('min="'.Character::CREATION_ATTRIBUTE_MIN.'"', false)
            ->assertDontSee('min="0"', false);
    }

    public function test_the_starting_spread_never_places_a_zero(): void
    {
        foreach ([10, 11, 19, 75, 150, 500] as $pool) {
            foreach (Character::startingAttributes($pool) as $attribute => $value) {
                $this->assertGreaterThanOrEqual(
                    Character::CREATION_ATTRIBUTE_MIN,
                    $value,
                    "pool {$pool} opened [{$attribute}] below the floor"
                );
            }
        }
    }

    public function test_a_pool_too_small_for_one_point_each_is_unsatisfiable(): void
    {
        $this->assertFalse(Character::poolIsSatisfiable(9), 'ten attributes need at least ten points');
        $this->assertTrue(Character::poolIsSatisfiable(10));
    }

    public function test_the_two_floors_are_distinct(): void
    {
        // 0 stays the lifetime floor: a debuff must be able to reach it.
        $this->assertSame(0, Character::ATTRIBUTE_MIN);
        $this->assertSame(1, Character::CREATION_ATTRIBUTE_MIN);
    }

    public function test_the_two_ceilings_are_distinct(): void
    {
        // 50 at creation, 100 for the rest of the character's life. Collapsing
        // these back into one constant silently truncates attributes grown
        // past 50, because the clamp on save uses the lifetime bound.
        $this->assertSame(50, Character::CREATION_ATTRIBUTE_MAX);
        $this->assertSame(100, Character::ATTRIBUTE_MAX);
    }

    public function test_growth_past_the_creation_cap_is_not_truncated_on_save(): void
    {
        $character = Character::factory()->create();

        $character->update(['focus' => 80]);

        $this->assertSame(80, $character->fresh()->focus);
    }

    public function test_growth_past_the_lifetime_cap_is_truncated(): void
    {
        $character = Character::factory()->create();

        $character->update(['focus' => 150]);

        $this->assertSame(Character::ATTRIBUTE_MAX, $character->fresh()->focus);
    }

    public function test_an_attribute_above_fifty_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(['meat' => Character::CREATION_ATTRIBUTE_MAX + 1]))
            ->assertSessionHasErrors('meat');

        $this->assertDatabaseCount('characters', 0);
    }

    public function test_fifty_exactly_is_accepted(): void
    {
        // Nine attributes at the floor of 1, plus meat at the ceiling of 50.
        Setting::put(CharacterCreationController::POINTS_SETTING, '59', 'character_creation');

        $attributes = array_fill_keys(Character::ATTRIBUTES, Character::CREATION_ATTRIBUTE_MIN);
        $attributes['meat'] = Character::CREATION_ATTRIBUTE_MAX;

        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), array_merge($attributes, ['handle' => 'atcap', 'archetype' => 1]))
            ->assertSessionHasNoErrors();

        $this->assertSame(50, Character::firstOrFail()->meat);
    }

    public function test_the_sliders_are_capped_at_the_creation_ceiling(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('character.create'));

        foreach (Character::ATTRIBUTES as $attribute) {
            $response->assertSee('max="'.Character::CREATION_ATTRIBUTE_MAX.'"', false);
        }

        // The lifetime ceiling must not leak into the creation screen.
        $response->assertDontSee('max="'.Character::ATTRIBUTE_MAX.'"', false);
    }

    // ---- even distribution ----------------------------------------------

    public function test_the_pool_is_spread_evenly_as_integers(): void
    {
        // 75 over ten attributes is 7 each, with 5 left for the player.
        $starting = Character::startingAttributes(75);

        $this->assertSame(array_fill_keys(Character::ATTRIBUTES, 7), $starting);
        $this->assertSame(70, array_sum($starting));
    }

    public function test_the_sliders_open_on_the_distributed_value(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            ->assertOk()
            ->assertSee('value="7"', false);
    }

    public function test_distribution_never_exceeds_the_cap(): void
    {
        $starting = Character::startingAttributes(10_000);

        foreach ($starting as $value) {
            $this->assertLessThanOrEqual(Character::CREATION_ATTRIBUTE_MAX, $value);
        }
    }

    public function test_an_unspendable_pool_is_reported_rather_than_rendered(): void
    {
        $tooMany = (Character::CREATION_ATTRIBUTE_MAX * count(Character::ATTRIBUTES)) + 1;
        Setting::put(CharacterCreationController::POINTS_SETTING, (string) $tooMany, 'character_creation');

        $this->assertFalse(Character::poolIsSatisfiable($tooMany));

        $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            ->assertOk()
            ->assertSee('cannot be built');
    }

    /**
     * The submit button is gated by three separate conditions, and the page
     * has to be able to name each one. A balanced pool with an empty handle
     * previously reported "Ready to jack in" beside a disabled button.
     */
    public function test_the_page_ships_a_message_for_every_thing_that_blocks_submit(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('character.create'));

        $response->assertSee(Text::get('character.handle.required'));
        $response->assertSee(Text::get('character.archetype.required'));
        $response->assertSee(Text::get('character.attributes.balanced'));

        // The three branches must all be present in the shipped script.
        $response->assertSee('needArchetype', false);
        $response->assertSee('needHandle', false);
        $response->assertSee('blocker !== null', false);
    }

    /**
     * With a pool that divides evenly the sliders open already balanced, so
     * every single move unbalances it and only an exact return re-balances it.
     * Dragging back by hand lands on 14 or 16 as often as 15, which reads as a
     * stuck button. Reset is the guaranteed way back to a valid state.
     */
    public function test_the_pool_panel_offers_a_reset(): void
    {
        Setting::put(CharacterCreationController::POINTS_SETTING, '150', 'character_creation');

        $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            ->assertOk()
            ->assertSee('id="pool-reset"', false)
            ->assertSee('data-each="15"', false)
            ->assertSee(Text::get('character.attributes.reset'));
    }

    /**
     * The pool readout and the derived readouts are refreshed by two different
     * functions. Reset was wired to only one of them, so resetting the spread
     * left HP and SI showing the previous values.
     *
     * The fix was a single update() entry point that every trigger calls. This
     * asserts nothing binds the halves directly again — a new trigger wired to
     * refresh() alone would reintroduce exactly the same bug.
     */
    public function test_every_form_trigger_goes_through_one_updater(): void
    {
        $page = $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            ->getContent();

        $this->assertStringContainsString('function update() {', $page);

        foreach (["'input', update", "'change', update", 'update();'] as $wiring) {
            $this->assertStringContainsString($wiring, $page, 'expected triggers to bind update(), not a half of it');
        }

        // No listener may bind a single half directly.
        foreach (["'input', refresh", "'input', refreshDerived", "'change', refresh", "'change', refreshDerived"] as $halfWiring) {
            $this->assertStringNotContainsString(
                $halfWiring,
                $page,
                "[{$halfWiring}] updates only half the screen; bind update() instead"
            );
        }
    }

    /** The pool is the only explanation for a disabled button; it must stay visible. */
    public function test_the_pool_panel_is_sticky(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            ->assertOk()
            ->assertSee('pool-sticky', false)
            ->assertSee('position: sticky', false);
    }

    // ---- attribute explainer --------------------------------------------

    public function test_every_attribute_name_opens_the_explainer(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('character.create'));

        foreach (Character::ATTRIBUTES as $attribute) {
            $response->assertSee('data-attr="'.$attribute.'"', false);
            $response->assertSee('id="info-'.$attribute.'"', false);
        }

        $response->assertSee('data-open="#attribute-info"', false);
        $response->assertSee('<dialog class="modal" id="attribute-info"', false);
    }

    public function test_the_modal_describes_every_attribute(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('character.create'));

        foreach (Character::ATTRIBUTES as $attribute) {
            $response->assertSee(Text::get("character.descriptions.{$attribute}"));
        }

        $response->assertSee(Text::get('character.info.title'));
    }

    public function test_every_attribute_has_a_description_string(): void
    {
        foreach (Character::ATTRIBUTES as $attribute) {
            $this->assertTrue(
                Text::has("character.descriptions.{$attribute}"),
                "missing description for [{$attribute}]"
            );
        }
    }

    /**
     * The name is a button now, so the slider lost its <label for>. It has to
     * keep an accessible name some other way or the control is unlabelled.
     */
    public function test_the_sliders_remain_labelled_for_assistive_tech(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('character.create'));

        foreach (Character::ATTRIBUTES as $attribute) {
            $response->assertSee('aria-label="'.Text::get("character.names.{$attribute}").'"', false);
        }
    }

    // ---- archetype perks -------------------------------------------------

    /** The label is on the perk's row; nothing else knows it. */
    private function perkLabel(string $key): string
    {
        return ArchetypePerk::query()->where('key', $key)->value('label');
    }

    public function test_the_cards_show_the_perks_that_exist(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('character.create'));

        $response->assertSee('card__stat', false);

        // Live perks: label on the left, effect generated from the modifier.
        $response->assertSee($this->perkLabel('grey_matter'));
        $response->assertSee('+5% '.Text::get('character.names.synaptic_integrity_max'), false);
        $response->assertSee($this->perkLabel('scar_tissue'));
        $response->assertSee('+5% '.Text::get('character.names.hitpoints_max'), false);

        // The Crim's second perk targets a TAG SET rather than a stat, and the
        // card names the set as a whole — "physical damage taken" is what a
        // person calls it, where the key is damage+hitpoints.
        $response->assertSee($this->perkLabel('damage_taken'));
        $response->assertSee('−5% '.Text::get('character.names.damage+hitpoints'), false);
    }

    /**
     * Nothing ships as display-only any more. The shape is kept for content
     * that may want it, so the card has to keep rendering one — this builds a
     * perk that exists nowhere else and looks for its legacy pair on the page.
     */
    public function test_a_display_only_perk_still_renders_from_its_legacy_pair(): void
    {
        ArchetypePerk::query()->create([
            'archetype_id' => 1,
            'key' => 'looks_the_part',
            'label' => 'Looks the part',
            'display_modifier' => -15,
            'display_unit' => 'percent',
            'position' => 9,
        ]);

        Archetype::forgetRoster();

        $response = $this->actingAs(User::factory()->create())->get(route('character.create'));

        $response->assertSee('Looks the part');
        $response->assertSee('−15%', false);
    }

    public function test_archetypes_without_perks_render_no_stat_rows(): void
    {
        $withoutPerks = Archetype::roster()->filter(
            fn (Archetype $archetype): bool => $archetype->perks->isEmpty(),
        );

        $this->assertNotEmpty($withoutPerks, 'expected at least one archetype with no perks');

        // Two archetypes carry perks, so exactly two cards get a stats block.
        $response = $this->actingAs(User::factory()->create())->get(route('character.create'));

        $this->assertSame(2, substr_count($response->getContent(), 'card__stats'));
    }

    /**
     * PHP array literals silently keep the last of two identical keys, so a
     * duplicated id does not fail to parse — it DELETES an archetype. The
     * roster used to be spread across a config file and a lang file, and
     * comparing the two caught it; now that every archetype is one entry,
     * counting distinct slugs is what is left to catch it with.
     */
    /**
     * The roster is inserted by its own migration, with ids that character
     * rows already reference. What this guards is that it arrived whole.
     */
    public function test_the_seeded_roster_is_whole(): void
    {
        $roster = Archetype::roster();

        $this->assertCount(7, $roster, 'the roster changed size');
        $this->assertSame(
            $roster->count(),
            $roster->pluck('slug')->unique()->count(),
            'two archetypes share a slug'
        );

        foreach ($roster as $id => $archetype) {
            $this->assertSame($id, $archetype->id);
            $this->assertNotSame('', $archetype->name);
            $this->assertNotSame('', $archetype->role);
            $this->assertNotSame('', $archetype->description);
        }
    }

    public function test_every_archetype_perk_is_valid_and_labelled(): void
    {
        // The FULL registry, not config alone: a perk may target a generated
        // stat — a skill's check, or one of the damage stats — and those are
        // no less declared for being generated.
        $registry = app(StatRegistry::class);

        foreach (Archetype::roster() as $archetype) {
            foreach ($archetype->perks as $perk) {
                $this->assertNotSame('', $perk->displayLabel(), "perk [{$perk->key}] has no label");

                if ($perk->isLive()) {
                    foreach ($perk->modifiers as $definition) {
                        // Throws on an unknown op, unknown key or bad target.
                        $modifier = Modifier::fromArray($definition);

                        $this->assertNotSame(
                            [],
                            $registry->matching($modifier->target),
                            "perk [{$perk->key}] targets nothing that exists"
                        );
                    }

                    continue;
                }

                $this->assertContains($perk->display_unit, ['percent', 'flat', 'attribute']);
            }
        }
    }

    public function test_an_unknown_archetype_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(['archetype' => 999]))
            ->assertSessionHasErrors('archetype');
    }

    public function test_a_taken_handle_is_rejected(): void
    {
        Character::factory()->create(['handle' => 'zer0c00l']);

        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload())
            ->assertSessionHasErrors(['handle' => Text::get('auth.handle_taken')]);
    }

    public function test_an_invalid_handle_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(['handle' => 'has space']))
            ->assertSessionHasErrors('handle');
    }

    public function test_the_handle_is_stored_normalised(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('character.store'), $this->payload([
            'handle' => "e\u{0301}clair",
        ]));

        $this->assertSame("\u{00E9}clair", $user->fresh()->character->handle);
    }

    public function test_creating_a_character_retires_a_pooled_handle(): void
    {
        SuggestedHandle::create(['handle' => 'zer0c00l', 'in_use' => false]);

        $this->actingAs(User::factory()->create())->post(route('character.store'), $this->payload());

        $this->assertDatabaseHas('suggest_hacker_handles', ['handle' => 'zer0c00l', 'in_use' => true]);
    }

    public function test_after_creating_a_character_the_gate_lets_the_player_through(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('home'))->assertRedirect(route('character.create'));
        $this->actingAs($user)->post(route('character.store'), $this->payload());
        $this->actingAs($user->fresh())->get(route('home'))->assertOk();
    }

    // ---- which archetypes are on offer -----------------------------------

    public function test_only_the_deck_jockey_is_offered(): void
    {
        // The roster is seven and the menu is one. The rest are written but
        // not built, so they are withdrawn rather than deleted — every
        // character who ever chose one still points at the id.
        $this->assertSame(['deckjockey'], Archetype::playable()->pluck('slug')->all());
        $this->assertCount(7, Archetype::roster());
    }

    public function test_a_withdrawn_archetype_is_shown_but_cannot_be_chosen(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('character.create'));

        $response->assertOk();

        // Still on the page, in full: a role nobody can pick yet is worth
        // reading, and hiding it would make the roster look shorter than it is.
        $response->assertSee('Crim');
        $response->assertSee(Text::get('character.archetype.unavailable'));
        $response->assertSee('value="2"', false);

        // The kit half-lights a .card--select holding a disabled radio.
        $this->assertStringContainsString(
            'value="2"',
            $this->radioMarkup($response->getContent(), 2),
        );
        $this->assertStringContainsString('disabled', $this->radioMarkup($response->getContent(), 2));
        $this->assertStringNotContainsString('disabled', $this->radioMarkup($response->getContent(), 1));
    }

    public function test_the_server_refuses_a_withdrawn_archetype(): void
    {
        // The cards disable it; the browser is not a security boundary.
        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(['archetype' => 2]))
            ->assertSessionHasErrors('archetype');

        $this->assertDatabaseCount('characters', 0);
    }

    public function test_offering_an_archetype_again_is_one_column(): void
    {
        Archetype::whereKey(2)->update(['playable' => true]);
        Archetype::forgetRoster();

        $this->actingAs(User::factory()->create())
            ->post(route('character.store'), $this->payload(['archetype' => 2]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Character::firstOrFail()->archetype);
    }

    public function test_a_character_of_a_withdrawn_archetype_still_resolves(): void
    {
        // Withdrawing is not deleting: the perks, the name and the role all
        // still have to work for whoever already chose it.
        $character = Character::factory()->create(['archetype' => 2]);

        $this->assertSame('Crim', Archetype::fromRoster(2)?->name);
        $this->assertNotEmpty($character->modifiers());
        $this->assertGreaterThan(0, $character->stat('hitpoints_max')->value);
    }

    /** The one archetype radio, so a test can ask whether it is disabled. */
    private function radioMarkup(string $html, int $id): string
    {
        preg_match('/<input type="radio" name="archetype" value="'.$id.'".*?>/s', $html, $matches);

        return $matches[0] ?? '';
    }

    // ---- the gate in the other direction ---------------------------------

    public function test_a_player_with_a_character_cannot_reach_the_creation_screen(): void
    {
        $user = User::factory()->create();
        Character::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('character.create'))
            ->assertRedirect(route('home'));
    }

    public function test_a_player_with_a_character_cannot_create_a_second(): void
    {
        $user = User::factory()->create();
        Character::factory()->create(['user_id' => $user->id, 'handle' => 'first']);

        // Without the guard this is not a refusal but a 500: characters.user_id
        // is unique, so the insert fails on an integrity error.
        $this->actingAs($user)
            ->post(route('character.store'), $this->payload())
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', Text::get('character.create.already'));

        $this->assertDatabaseCount('characters', 1);
        $this->assertSame('first', $user->fresh()->character->handle);
    }

    public function test_a_json_caller_with_a_character_gets_a_conflict(): void
    {
        $user = User::factory()->create();
        Character::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->postJson(route('character.store'), $this->payload())
            ->assertStatus(409)
            ->assertJson(['redirect' => route('home')]);
    }

    public function test_an_admin_with_a_character_may_still_look_at_the_screen(): void
    {
        $admin = User::factory()->admin()->create();
        Character::factory()->create(['user_id' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('character.create'));

        $response->assertOk();
        $response->assertSee(Text::get('character.create.preview'));

        // Looking, not saving: the button is not rendered at all, because the
        // pool updater would re-enable a disabled one.
        $response->assertDontSee('id="character-submit"', false);
    }

    public function test_an_admin_with_a_character_still_cannot_create_a_second(): void
    {
        $admin = User::factory()->admin()->create();
        Character::factory()->create(['user_id' => $admin->id, 'handle' => 'first']);

        $this->actingAs($admin)
            ->post(route('character.store'), $this->payload())
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', Text::get('character.create.already'));

        $this->assertDatabaseCount('characters', 1);
        $this->assertSame('first', $admin->fresh()->character->handle);
    }

    public function test_an_admin_without_a_character_gets_the_ordinary_form(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('character.create'))
            ->assertOk()
            ->assertSee('id="character-submit"', false)
            ->assertDontSee(Text::get('character.create.preview'));

        $this->actingAs($admin)
            ->post(route('character.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('characters', 1);
    }

    public function test_the_exception_is_a_read_and_belongs_to_admins_only(): void
    {
        // The same request from a player with a character is still bounced —
        // the preview is a screen an admin maintains, not a screen anyone may
        // revisit.
        $user = User::factory()->create();
        Character::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('character.create'))
            ->assertRedirect(route('home'));
    }

    public function test_the_guard_does_not_touch_a_player_who_has_no_character(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('character.create'))->assertOk();
        $this->actingAs($user)->post(route('character.store'), $this->payload())
            ->assertRedirect(route('home'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('characters', 1);
    }

    public function test_max_length_handle_is_accepted_here(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('character.store'), $this->payload([
            'handle' => str_repeat('a', ValidHandle::MAX_LENGTH),
        ]))->assertSessionHasNoErrors();
    }
}
