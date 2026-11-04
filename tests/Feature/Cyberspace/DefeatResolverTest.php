<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Cyberspace\DefeatResolver;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\Craft;
use App\Models\DeckFitting;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Program;
use App\Programs\ProgramKind;
use App\Stats\InjuryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * What a piece of ICE actually does to somebody it beats.
 *
 * Until this class existed, a defeat's tags were words on a screen and losing
 * to ICE XII cost exactly what losing to ICE I did. Every roll here is pinned.
 */
final class DefeatResolverTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private function character(): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));
    }

    private function resolver(int $die): DefeatResolver
    {
        return DefeatResolver::fromConfig(static fn (int $min, int $max): int => min($max, max($min, $die)));
    }

    /**
     * A test-only piece of ICE, at a level well past the seeded catalogue's
     * 1-12 so it never collides with the unique constraint on `level`.
     */
    private function iceWithTags(array $tags): Ice
    {
        return Ice::query()->create([
            'level' => 90,
            'name' => 'Test ICE',
            'integrity' => 410,
            'attack' => 20,
            'skill' => 95,
            'detection' => 40,
            'grade_min' => 8,
            'grade_max' => 9,
            'defeat_tags' => array_map(
                static fn (string $tag): array => ['tag' => $tag, 'level' => 3],
                $tags,
            ),
        ]);
    }

    // ---- flatline ------------------------------------------------------

    /** A percentage of MAXIMUM SI, taken as a permanent injury. */
    public function test_flatline_deepens_the_neural_damage_injury(): void
    {
        $character = $this->character();
        $max = $character->maximumFor('current_synaptic_integrity');
        $ice = $this->iceWithTags(['flatline']);

        $this->resolver(14)->apply($character, $ice); // 14% of max, floor of the level-3 band

        $expected = (int) round($max * 14 / 100);

        $this->assertSame(
            -$expected,
            (int) app(InjuryLedger::class)->carried($character->fresh(), InjuryLedger::NEURAL),
        );

        // The tag is what being flatlined MEANS, so it is what gets counted.
        $this->assertSame(1, (new Achievements)->progressFor($character, Metric::Flatlines));
    }

    /** Current SI clamps to the new, lower ceiling immediately — not on some later save. */
    public function test_flatline_clamps_current_si_down_at_once(): void
    {
        $character = $this->character();
        $max = $character->maximumFor('current_synaptic_integrity');
        $character->forceFill(['current_synaptic_integrity' => $max])->save();

        $this->resolver(19)->apply($character, $this->iceWithTags(['flatline'])); // top of the level-3 band

        $newMax = $character->fresh()->maximumFor('current_synaptic_integrity');

        $this->assertLessThan($max, $newMax);
        $this->assertSame($newMax, $character->fresh()->current_synaptic_integrity);
    }

    // ---- neural shock ----------------------------------------------------

    /** Current hit points, never a maximum — that is Flatline's job. */
    public function test_neural_shock_takes_current_hit_points_only(): void
    {
        $character = $this->character();
        $maxHp = $character->maximumFor('current_hitpoints');
        $maxSi = $character->maximumFor('current_synaptic_integrity');
        $character->forceFill(['current_hitpoints' => $maxHp])->save();

        $this->resolver(28)->apply($character, $this->iceWithTags(['neural_shock']));

        $expected = (int) round($maxHp * 28 / 100);

        $this->assertSame($maxHp - $expected, $character->fresh()->current_hitpoints);
        $this->assertSame($maxSi, $character->fresh()->maximumFor('current_synaptic_integrity'), 'the ceiling is untouched');
    }

    /**
     * AND IT GOES THROUGH THE DOOR, so mitigation reaches it.
     *
     * It called `Character::takeDamage()` directly once — the dull half
     * underneath `DamageResolver`, which skips every rule the door exists for.
     * Nothing could resist it, and the Crim's own "Hard knocks" could not
     * touch it although its target set (`damage` + `hitpoints`) covers exactly
     * this stat. The percentage is still rolled off the maximum; what the
     * resolver decides is how much of it lands.
     */
    public function test_neural_shock_is_mitigated_like_any_other_hit(): void
    {
        $character = $this->character();
        $maxHp = $character->maximumFor('current_hitpoints');
        $character->forceFill(['current_hitpoints' => $maxHp])->save();

        Config::set('effects.padded', [
            'label' => 'Padded',
            'stacking' => 'unique',
            'retired' => false,
            'modifiers' => [[
                'op' => 'percentage_of_base',
                'amount' => -50,
                'target' => ['all_of' => ['damage', 'hitpoints']],
            ]],
        ]);

        CharacterModifier::query()->create([
            'character_id' => $character->id,
            'definition_key' => 'padded',
        ]);

        $this->resolver(28)->apply($character->fresh(), $this->iceWithTags(['neural_shock']));

        $rolled = (int) round($maxHp * 28 / 100);

        $this->assertSame(
            $maxHp - $rolled * 0.5,
            $character->fresh()->current_hitpoints,
            'half of it was soaked',
        );
    }

    /**
     * THE ORIGIN IS ICE, which is what dealt it — so a resistance written
     * against ICE covers the body half of what ICE does as well as the mind
     * half, and the two axes work the way the damage design says they do.
     */
    public function test_a_resistance_to_ice_covers_neural_shock(): void
    {
        $character = $this->character();
        $maxHp = $character->maximumFor('current_hitpoints');
        $character->forceFill(['current_hitpoints' => $maxHp])->save();

        Config::set('effects.warded', [
            'label' => 'Warded',
            'stacking' => 'unique',
            'retired' => false,
            'modifiers' => [[
                'op' => 'multiplier',
                'amount' => 0,
                'target' => ['all_of' => ['damage', 'ice']],
            ]],
        ]);

        CharacterModifier::query()->create([
            'character_id' => $character->id,
            'definition_key' => 'warded',
        ]);

        $this->resolver(28)->apply($character->fresh(), $this->iceWithTags(['neural_shock']));

        $this->assertSame($maxHp, $character->fresh()->current_hitpoints, 'immune, so nothing landed');
    }

    // ---- surge -------------------------------------------------------

    /**
     * A won roll destroys a fitted module outright — deleted, not merely
     * unfitted. Level 3 is 55% for THREE modules — one roll per module, not
     * one roll for the tag — so of the four the fixture fits, exactly three
     * come off when every roll wins.
     */
    public function test_surge_destroys_fitted_modules_on_a_won_roll(): void
    {
        $character = $this->character();
        $this->equipReadyDeck($character);

        $before = DeckFitting::query()->where('deck_id', $character->equipped_deck_id)->count();
        $this->assertSame(4, $before);

        // Level 3 is 55% chance per module, three attempts; a die of 1 always wins.
        $this->resolver(1)->apply($character->fresh(), $this->iceWithTags(['surge']));

        $after = DeckFitting::query()->where('deck_id', $character->fresh()->equipped_deck_id)->count();

        $this->assertSame(1, $after, 'three of the four rolls won, and the tag only attempts three');
    }

    /** A lost roll destroys nothing — a miss does nothing at all, same as the ICE's own attack. */
    public function test_surge_destroys_nothing_on_a_lost_roll(): void
    {
        $character = $this->character();
        $this->equipReadyDeck($character);
        $before = DeckFitting::query()->where('deck_id', $character->equipped_deck_id)->count();

        $this->resolver(101)->apply($character->fresh(), $this->iceWithTags(['surge']));

        $this->assertSame($before, DeckFitting::query()->where('deck_id', $character->fresh()->equipped_deck_id)->count());
    }

    /** No deck at all is an ordinary answer, not a crash. */
    public function test_surge_does_nothing_with_no_deck_equipped(): void
    {
        $character = $this->character();

        $result = $this->resolver(1)->apply($character, $this->iceWithTags(['surge']));

        $this->assertSame([], $result);
    }

    // ---- erase -------------------------------------------------------

    /** Level 1 takes exactly one item from deck storage, at random. */
    public function test_erase_level_one_takes_exactly_one_item(): void
    {
        $character = $this->character();
        $this->putDataItems($character, 5);

        $ice = Ice::query()->create([
            'level' => 91, 'name' => 'Test ICE', 'integrity' => 178, 'attack' => 8, 'skill' => 45, 'detection' => 19,
            'grade_min' => 6, 'grade_max' => 7,
            'defeat_tags' => [['tag' => 'erase', 'level' => 1]],
        ]);

        $this->resolver(1)->apply($character, $ice);

        $this->assertSame(4, $character->inventoryIn(Item::CONTAINER_DATA)->count());
    }

    /** Level 3's `share: 100` wipes the deck: every program and every file. */
    public function test_erase_level_three_wipes_the_deck(): void
    {
        $character = $this->character();
        $this->putDataItems($character, 6);

        $this->resolver(1)->apply($character, $this->iceWithTags(['erase']));

        $this->assertSame(0, $character->inventoryIn(Item::CONTAINER_DATA)->count());
    }

    /** A running program is stopped before it is deleted, not blown through. */
    public function test_erase_stops_a_running_program_before_deleting_it(): void
    {
        $character = $this->character();
        $this->equipReadyDeck($character);

        $item = Item::factory()->create(['container' => 'data', 'stackable' => true]);
        Program::query()->create([
            'item_id' => $item->id, 'kind' => ProgramKind::Daemon->value,
            'qflops_cost' => 1, 'memory_cost' => 1, 'storage_size' => 1, 'background_process' => true,
        ]);
        $row = InventoryItem::query()->create([
            'character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 1,
            'running_on_deck_id' => $character->fresh()->equipped_deck_id, 'started_at' => now(),
        ]);

        $this->resolver(1)->apply($character->fresh(), $this->iceWithTags(['erase']));

        $this->assertNull(InventoryItem::find($row->id));
    }

    /**
     * docs/CRAFTING.md: a design is destructible by ICE, and Erase already is that ICE. A design and a component are data on the deck like
     * any other, so a wipe takes them with the running Script, while a build under way (its components are spent) goes on.
     */
    public function test_erase_takes_designs_components_and_a_running_script_but_not_a_build_under_way(): void
    {
        $character = $this->character();
        $this->equipReadyDeck($character);
        $character = $character->fresh();

        $design = InventoryItem::query()->create(['character_id' => $character->id, 'item_id' => Item::query()->where('slug', 'dummy-temporary-program-design-original')->value('id'), 'quantity' => 1]);
        $dump = InventoryItem::query()->create(['character_id' => $character->id, 'item_id' => Item::query()->where('slug', 'memory-dump')->value('id'), 'quantity' => 1, 'extracted_file' => ['label' => 'x', 'size_qb' => 0.1, 'encryption_skill_id' => null, 'value_sealed' => 1, 'value_broken' => 2, 'grade' => 1]]);
        $script = InventoryItem::query()->create(['character_id' => $character->id, 'item_id' => Item::query()->where('slug', 'dummy-temporary-program')->value('id'), 'quantity' => 1, 'running_on_deck_id' => $character->equipped_deck_id, 'started_at' => now()]);
        $craft = Craft::query()->create(['character_id' => $character->id, 'product_item_id' => $script->item_id, 'name' => 'x', 'required_seconds' => 600, 'settled_at' => now()]);

        $this->resolver(1)->apply($character->fresh(), $this->iceWithTags(['erase']));

        $this->assertNull(InventoryItem::find($design->id), 'the design is gone');
        $this->assertNull(InventoryItem::find($dump->id), 'and so is a component');
        $this->assertNull(InventoryItem::find($script->id), 'a running Script is destroyed, not put back');
        $this->assertNotNull(Craft::find($craft->id), 'a build under way was paid for already');
    }

    /** What a wipe destroyed comes back as one Damage report, summed by name. */
    public function test_erase_sends_a_damage_report_listing_what_was_lost(): void
    {
        $character = $this->character();
        $this->putDataItems($character, 3);

        $this->resolver(1)->apply($character, $this->iceWithTags(['erase']));

        $message = Notification::query()->where('user_id', $character->user_id)->latest('id')->firstOrFail();
        $this->assertSame('Damage report', $message->title);
        $this->assertCount(3, $message->details['lost']);
        $this->assertStringContainsString(' × ', $message->details['lost'][0]);
    }

    /** Nothing in deck storage is an ordinary answer. */
    public function test_erase_does_nothing_with_an_empty_deck(): void
    {
        $character = $this->character();

        $this->assertSame([], $this->resolver(1)->apply($character, $this->iceWithTags(['erase'])));
    }

    private function putDataItems(Character $character, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $item = Item::factory()->create(['container' => 'data', 'stackable' => false]);
            InventoryItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 1]);
        }
    }

    // ---- skim -------------------------------------------------------

    /** Reaches what was CARRIED, and only what was carried. */
    public function test_skim_takes_a_percentage_of_carried_creds(): void
    {
        $character = $this->character();
        $character->forceFill(['creds' => 1000])->save();

        $this->resolver(1)->apply($character, $this->iceWithTags(['skim'])); // level 3 is 60%

        $this->assertSame(400, $character->fresh()->creds);
    }

    public function test_skim_does_nothing_when_carrying_nothing(): void
    {
        $character = $this->character();
        $character->forceFill(['creds' => 0])->save();

        $this->assertSame([], $this->resolver(1)->apply($character, $this->iceWithTags(['skim'])));
    }

    // ---- everything at once -------------------------------------------------

    /** A grade 9's ICE is terrifying for carrying several tags, not one huge number. */
    public function test_several_tags_on_one_piece_all_apply(): void
    {
        $character = $this->character();
        $this->equipReadyDeck($character);
        $character->forceFill(['creds' => 1000])->save();

        $ice = $this->iceWithTags(['flatline', 'neural_shock', 'skim']);

        $lines = $this->resolver(10)->apply($character->fresh(), $ice);

        $this->assertCount(3, $lines);
        $this->assertLessThan(1000, $character->fresh()->creds);
    }
}
