<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\DownedCause;
use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\Combat\CombatTag;
use App\Combat\Fight\Action;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\Result;
use App\Combat\FightEnding;
use App\Combat\Fights;
use App\Combat\WeaponClass;
use App\Models\Ammunition;
use App\Models\Character;
use App\Models\Entity;
use App\Models\EntityBook;
use App\Models\Fight;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Npc;
use App\Models\NpcLoot;
use App\Models\WalletEntry;
use App\Models\Weapon;
use App\Stats\InjuryLedger;
use App\Wallet\Reason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * What a fight's ending is worth. COMBAT.md, step 6.
 *
 * The fight's own die is pinned as in `FightsTest` (an aim of 99 against a defence of 0 is Dead-on at a die
 * of 0.0; a body die of 0.5 is the right leg). The ending has a die of its own, an integer one, pinned here
 * to a queue that complains when it runs dry and when some is left.
 */
final class FightEndingTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    /** @var list<int> */
    private array $rolls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3, 'creds' => 1000]));

        $gun = Item::factory()->physical()->create(['name' => 'Pistol', 'stackable' => false]);
        Weapon::create(['item_id' => $gun->id, 'class' => WeaponClass::Pistol, 'accuracy' => 0, 'rate' => 1, 'magazine' => 12, 'calibre' => '9mm']);
        $row = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $gun->id, 'quantity' => 1]);
        $round = Item::factory()->physical()->create(['name' => '9mm FMJ', 'stackable' => true]);
        Ammunition::create(['item_id' => $round->id, 'calibre' => '9mm', 'type' => 'full_metal_jacket', 'damage' => 8, 'damage_profile' => ['penetrating' => 100], 'penetration' => 0]);
        $row->forceFill(['loaded_ammo_id' => $round->id, 'loaded_rounds' => 12])->save();
        $this->character->forceFill(['equipped_ranged_id' => $row->id])->save();
    }

    protected function tearDown(): void
    {
        Assert::assertSame([], $this->rolls, 'Ending dice were left over: the ending rolled fewer than the test expected.');

        parent::tearDown();
    }

    private function npc(array $attributes = []): Npc
    {
        $gun = Item::factory()->physical()->create(['name' => 'Gang pistol', 'stackable' => false]);
        $calibre = 'gang-'.$gun->id;
        Weapon::create(['item_id' => $gun->id, 'class' => WeaponClass::Pistol, 'accuracy' => 0, 'rate' => 1, 'magazine' => 12, 'calibre' => $calibre]);
        $round = Item::factory()->physical()->create(['name' => 'Gang round', 'stackable' => true]);
        Ammunition::create(['item_id' => $round->id, 'calibre' => $calibre, 'type' => 'standard', 'damage' => 5, 'damage_profile' => ['penetrating' => 100], 'penetration' => 0]);

        return Npc::create($attributes + ['slug' => 'thug', 'name' => 'Thug', 'grade' => 2, 'hitpoints' => 5, 'aim' => 99, 'defence' => 0, 'pursuit' => 8, 'ranged_weapon_id' => $gun->id, 'ammo_item_id' => $round->id]);
    }

    /** The ending's die: these values, in order. */
    private function roll(int ...$values): void
    {
        $this->rolls = array_values($values);

        $this->app->bind(FightEnding::class, fn (): FightEnding => FightEnding::fromConfig(function (int $min, int $max): int {
            Assert::assertNotSame([], $this->rolls, 'The ending asked for a roll it was not given.');
            $value = array_shift($this->rolls);
            Assert::assertGreaterThanOrEqual($min, $value);
            Assert::assertLessThanOrEqual($max, $value);

            return $value;
        }));
    }

    /** Open a fight and play one round of it. @param list<float> $dice */
    private function play(Npc $npc, array $dice): Fight
    {
        $fights = new Fights(new FightEngine(CheckRoller::fromConfig()), new QueueDice($dice));
        $this->assertInstanceOf(Fight::class, $fights->open($this->character->fresh(), $npc));

        $this->character = $this->character->fresh();
        $fight = $fights->act($this->character, Action::attack());
        $this->assertInstanceOf(Fight::class, $fight);

        return $fight->fresh();
    }

    /** The player Dead-on for 8 against five hit points: the enemy fires first, then the player kills it. */
    private function win(Npc $npc): Fight
    {
        return $this->play($npc, [0.0, 0.5,  0.0, 0.5]);
    }

    /** The enemy fires first, Dead-on for 5, with three hit points left to the player. */
    private function lose(Npc $npc): Fight
    {
        $this->character->forceFill(['current_hitpoints' => 3])->save();

        return $this->play($npc, [0.0, 0.5]);
    }

    // ---- a win ----------------------------------------------------------------------------

    public function test_a_win_pays_the_grades_band_through_the_wallet(): void
    {
        $this->roll(77);

        $fight = $this->win($this->npc());

        $this->assertSame(Result::BDown, $fight->result);
        $this->assertSame(1077, $this->character->creds, 'the caller\'s own instance is current, for the topbar');
        $this->assertSame(1077, $this->character->fresh()->creds);

        $entry = WalletEntry::query()->sole();
        $this->assertSame(Reason::Fight, $entry->reason);
        $this->assertSame(77, $entry->amount);
        $this->assertSame(1077, $entry->balance);
    }

    public function test_the_fight_log_says_what_was_taken(): void
    {
        $this->roll(77);

        $log = $this->win($this->npc())->snapshot()->log;

        $this->assertSame('Thug goes down.', $log[count($log) - 2]['text']);
        $this->assertSame('You take 77 ¤ off it.', $log[count($log) - 1]['text']);
        $this->assertSame('ok', $log[count($log) - 1]['tone']);
    }

    public function test_the_drops_roll_in_the_order_they_were_authored_and_only_a_success_rolls_a_quantity(): void
    {
        $npc = $this->npc();
        $scrap = Item::factory()->physical()->create(['name' => 'Scrap', 'stackable' => true]);
        $knife = Item::factory()->physical()->create(['name' => 'Knife', 'stackable' => false]);
        $charm = Item::factory()->physical()->create(['name' => 'Charm', 'stackable' => true]);
        NpcLoot::create(['npc_id' => $npc->id, 'item_id' => $scrap->id, 'chance' => 50, 'min_quantity' => 2, 'max_quantity' => 4]);
        NpcLoot::create(['npc_id' => $npc->id, 'item_id' => $knife->id, 'chance' => 50, 'min_quantity' => 1, 'max_quantity' => 1]);
        NpcLoot::create(['npc_id' => $npc->id, 'item_id' => $charm->id, 'chance' => 50, 'min_quantity' => 1, 'max_quantity' => 1]);

        // Pay; scrap hits (50 ≤ 50) for 3; the knife misses (51); the charm hits (1) for 1.
        $this->roll(60, 50, 3, 51, 1, 1);

        $this->win($npc);

        $this->assertSame(3, (int) InventoryItem::query()->where('character_id', $this->character->id)->where('item_id', $scrap->id)->sum('quantity'));
        $this->assertSame(0, InventoryItem::query()->where('character_id', $this->character->id)->where('item_id', $knife->id)->count());
        $this->assertSame(1, (int) InventoryItem::query()->where('character_id', $this->character->id)->where('item_id', $charm->id)->sum('quantity'));
    }

    public function test_a_drop_whose_item_has_gone_costs_no_die_and_drops_nothing(): void
    {
        $npc = $this->npc();
        $gone = Item::factory()->physical()->create(['name' => 'Gone']);
        NpcLoot::create(['npc_id' => $npc->id, 'item_id' => $gone->id, 'chance' => 100, 'min_quantity' => 1, 'max_quantity' => 1]);

        $fights = new Fights(new FightEngine(CheckRoller::fromConfig()), new QueueDice([0.0, 0.5,  0.0, 0.5]));
        $fights->open($this->character->fresh(), $npc);

        // The row cascades off the NPC, but the fight holds its own copy of the list.
        $gone->delete();
        $this->roll(60);

        $fights->act($this->character->fresh(), Action::attack());

        $this->assertSame(1060, $this->character->fresh()->creds);
    }

    public function test_beating_somebodys_person_lowers_that_entitys_opinion_by_grade(): void
    {
        $entity = Entity::query()->interactable()->firstOrFail();
        $this->roll(150);

        $this->win($this->npc(['entity_id' => $entity->id, 'grade' => 3]));

        $book = EntityBook::query()->where('character_id', $this->character->id)->where('entity_id', $entity->id)->sole();
        $this->assertEqualsWithDelta(-0.15, (float) $book->standing, 0.0001, 'three grades of -0.05');
    }

    public function test_an_opponent_nobody_owns_moves_no_standing(): void
    {
        $this->roll(60);

        $this->win($this->npc());

        $this->assertSame(0, EntityBook::query()->count());
    }

    // ---- the player sees it ------------------------------------------------------------------------

    public function test_the_winning_press_flashes_what_it_was_worth(): void
    {
        $this->roll(77);
        $this->app->bind(Fights::class, fn (): Fights => new Fights(new FightEngine(CheckRoller::fromConfig()), new QueueDice([0.0, 0.5,  0.0, 0.5])));
        app(Fights::class)->open($this->character->fresh(), $this->npc());

        $this->actingAs($this->character->user)->post(route('fight.act'), ['action' => 'attack'])
            ->assertRedirect(route('city.index'))
            ->assertSessionHas('status', 'Thug is down. You take 77 ¤ off it.');
    }

    public function test_the_waiting_screen_reads_a_lost_fight_back_with_what_it_cost(): void
    {
        $this->roll(40);
        $this->lose($this->npc());

        $this->actingAs($this->character->user->fresh())->get(route('waiting.show'))
            ->assertOk()
            ->assertSeeText('400 ¤ are gone from what you were carrying.');
    }

    public function test_the_waiting_screen_copes_with_a_tag_it_does_not_know_when_nothing_was_said(): void
    {
        // A cause with tags and no effects falls back to naming the tags; a combat tag must not break that.
        $this->character->forceFill(['waiting_until' => now()->addMinutes(5), 'waiting_cause' => (new DownedCause(reason: 'fight', tags: ['mugged']))->toArray()])->save();

        $this->actingAs($this->character->user->fresh())->get(route('waiting.show'))->assertOk()->assertSeeText('mugged');
    }

    // ---- getting away ------------------------------------------------------------------------

    public function test_getting_away_either_way_pays_and_costs_nothing(): void
    {
        $this->roll(60);
        $fight = $this->win($this->npc()); // opens and finishes a fight to have a row to settle against
        $before = $this->character->fresh()->creds;

        foreach ([Result::AEscaped, Result::BEscaped] as $result) {
            $this->assertSame([], app(FightEnding::class)->settle($this->character->fresh(), $fight, $result));
        }

        $this->assertSame($before, $this->character->fresh()->creds);
    }

    // ---- a loss -----------------------------------------------------------------------------------

    public function test_a_loss_mugs_a_share_of_everything_carried_and_the_floor_reads_it_back(): void
    {
        $this->roll(40);

        $fight = $this->lose($this->npc());

        $this->assertSame(Result::ADown, $fight->result);
        $this->assertSame(600, $this->character->creds, '40% of 1000 gone');
        $this->assertTrue(app(WaitingRoom::class)->isDown($this->character->fresh()));

        $entry = WalletEntry::query()->sole();
        $this->assertSame(Reason::Mugged, $entry->reason);
        $this->assertSame(-400, $entry->amount);

        $cause = DownedCause::fromArray($this->character->fresh()->waiting_cause);
        $this->assertSame('fight', $cause->reason);
        $this->assertSame(['mugged'], $cause->tags);
        $this->assertCount(2, $cause->effects);
        $this->assertStringEndsWith('goes down.', $cause->effects[0], 'the ending first, then what it cost');
        $this->assertSame('400 ¤ are gone from what you were carrying.', $cause->effects[1]);
    }

    public function test_the_share_is_floored_and_an_empty_purse_loses_nothing_and_says_nothing(): void
    {
        $this->character->forceFill(['creds' => 0])->save();
        $this->roll(50);

        $fight = $this->lose($this->npc());

        $this->assertSame(0, WalletEntry::query()->count());
        $effects = DownedCause::fromArray($this->character->fresh()->waiting_cause)->effects;
        $this->assertCount(1, $effects, 'only the ending: nothing was taken, so nothing is said');
        $this->assertSame($effects[0], $fight->snapshot()->log[array_key_last($fight->snapshot()->log)]['text']);
    }

    public function test_a_tag_replaces_the_default_rather_than_adding_to_it(): void
    {
        $this->roll(10);
        $trauma = fn (): float => app(InjuryLedger::class)->carried($this->character->fresh(), InjuryLedger::TRAUMA);
        $max = $this->character->maximumFor('current_hitpoints');

        $this->lose($this->npc(['tags' => ['maim']]));

        $this->assertSame(1000, $this->character->fresh()->creds, 'maim alone does not rob');
        $this->assertSame(0, WalletEntry::query()->count());
        $points = max(1, (int) round($max * 10 / 100));
        $this->assertEqualsWithDelta(-$points, $trauma(), 0.0001, 'a share of the ceiling, held as physical trauma');
        $this->assertSame($max - $points, $this->character->fresh()->maximumFor('current_hitpoints'));
    }

    public function test_both_tags_apply_in_the_vocabularys_order(): void
    {
        $this->roll(30, 10);

        $this->lose($this->npc(['tags' => ['maim', 'mugged']]));

        $this->assertSame(700, $this->character->fresh()->creds);
        $effects = DownedCause::fromArray($this->character->fresh()->waiting_cause)->effects;
        $this->assertCount(3, $effects);
        $this->assertStringContainsString('gone from what you were carrying', $effects[1], 'Mugged is the first case, whatever order the row lists them');
        $this->assertStringContainsString('maimed', $effects[2]);
    }

    public function test_null_tags_are_the_default_and_a_list_is_exactly_what_it_names(): void
    {
        $this->assertSame([CombatTag::Mugged], $this->npc()->combatTags());
        $this->assertSame([CombatTag::Maim], $this->npc(['slug' => 'a', 'tags' => ['maim']])->combatTags());
        $this->assertSame([], $this->npc(['slug' => 'b', 'tags' => []])->combatTags(), 'an empty list is not the default');
        $this->assertSame([CombatTag::Maim], CombatTag::fromStored(['maim', 'a_tag_since_retired']));
    }

    // ---- the terms are the fight's own --------------------------------------------------------------

    public function test_a_fight_keeps_the_terms_it_opened_with_whatever_happens_to_the_opponent(): void
    {
        $entity = Entity::query()->interactable()->firstOrFail();
        $npc = $this->npc(['entity_id' => $entity->id, 'tags' => ['maim']]);
        $fights = new Fights(new FightEngine(CheckRoller::fromConfig()), new QueueDice([0.0, 0.5,  0.0, 0.5]));
        $fight = $fights->open($this->character->fresh(), $npc);

        $this->assertSame($entity->id, $fight->entity_id);
        $this->assertSame(['maim'], $fight->tags);

        $npc->update(['tags' => null, 'entity_id' => null]);
        $npc->delete();
        $this->roll(60);

        $fights->act($this->character->fresh(), Action::attack());

        $this->assertSame(1060, $this->character->fresh()->creds, 'it still paid after the opponent was deleted');
        $this->assertSame(-0.1, (float) EntityBook::query()->where('entity_id', $entity->id)->sole()->standing);
    }

    public function test_a_fight_with_no_loot_stores_none(): void
    {
        $fight = (new Fights(new FightEngine(CheckRoller::fromConfig()), new QueueDice([])))->open($this->character->fresh(), $this->npc());

        $this->assertNull($fight->loot);
        $this->assertNull($fight->tags, 'the default is stored as the default');
    }

    // ---- the numbers ---------------------------------------------------------------------------------

    public function test_every_grade_has_a_pay_band_and_the_bounds_are_sane(): void
    {
        foreach (range(1, 5) as $grade) {
            $band = config("combat.pay.{$grade}");
            $this->assertIsArray($band, "grade {$grade}");
            $this->assertGreaterThan(0, $band['min']);
            $this->assertGreaterThanOrEqual($band['min'], $band['max']);
        }

        foreach (['mugging', 'maim'] as $tag) {
            $this->assertGreaterThan(0, config("combat.{$tag}.min"));
            $this->assertGreaterThanOrEqual(config("combat.{$tag}.min"), config("combat.{$tag}.max"));
            $this->assertLessThanOrEqual(100, config("combat.{$tag}.max"));
        }
    }

    public function test_every_tag_and_reason_has_its_words(): void
    {
        foreach (CombatTag::cases() as $tag) {
            $this->assertNotSame("combat.tags.{$tag->value}.label", $tag->label());
            $this->assertNotSame("combat.tags.{$tag->value}.hint", $tag->hint());
        }

        $this->assertNotSame('wallet.reasons.fight', Reason::Fight->label());
        $this->assertNotSame('wallet.reasons.mugged', Reason::Mugged->label());
    }
}
