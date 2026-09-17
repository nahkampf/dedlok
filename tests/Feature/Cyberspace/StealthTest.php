<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Stats\Sheet;
use App\Stats\SkillStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * How hard somebody is to notice, and the three things that move it.
 *
 * A pool everybody has a little of, a skill that adds to it flat, and software
 * that is worth what its operator is.
 */
final class StealthTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private function character(): Character
    {
        SkillStats::forget();
        Sheet::forgetSkills();

        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));
    }

    private function obfuscation(): Skill
    {
        SkillStats::forget();
        Sheet::forgetSkills();

        $skill = Skill::factory()->governedBy('memory', 'focus')->create([
            'slug' => 'obfuscation',
            'title' => 'Obfuscation',
            'category' => 'Systems',
            'rank' => 4,
            'modifiers' => array_map(
                static fn (int $level): array => [
                    'op' => 'add',
                    'level' => $level,
                    'amount' => 2,
                    'target' => ['id' => 'stealth'],
                ],
                range(1, 5),
            ),
        ]);

        SkillStats::forget();
        Sheet::forgetSkills();

        return $skill;
    }

    /** Running the named program on the equipped deck, which is what "active" means. */
    private function runProgram(Character $character, string $slug): void
    {
        InventoryItem::query()->create([
            'character_id' => $character->id,
            'item_id' => Item::query()->where('slug', $slug)->sole()->id,
            'quantity' => 1,
            'running_on_deck_id' => $character->equipped_deck_id,
            'started_at' => now(),
        ]);
    }

    /** EVERYBODY HAS A LITTLE: four attributes of 20 over 8.5. */
    public function test_the_pool_gives_everybody_a_floor_to_build_on(): void
    {
        $this->assertSame(9.4, $this->character()->stat('stealth')->value);
    }

    public function test_obfuscation_adds_two_a_level(): void
    {
        $character = $this->character();
        app(SkillInjector::class)->grant($character, $this->obfuscation(), 3);

        $this->assertSame(15.4, $character->fresh()->stat('stealth')->value, '9.4 and six');
    }

    // ---- the software ------------------------------------------------------

    /**
     * FLAT AND UNCONDITIONAL, and worth proportionally most to somebody who has
     * trained nothing — which is what a Tier One tool should be.
     */
    public function test_piggyback_is_worth_two_to_anybody(): void
    {
        $character = $this->character();
        $this->equipReadyDeck($character);
        $this->runProgram($character->fresh(), 'evasion-piggyback');

        $this->assertSame(11.4, $character->fresh()->stat('stealth')->value);
    }

    /**
     * WORTH WHAT ITS OPERATOR IS, and nothing at all untrained. The Tier Two
     * tool is not a better version of the Tier One one, it is a different
     * bargain.
     */
    public function test_the_injector_does_nothing_for_somebody_untrained(): void
    {
        $character = $this->character();
        $this->equipReadyDeck($character);
        $this->runProgram($character->fresh(), 'evasion-payloadinjector');

        $this->assertSame(9.4, $character->fresh()->stat('stealth')->value);
    }

    public function test_the_injector_pays_four_per_cent_a_level(): void
    {
        $character = $this->character();
        $this->equipReadyDeck($character);
        app(SkillInjector::class)->grant($character, $this->obfuscation(), 3);
        $this->runProgram($character->fresh(), 'evasion-payloadinjector');

        /*
         * ROUNDING HAPPENS ONCE, AT THE END, which is why this is 17.3 and not
         * 17.2. The pool's base is 80/8.5 = 9.41176…, not the 9.4 the sheet
         * displays — so the percentage is taken over 15.41176 and the single
         * rounding lands above the midpoint. Rounding the base first and then
         * modifying it would break the commutativity the whole resolver design
         * rests on; see MECHANICS.md.
         */
        $this->assertSame(17.3, $character->fresh()->stat('stealth')->value);
    }

    /**
     * THE TWO COMPOSE RATHER THAN COMPETING. Percentages apply to base plus
     * flat and are summed before being applied once, so the flat two points go
     * in first and the percentage is taken over everything.
     */
    public function test_both_programs_stack_the_way_the_resolver_orders_them(): void
    {
        $character = $this->character();
        $this->equipReadyDeck($character);
        app(SkillInjector::class)->grant($character, $this->obfuscation(), 5);

        $this->runProgram($character->fresh(), 'evasion-piggyback');
        $this->runProgram($character->fresh(), 'evasion-payloadinjector');

        // 9.4 + 10 (skill) + 2 (piggyback) = 21.4, then 20% over the lot.
        $this->assertSame(25.7, $character->fresh()->stat('stealth')->value);
    }

    /** Software that is not running is a file, and a file hides nobody. */
    public function test_an_unloaded_program_is_worth_nothing(): void
    {
        $character = $this->character();
        $this->equipReadyDeck($character);

        InventoryItem::query()->create([
            'character_id' => $character->id,
            'item_id' => Item::query()->where('slug', 'evasion-piggyback')->sole()->id,
            'quantity' => 1,
        ]);

        $this->assertSame(9.4, $character->fresh()->stat('stealth')->value);
    }
}
