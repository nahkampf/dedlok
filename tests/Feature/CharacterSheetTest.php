<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Drugs\Family;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\Dose;
use App\Models\Drug;
use App\Models\DrugUse;
use App\Models\Item;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The character sheet: who you are, rather than what you are carrying.
 *
 * THE WINDOW IS FOUR ROUTES, NOT FOUR PANELS. Skills, implants and
 * achievements kept their URLs and their controllers and became tabs, so what
 * is asserted here is that the strip is on all four and says which one you are
 * looking at — and that opening the sheet does not draw the other three.
 */
final class CharacterSheetTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3, 'handle' => 'Dedlok'],
        ));
    }

    private function visit(): TestResponse
    {
        return $this->actingAs($this->character->user)->get(route('character.index'));
    }

    // ---- what is on it -----------------------------------------------------

    public function test_the_sheet_names_the_character_and_their_role(): void
    {
        $this->visit()
            ->assertOk()
            ->assertSeeText('Dedlok')
            ->assertSeeText(Text::get('character.attributes_heading'));
    }

    public function test_the_attributes_are_resolved(): void
    {
        Config::set('effects.wired', [
            'label' => 'Wired',
            'stacking' => 'sum',
            'modifiers' => [
                ['op' => 'add', 'amount' => 15, 'target' => ['id' => 'focus']],
            ],
        ]);

        CharacterModifier::query()->create([
            'character_id' => $this->character->id,
            'definition_key' => 'wired',
        ]);

        // The label a bar carries, which is also the only place the number is
        // said in words — the bar itself is a ratio.
        $this->visit()->assertSee(Text::get('character.attribute_value', [
            'attribute' => Text::get('character.names.focus'),
            'value' => 35,
            'max' => Character::ATTRIBUTE_MAX,
        ]), false);
    }

    public function test_the_vitals_name_what_moved_each_maximum(): void
    {
        Config::set('effects.frail', [
            'label' => 'Frail',
            'stacking' => 'sum',
            'modifiers' => [
                ['op' => 'add', 'amount' => -4, 'target' => ['id' => 'hitpoints_max']],
            ],
        ]);

        CharacterModifier::query()->create([
            'character_id' => $this->character->id,
            'definition_key' => 'frail',
        ]);

        $this->visit()
            ->assertSeeText(Text::get('character.vitals_heading'))
            ->assertSeeText(Text::get('character.vitals_modified'))
            ->assertSeeText('Frail')
            ->assertSee('−4', false)
            // SI has nothing on it.
            ->assertSeeText(Text::get('character.vitals_unmodified'));
    }

    /**
     * The dose panel is per FAMILY, and a family with nothing in it and clean
     * books is left out — a row of zeroes would describe a problem nobody has.
     */
    public function test_the_sheet_says_what_is_in_them(): void
    {
        $item = Item::factory()->create(['container' => 'physical', 'name' => 'Redline']);
        Drug::query()->create([
            'item_id' => $item->id,
            'family' => Family::Stimulant,
            'duration_minutes' => 60,
            'addictive' => 4.0,
            'tolerance_gain' => 5.0,
        ]);

        Dose::query()->create([
            'character_id' => $this->character->id,
            'item_id' => $item->id,
            'potency' => 0.8,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        DrugUse::query()->create([
            'character_id' => $this->character->id,
            'family' => Family::Stimulant,
            'addiction' => 12.0,
            'tolerance' => 5.0,
        ]);

        $this->visit()
            ->assertSeeText(Text::get('character.doses_heading'))
            ->assertSeeText(Family::Stimulant->label())
            ->assertSeeText('Redline')
            ->assertSeeText(Text::get('character.doses_potency', ['percent' => 80]))
            // Two of a possible two, and no card for a family they are clean of.
            ->assertSeeText(Text::get('character.doses_capacity', ['used' => 1, 'capacity' => 2]))
            ->assertDontSeeText(Family::Opiate->label());
    }

    /** One more dose would overdose: a triangle with the warning, and none while there is room. */
    public function test_the_sheet_warns_when_one_more_dose_would_overdose(): void
    {
        $item = Item::factory()->create(['container' => 'physical', 'name' => 'Redline']);
        Drug::query()->create(['item_id' => $item->id, 'family' => Family::Stimulant, 'duration_minutes' => 60, 'addictive' => 0, 'tolerance_gain' => 0]);
        $dose = fn () => Dose::query()->create([
            'character_id' => $this->character->id, 'item_id' => $item->id, 'potency' => 1.0,
            'started_at' => now(), 'expires_at' => now()->addHour(),
        ]);

        $dose();
        $this->visit()->assertDontSee('class="warn-icon', false);

        $dose();   // two of a possible two
        $this->visit()->assertSee('class="warn-icon', false)->assertSee('data-tip="'.Text::get('character.doses_risk').'"', false);
    }

    public function test_a_clean_character_has_no_dose_panel(): void
    {
        $this->visit()->assertOk()->assertDontSeeText(Text::get('character.doses_heading'));
    }

    // ---- the tabs ----------------------------------------------------------

    /** Every tab is on every panel, and each panel says which one it is. */
    public function test_the_strip_is_on_all_four_and_marks_the_current_one(): void
    {
        $routes = ['character.index', 'skills.index', 'implants.index', 'achievements.index'];

        foreach ($routes as $current) {
            $response = $this->actingAs($this->character->user)->get(route($current));
            $response->assertOk();

            $strip = $this->strip($response->getContent());

            foreach ($routes as $link) {
                $this->assertStringContainsString(route($link), $strip, "{$current} is missing {$link}");
            }

            // Exactly one tab is the page you are on, and it is this one.
            $this->assertSame(1, substr_count($strip, 'aria-current="page"'), $current);
            $this->assertMatchesRegularExpression(
                '#href="'.preg_quote(route($current), '#').'"\s+aria-current="page"#',
                $strip,
                $current,
            );
        }
    }

    /**
     * The tab strip alone. The rail marks itself with aria-current too — it is
     * current for all four of these — so counting on the whole page would
     * count the rail's mark as well.
     */
    private function strip(string $html): string
    {
        $start = strpos($html, '<nav class="tabs"');
        $this->assertNotFalse($start, 'the character window has no tab strip');

        return substr($html, $start, (int) strpos($html, '</nav>', $start) - $start);
    }

    // ---- the bio -----------------------------------------------------------

    public function test_a_bio_is_saved_and_shown_back(): void
    {
        $this->actingAs($this->character->user)
            ->from(route('character.index'))
            ->post(route('character.bio'), ['bio' => 'Ran the Kabuki relay before it burned.'])
            ->assertRedirect(route('character.index'))
            ->assertSessionHas('status', Text::get('character.bio_saved'));

        $this->assertSame('Ran the Kabuki relay before it burned.', $this->character->fresh()->bio);

        $this->visit()->assertSee('Ran the Kabuki relay before it burned.', false);
    }

    public function test_the_bio_is_a_monospace_field_that_does_not_wrap(): void
    {
        $this->visit()->assertSee('class="input input--mono" id="bio"', false)->assertSee('wrap="off"', false);
    }

    /**
     * ASCII art keeps its shape: the first line's indentation survives, the browser's CRLF becomes LF, and only
     * blank lines at the start and whitespace at the very end go.
     */
    public function test_a_bios_leading_spaces_survive_for_ascii_art(): void
    {
        $art = "\r\n\r\n    /\\_/\\   \r\n   ( o.o )\r\n    > ^ <\r\n\r\n";

        $this->actingAs($this->character->user)->from(route('character.index'))->post(route('character.bio'), ['bio' => $art]);

        $this->assertSame("    /\\_/\\   \n   ( o.o )\n    > ^ <", $this->character->fresh()->bio);
    }

    /** Clearing the box is how somebody takes their bio down. */
    public function test_an_empty_bio_is_null_rather_than_blank(): void
    {
        $this->character->forceFill(['bio' => 'Something'])->save();

        $this->actingAs($this->character->user)
            ->from(route('character.index'))
            ->post(route('character.bio'), ['bio' => '   ']);

        $this->assertNull($this->character->fresh()->bio);
    }

    public function test_a_bio_longer_than_the_column_is_refused(): void
    {
        $this->actingAs($this->character->user)
            ->from(route('character.index'))
            ->post(route('character.bio'), ['bio' => str_repeat('a', 65_001)])
            ->assertSessionHasErrors('bio');

        $this->assertNull($this->character->fresh()->bio);
    }

    /** A long one in a multibyte script fits, which is why the column is MEDIUMTEXT. */
    public function test_a_bio_of_multibyte_characters_fits(): void
    {
        $bio = str_repeat('東', 30_000);

        $this->actingAs($this->character->user)
            ->from(route('character.index'))
            ->post(route('character.bio'), ['bio' => $bio])
            ->assertSessionHasNoErrors();

        $this->assertSame($bio, $this->character->fresh()->bio);
    }

    public function test_somebody_elses_sheet_is_not_reachable(): void
    {
        $this->get(route('character.index'))->assertRedirect(route('login'));
    }
}
