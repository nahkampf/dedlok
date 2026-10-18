<?php

namespace Tests\Feature;

use App\Characters\Fatigue;
use App\Models\Character;
use App\Models\CharacterModifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The resource and its one writer. FATIGUE.md is the design.
 */
class FatigueTest extends TestCase
{
    use RefreshDatabase;

    private function character(int $each = 20): Character
    {
        return Character::factory()->create(array_fill_keys(Character::ATTRIBUTES, $each) + ['archetype' => 3]);
    }

    public function test_a_new_character_is_rested(): void
    {
        $this->assertSame(0, $this->character()->fresh()->current_fatigue);
    }

    public function test_the_maximum_is_six_focus_six_meat_and_social(): void
    {
        // (20 x 2 + 20 x 2 + 20 / 3) x 3 = 260 at 20, to the formula's round-up.
        $this->assertSame(260, app(Fatigue::class)->maximum($this->character(20)));
        $this->assertSame(13 * 6 + 13 * 6 + 13, app(Fatigue::class)->maximum($this->character(13)));
        $this->assertSame(100 * 13, app(Fatigue::class)->maximum($this->character(100)));
    }

    public function test_add_and_ease_clamp_and_carry_two_decimals(): void
    {
        $fatigue = app(Fatigue::class);
        $character = $this->character();

        $this->assertSame(0.1, $fatigue->add($character, 0.1));
        $this->assertSame(0.4, $fatigue->add($character, 0.4));
        $this->assertSame(0.5, $character->fresh()->current_fatigue);

        $this->assertSame(259.5, $fatigue->add($character, 9999));
        $this->assertSame(260, $character->fresh()->current_fatigue);

        $this->assertSame(260, $fatigue->ease($character, 9999));
        $this->assertSame(0, $character->fresh()->current_fatigue);
    }

    public function test_a_negative_amount_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(Fatigue::class)->add($this->character(), -1);
    }

    public function test_the_fatigue_effects_do_not_feed_the_maximum(): void
    {
        Config::set('effects.fatigue_exhausted', [
            'stacking' => 'highest',
            'modifiers' => [
                ['op' => 'add', 'amount' => -10, 'target' => ['id' => 'focus']],
                ['op' => 'add', 'amount' => -10, 'target' => ['id' => 'meat']],
            ],
        ]);

        $character = $this->character();
        CharacterModifier::query()->create(['character_id' => $character->id, 'definition_key' => 'fatigue_exhausted']);

        // The attributes really are lower...
        $this->assertSame(10.0, $character->stat('focus')->value);
        // ...and the maximum does not move.
        $this->assertSame(260, app(Fatigue::class)->maximum($character));
    }

    public function test_any_other_source_still_moves_the_maximum(): void
    {
        Config::set('effects.test_boost', [
            'stacking' => 'highest',
            'modifiers' => [['op' => 'add', 'amount' => 10, 'target' => ['id' => 'focus']]],
        ]);

        $character = $this->character();
        CharacterModifier::query()->create(['character_id' => $character->id, 'definition_key' => 'test_boost']);

        $this->assertSame(260 + 60, app(Fatigue::class)->maximum($character));
    }

    public function test_a_dropping_maximum_brings_fatigue_down_and_never_up(): void
    {
        $character = $this->character();
        app(Fatigue::class)->add($character, 250);

        $character->forceFill(['focus' => 10])->save();   // 260 - 60 = 200

        $this->assertSame(200, $character->fresh()->current_fatigue);

        $character->forceFill(['focus' => 20])->save();   // back to 260; fatigue stays
        $this->assertSame(200, $character->fresh()->current_fatigue);
    }

    public function test_fatigue_taken_scales_what_is_added_but_not_what_is_eased(): void
    {
        Config::set('effects.test_calm', [
            'stacking' => 'highest',
            'modifiers' => [['op' => 'percentage_of_base', 'amount' => -50, 'target' => ['id' => 'fatigue_taken']]],
        ]);

        $character = $this->character();
        CharacterModifier::query()->create(['character_id' => $character->id, 'definition_key' => 'test_calm']);

        $this->assertSame(5, app(Fatigue::class)->add($character, 10));
        $this->assertSame(5, app(Fatigue::class)->ease($character, 5));
    }

    private function keys(Character $character): array
    {
        return CharacterModifier::query()->where('character_id', $character->id)->orderBy('definition_key')->pluck('definition_key')->all();
    }

    public function test_the_breakpoints_follow_the_figure_both_ways(): void
    {
        $fatigue = app(Fatigue::class);
        $character = $this->character();   // 260: Tired at 195, Drained at 234

        $fatigue->add($character, 194.99);
        $this->assertSame([], $this->keys($character));

        $fatigue->add($character, 0.01);
        $this->assertSame(['fatigue_tired'], $this->keys($character));
        $this->assertSame(17.0, $character->stat('focus')->value);

        $fatigue->add($character, 38.99);   // 233.99
        $this->assertSame(['fatigue_tired'], $this->keys($character));

        $fatigue->add($character, 0.01);    // 234: Drained REPLACES Tired
        $this->assertSame(['fatigue_drained'], $this->keys($character));
        $this->assertSame([13.0, 15.0, 15.0], [$character->stat('focus')->value, $character->stat('intellect')->value, $character->stat('reflex')->value]);

        $fatigue->ease($character, 0.01);
        $this->assertSame(['fatigue_tired'], $this->keys($character));

        $fatigue->ease($character, 9999);
        $this->assertSame([], $this->keys($character));
    }

    public function test_the_breakpoints_do_not_move_the_maximum_they_are_judged_against(): void
    {
        $fatigue = app(Fatigue::class);
        $character = $this->character();

        $fatigue->add($character, 240);   // Drained: -7 focus, but the ceiling stays 260

        $this->assertSame(260, $fatigue->maximum($character));
        $this->assertSame(240, $character->fresh()->current_fatigue);
    }

    public function test_exhausted_lasts_until_under_half_and_is_only_granted_by_a_collapse(): void
    {
        $fatigue = app(Fatigue::class);
        $character = $this->character();

        $fatigue->add($character, 200);
        $this->assertNotContains('fatigue_exhausted', $this->keys($character));

        $fatigue->exhaust($character);
        $fatigue->exhaust($character);   // idempotent
        $this->assertSame(1, CharacterModifier::query()->where('definition_key', 'fatigue_exhausted')->count());
        $this->assertSame(7.0, $character->stat('focus')->value);   // 20, less Exhausted 10 and Tired 3

        $fatigue->ease($character, 69);   // 131: still half or more
        $this->assertContains('fatigue_exhausted', $this->keys($character));

        $fatigue->ease($character, 2);    // 129 < 130
        $this->assertNotContains('fatigue_exhausted', $this->keys($character));
    }

    public function test_the_clock_corrects_a_status_when_the_maximum_moves(): void
    {
        $fatigue = app(Fatigue::class);
        $character = $this->character();
        $fatigue->add($character, 200);   // Tired
        $this->assertSame(['fatigue_tired'], $this->keys($character));

        $character->forceFill(['focus' => 100, 'meat' => 100])->save();   // 1300: 200 is nowhere near Tired

        $this->artisan('characters:regenerate')->assertSuccessful();

        $this->assertSame([], $this->keys($character));
    }

    public function test_the_config_and_the_writer_name_the_same_effects(): void
    {
        $this->assertEqualsCanonicalizing(
            [Fatigue::TIRED, Fatigue::DRAINED, Fatigue::EXHAUSTED],
            config('fatigue.effects'),
        );
    }

    public function test_the_reading_turns_yellow_at_tired_and_ember_at_drained(): void
    {
        $character = $this->character();
        $fatigue = app(Fatigue::class);

        $this->assertSame('var(--ok)', $fatigue->reading($character)['fill']);

        $character->forceFill(['current_fatigue' => 195])->save();
        $this->assertSame('var(--warn)', $fatigue->reading($character->fresh())['fill']);

        $character->forceFill(['current_fatigue' => 234])->save();
        $reading = $fatigue->reading($character->fresh());
        $this->assertSame('var(--crit)', $reading['fill']);
        $this->assertSame(0.9, $reading['ratio']);
    }

    public function test_the_sheet_and_the_bar_show_fatigue_with_its_markers(): void
    {
        $character = $this->character();

        $this->actingAs($character->user)->get(route('character.index'))
            ->assertOk()
            ->assertSee('Fatigue')
            ->assertSee('meter__mark', false)
            ->assertSee('--x:0.75', false)
            ->assertSee('--x:0.9', false);
    }
}
