<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Checks\CheckRoller;
use App\Combat\Fight\Action;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\Result;
use App\Combat\Fight\Side;
use App\Combat\FightRefusal;
use App\Combat\Fights;
use App\Combat\WeaponClass;
use App\Drugs\DoseRefusal;
use App\Drugs\DrugTaker;
use App\Drugs\Family;
use App\Items\ConsumableEditor;
use App\Models\Ammunition;
use App\Models\Character;
use App\Models\Dose;
use App\Models\Drug;
use App\Models\Fight;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Npc;
use App\Models\User;
use App\Models\Weapon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * Drugs that are used only in a fight, from the belt, as the round's action, and that tick on ROUNDS.
 *
 * Dice: an enemy aim of 99 against defence 0 is Dead-on at 0.0; 0.5 is the body slot. Every round the enemy fires first
 * (two dice) and the player's action then spends none unless it is an attack.
 */
final class FightDrugTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->character->forceFill(['current_hitpoints' => $this->character->maximumFor('current_hitpoints') - 20])->save();
    }

    /** A weak enemy: one point a hit, so the rounds change the player's hit points by a known 1. */
    private function npc(int $hp = 40): Npc
    {
        $gun = Item::factory()->physical()->create(['name' => 'Gang pistol', 'stackable' => false]);
        Weapon::create(['item_id' => $gun->id, 'class' => WeaponClass::Pistol, 'accuracy' => 0, 'rate' => 1, 'magazine' => 12, 'calibre' => 'gang']);
        $round = Item::factory()->physical()->create(['name' => 'Gang round', 'stackable' => true]);
        Ammunition::create(['item_id' => $round->id, 'calibre' => 'gang', 'type' => 'standard', 'damage' => 1, 'damage_profile' => ['penetrating' => 100], 'penetration' => 0]);

        return Npc::create(['slug' => 'thug', 'name' => 'Thug', 'grade' => 1, 'hitpoints' => $hp, 'aim' => 99, 'defence' => 0, 'pursuit' => 8, 'ranged_weapon_id' => $gun->id, 'ammo_item_id' => $round->id]);
    }

    /** A Fights whose die yields `$dice` in order. */
    private function with(array $dice): Fights
    {
        return new Fights(new FightEngine(CheckRoller::fromConfig()), new QueueDice($dice));
    }

    /** The enemy's turn, as a MISS (a roll no chance reaches), so the rounds change nothing but what the doses do. */
    private function enemyShot(int $times = 1): array
    {
        return array_merge(...array_fill(0, $times, [0.999, 0.5]));
    }

    private function open(?Npc $npc = null): Fight
    {
        $fight = $this->with([])->open($this->character, $npc ?? $this->npc());
        $this->assertInstanceOf(Fight::class, $fight);

        return $fight;
    }

    /**
     * A Medical fight-only drug on the belt: `$turns` rounds, `$perTurn` hit points each, optionally a heal at once.
     *
     * @param  list<array<string, mixed>>  $modifiers
     */
    private function belted(int $quantity = 3, int $turns = 3, int $perTurn = 4, ?int $instant = null, array $modifiers = [], ?int $lethalAt = null): InventoryItem
    {
        $item = Item::factory()->create(['container' => 'physical', 'name' => 'Combat stim', 'stackable' => true, 'modifiers' => $modifiers ?: null]);
        Drug::query()->create([
            'item_id' => $item->id, 'family' => Family::Medical, 'fight_only' => true, 'duration_minutes' => 0, 'duration_turns' => $turns,
            'turn_hitpoints' => $perTurn, 'addictive' => 0, 'tolerance_gain' => 0, 'lethal_at' => $lethalAt,
        ]);

        if ($instant !== null) {
            ConsumableEditor::apply($item, [['kind' => 'heal_hitpoints', 'amount' => $instant]]);
        }

        return InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => $quantity, 'belt_slot' => 1]);
    }

    private function hp(): int
    {
        return (int) $this->character->fresh()->current_hitpoints;
    }

    // ---- using one ----------------------------------------------------------------------

    public function test_using_one_spends_the_turn_takes_the_dose_and_heals_at_once(): void
    {
        $row = $this->belted(instant: 7);
        $this->open();
        $before = $this->hp();

        $fight = $this->with($this->enemyShot())->act($this->character->fresh(), Action::use($row->id));

        // The enemy misses; the instant heal lands and the fight goes on.
        $this->assertInstanceOf(Fight::class, $fight);
        $this->assertTrue($fight->isLive());
        $this->assertSame($before + 7, $this->hp());
        $this->assertSame(2, $row->fresh()->quantity);

        $dose = Dose::query()->where('character_id', $this->character->id)->sole();
        $this->assertSame(3, $dose->turns_left);

        $texts = array_column($fight->snapshot()->log, 'text');
        $this->assertTrue(collect($texts)->contains(fn (string $t): bool => str_contains($t, 'uses Combat stim') && str_contains($t, 'You recover 7 hit points.') && str_contains($t, 'lasts 3 rounds')));
    }

    public function test_it_ticks_each_round_and_is_gone_after_its_rounds(): void
    {
        $row = $this->belted(turns: 3, perTurn: 4);
        $this->open();
        $this->with($this->enemyShot())->act($this->character->fresh(), Action::use($row->id));
        $hp = $this->hp();

        $seen = [];

        foreach ([3, 2, 1] as $left) {
            $this->assertSame($left, Dose::query()->where('character_id', $this->character->id)->sole()->turns_left);
            $this->with($this->enemyShot())->act($this->character->fresh(), Action::brace());
            $seen[] = $this->hp() - $hp;
            $hp = $this->hp();
        }

        // Each round: +4 from the dose at the start.
        $this->assertSame([4, 4, 4], $seen);
        $this->assertSame(0, Dose::query()->where('character_id', $this->character->id)->count(), 'spent after three rounds');

        // And a fourth round has nothing to tick.
        $this->with($this->enemyShot())->act($this->character->fresh(), Action::brace());
        $this->assertSame($hp, $this->hp());
    }

    public function test_tolerance_weakens_the_per_round_heal_through_potency(): void
    {
        $row = $this->belted(turns: 2, perTurn: 10);
        $this->open();
        $this->with($this->enemyShot())->act($this->character->fresh(), Action::use($row->id));
        Dose::query()->where('character_id', $this->character->id)->update(['potency' => 0.5]);
        $hp = $this->hp();

        $this->with($this->enemyShot())->act($this->character->fresh(), Action::brace());

        $this->assertSame($hp + 5, $this->hp());
    }

    public function test_its_stat_effects_reach_the_fight_numbers_for_its_rounds_only(): void
    {
        $row = $this->belted(turns: 1, perTurn: 0, modifiers: [['op' => 'add', 'amount' => 5, 'target' => ['id' => 'defence']]]);
        $fight = $this->open();
        $base = $fight->snapshot()->a->defence;

        $this->with($this->enemyShot())->act($this->character->fresh(), Action::use($row->id));
        // The next round's snapshot is rebuilt with the dose in effect.
        $during = $this->with($this->enemyShot())->act($this->character->fresh(), Action::brace());
        $this->assertSame($base + 5, $during->snapshot()->a->defence);

        // It lasted one round: the one after is back to the base.
        $after = $this->with($this->enemyShot())->act($this->character->fresh(), Action::brace());
        $this->assertSame($base, $after->snapshot()->a->defence);
    }

    public function test_a_negative_tick_is_the_drugs_price(): void
    {
        $row = $this->belted(turns: 2, perTurn: -3);
        $this->open();
        $this->with($this->enemyShot())->act($this->character->fresh(), Action::use($row->id));
        $hp = $this->hp();

        $this->with($this->enemyShot())->act($this->character->fresh(), Action::brace());

        $this->assertSame($hp - 3, $this->hp());
    }

    // ---- what it refuses ----------------------------------------------------------------

    public function test_only_a_belted_fight_only_drug_can_be_used(): void
    {
        $this->open();
        $ordinary = Item::factory()->create(['container' => 'physical', 'name' => 'Stim', 'stackable' => true]);
        Drug::query()->create(['item_id' => $ordinary->id, 'family' => Family::Stimulant, 'duration_minutes' => 60, 'addictive' => 0, 'tolerance_gain' => 0]);
        $onBelt = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $ordinary->id, 'quantity' => 1, 'belt_slot' => 2]);
        $loose = $this->belted();
        $loose->forceFill(['belt_slot' => null])->save();
        $before = $this->hp();

        foreach ([$onBelt->id, $loose->id, 99999] as $id) {
            $this->assertSame(FightRefusal::CannotUse, $this->with([])->act($this->character->fresh(), Action::use($id)));
        }

        $this->assertSame($before, $this->hp(), 'a refusal plays no round');
        $this->assertSame(0, Dose::query()->count());
    }

    public function test_a_fight_only_drug_cannot_be_taken_outside_a_fight(): void
    {
        $row = $this->belted();

        $result = app(DrugTaker::class)->take($this->character->fresh(), $row);

        $this->assertFalse($result->succeeded());
        $this->assertSame(DoseRefusal::OnlyInAFight, $result->refusal);
        $this->assertSame(3, $row->fresh()->quantity);
    }

    public function test_the_stash_card_has_no_take_button_for_it_and_the_fight_screen_offers_use(): void
    {
        $row = $this->belted();
        $user = $this->character->user;

        $this->actingAs($user)->get(route('inventory.index'))->assertOk()->assertSee('Fights only')->assertDontSee(route('inventory.take', $row), false);

        $this->open();
        // A fresh user: the first request cached its character before the fight existed.
        $this->actingAs($this->character->fresh()->user->fresh())->get(route('fight.show'))->assertOk()->assertSee('Use Combat stim')->assertSee('value="'.$row->id.'"', false);
    }

    // ---- the books, and the end ---------------------------------------------------------

    public function test_it_counts_toward_the_overdose_line_and_a_lethal_one_ends_the_fight(): void
    {
        // Medical's line is two doses at once; the third is one over, and this drug stops a heart at one over.
        $row = $this->belted(quantity: 3, turns: 5, perTurn: 0, lethalAt: 1);
        $fight = $this->open();

        foreach ([1, 2] as $i) {
            $this->with($this->enemyShot())->act($this->character->fresh(), Action::use($row->id));
        }

        $fight = $this->with($this->enemyShot())->act($this->character->fresh(), Action::use($row->id));

        $this->assertSame(Result::ADown, $fight->result);
        $this->assertFalse($fight->isLive());
        $this->assertNull($this->character->fresh()->current_fight_id);
        $this->assertSame(0, $this->hp());
        $this->assertSame(0, Dose::query()->count(), 'fight doses end with the fight');
    }

    public function test_a_won_fight_clears_its_doses(): void
    {
        $row = $this->belted();
        $this->open($this->npc(hp: 1));
        $this->with($this->enemyShot())->act($this->character->fresh(), Action::use($row->id));
        $this->assertSame(1, Dose::query()->count());

        // The enemy is not killed by a brace; end it another way: it flees nowhere, so remove the fight by force.
        $fight = Fight::query()->sole();
        $state = $fight->snapshot();
        $fight->forceFill(['state' => $state->with(Side::B, $state->b->with(hp: 0))->toArray()])->save();
        $this->with($this->enemyShot())->act($this->character->fresh(), Action::brace());

        $this->assertSame(0, Dose::query()->count());
    }

    // ---- the editor ---------------------------------------------------------------------

    public function test_the_editor_saves_a_fight_only_drug_and_wants_its_rounds(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $base = [
            'name' => 'Combat stim', 'container' => 'physical', 'tier' => 1, 'rarity' => 'common', 'base_cost' => 10,
            'weight' => 1, 'size' => 1, 'capacity' => 0, 'seed_quantity' => 0, 'stackable' => 1, 'slug' => 'combat_stim',
            'drug_family' => 'medical', 'fight_only' => '1', 'addictive' => '0', 'tolerance_gain' => '0',
            'modifiers' => [['op' => 'add', 'amount' => '3', 'target' => 'defence', 'carried' => '']],
        ];

        $this->actingAs($admin)->post(route('admin.items.store'), $base + ['duration_turns' => '', 'turn_hitpoints' => '2'])->assertSessionHasErrors('duration_turns');
        $this->assertDatabaseMissing('items', ['slug' => 'combat_stim']);

        $this->actingAs($admin)->post(route('admin.items.store'), $base + ['duration_turns' => '4', 'turn_hitpoints' => '2'])->assertSessionHasNoErrors();
        $drug = Item::query()->where('slug', 'combat_stim')->sole()->drug;
        $this->assertTrue($drug->fight_only);
        $this->assertSame(4, $drug->duration_turns);
        $this->assertSame(2, $drug->turn_hitpoints);
        $this->assertSame(0, $drug->duration_minutes);
    }
}
