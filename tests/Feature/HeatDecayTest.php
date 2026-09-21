<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\HeatDecay;
use App\Models\Character;
use App\Models\CharacterModifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Heat comes off on its own, slowly.
 *
 * The other half of heat being a number to MANAGE: it goes up when you are
 * loud and comes down when you are not, and neither half is fast. Half a point
 * a night means a completed trace is two nights to shake and a burn is four.
 *
 * The rate is a STAT, which is the part worth pinning — nothing raises it
 * today, and the whole reason it is resolved per character is that the first
 * skill to touch it should need no code here.
 */
final class HeatDecayTest extends TestCase
{
    use RefreshDatabase;

    private function character(float $heat): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3, 'heat' => $heat],
        ));
    }

    private function decay(): HeatDecay
    {
        return new HeatDecay;
    }

    public function test_half_a_point_comes_off_a_night(): void
    {
        $character = $this->character(3.0);

        $this->decay()->tonight();

        $this->assertSame(2.5, (float) $character->fresh()->heat);
    }

    /** Two nights to shake a completed trace, which is the design's own figure. */
    public function test_a_trace_takes_two_nights_to_shake(): void
    {
        $character = $this->character(1.0);

        $this->decay()->tonight();
        $this->assertSame(0.5, (float) $character->fresh()->heat);

        $this->decay()->tonight();
        $this->assertSame(0.0, (float) $character->fresh()->heat);
    }

    /** It floors at nothing rather than going negative. */
    public function test_it_stops_at_nothing(): void
    {
        $character = $this->character(0.3);

        $this->decay()->tonight();

        $this->assertSame(0.0, (float) $character->fresh()->heat);

        // And a second night touches nobody.
        $this->assertSame([], $this->decay()->tonight());
    }

    public function test_somebody_carrying_nothing_is_left_alone(): void
    {
        $this->character(0.0);

        $this->assertSame([], $this->decay()->tonight());
    }

    /**
     * THE RATE IS WHERE THE SKILLS GO. Nothing in the catalogue raises it, so
     * what is proved here is the CHANNEL rather than a piece of content: a
     * modifier on the stat is all a fixer's contacts, a clean identity or a
     * scrubbed record will ever have to be, and nothing in the decay learns
     * they exist.
     */
    public function test_a_modifier_on_the_stat_speeds_it_up(): void
    {
        $character = $this->character(3.0);

        $this->assertSame(0.5, $this->decay()->rateFor($character));

        Config::set('effects.contacts', [
            'label' => 'Contacts',
            'stacking' => 'sum',
            'modifiers' => [
                ['op' => 'add', 'amount' => 0.7, 'target' => ['id' => 'heat_decay']],
            ],
        ]);

        CharacterModifier::query()->create([
            'character_id' => $character->id,
            'definition_key' => 'contacts',
        ]);

        $this->assertSame(1.2, $this->decay()->rateFor($character->fresh()));

        $this->decay()->tonight();

        $this->assertSame(1.8, (float) $character->fresh()->heat);
    }

    // ---- the command -------------------------------------------------------

    public function test_the_command_cools_and_reports(): void
    {
        $this->character(2.0);

        $this->artisan('characters:cool')
            ->expectsOutputToContain('1 character(s) cooled off.')
            ->assertSuccessful();

        $this->assertSame(1.5, (float) Character::query()->sole()->heat);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->character(2.0);

        $this->artisan('characters:cool --dry-run')
            ->expectsOutputToContain('Nothing was written.')
            ->assertSuccessful();

        $this->assertSame(2.0, (float) Character::query()->sole()->heat);
    }

    public function test_it_says_nothing_when_nobody_is_carrying_any(): void
    {
        $this->character(0.0);

        $this->artisan('characters:cool --quiet-when-idle')
            ->doesntExpectOutput()
            ->assertSuccessful();
    }
}
