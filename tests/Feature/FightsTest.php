<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\City\CityRefusal;
use App\City\Encounters;
use App\City\Explorer;
use App\City\Presence;
use App\City\Traveller;
use App\City\TravelMode;
use App\Combat\Fight\Action;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\Result;
use App\Combat\FightRefusal;
use App\Combat\Fights;
use App\Combat\WeaponClass;
use App\Cyberspace\Navigator;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunRefusal;
use App\Cyberspace\RunStarter;
use App\Cyberspace\TravelRefusal;
use App\Models\Ammunition;
use App\Models\Character;
use App\Models\CharacterEncounter;
use App\Models\CityActivity;
use App\Models\District;
use App\Models\Encounter;
use App\Models\Fight;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Location;
use App\Models\Npc;
use App\Models\Weapon;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * A fight as a stored thing, played a round at a time against a real character. COMBAT.md, step 4.
 *
 * The dice are pinned: an NPC aim of 99 makes the enemy's every shot Dead-on at a die of 0.0, and the
 * player (aim near 13 against a defence of 0) is Dead-on at the same. A body die of 0.5 is the right leg.
 */
final class FightsTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private InventoryItem $pistol;

    private Item $fmj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));

        $gun = Item::factory()->physical()->create(['name' => 'Pistol', 'stackable' => false]);
        Weapon::create(['item_id' => $gun->id, 'class' => WeaponClass::Pistol, 'accuracy' => 0, 'rate' => 1, 'magazine' => 12, 'calibre' => '9mm']);
        $this->pistol = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $gun->id, 'quantity' => 1]);
        $this->character->forceFill(['equipped_ranged_id' => $this->pistol->id])->save();

        $this->fmj = Item::factory()->physical()->create(['name' => '9mm FMJ', 'stackable' => true]);
        Ammunition::create(['item_id' => $this->fmj->id, 'calibre' => '9mm', 'type' => 'full_metal_jacket', 'damage' => 8, 'damage_profile' => ['penetrating' => 100], 'penetration' => 0]);
    }

    private function stock(int $quantity): void
    {
        InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $this->fmj->id, 'quantity' => $quantity]);
    }

    private function load(int $rounds): void
    {
        $this->pistol->forceFill(['loaded_ammo_id' => $this->fmj->id, 'loaded_rounds' => $rounds])->save();
    }

    private function npc(array $attributes = [], int $damage = 5, string $class = 'pistol'): Npc
    {
        $gun = Item::factory()->physical()->create(['name' => 'Gang pistol', 'stackable' => false]);
        $calibre = 'gang-'.$gun->id;
        Weapon::create(['item_id' => $gun->id, 'class' => WeaponClass::from($class), 'accuracy' => 0, 'rate' => 1, 'magazine' => 12, 'calibre' => $calibre]);
        $round = Item::factory()->physical()->create(['name' => 'Gang round', 'stackable' => true]);
        Ammunition::create(['item_id' => $round->id, 'calibre' => $calibre, 'type' => 'standard', 'damage' => $damage, 'damage_profile' => $class === 'pistol' ? ['penetrating' => 100] : ['neural' => 100], 'penetration' => 0]);

        return Npc::create($attributes + ['slug' => 'thug', 'name' => 'Thug', 'grade' => 2, 'hitpoints' => 40, 'aim' => 99, 'defence' => 0, 'pursuit' => 8, 'ranged_weapon_id' => $gun->id, 'ammo_item_id' => $round->id]);
    }

    private function fights(array $dice = []): Fights
    {
        return new Fights(new FightEngine(CheckRoller::fromConfig()), new QueueDice($dice));
    }

    private function open(Npc $npc, array $dice = []): Fight
    {
        $fight = $this->fights($dice)->open($this->character, $npc);
        $this->assertInstanceOf(Fight::class, $fight);

        return $fight;
    }

    // ---- opening ------------------------------------------------------------------------

    public function test_a_fight_opens_as_a_snapshot_and_nothing_has_happened_yet(): void
    {
        $this->load(12);
        $npc = $this->npc(['resistances' => ['blunt' => 20], 'slot_resistances' => ['head' => ['blunt' => 30]]]);

        $fight = $this->open($npc);

        $this->assertSame($fight->id, $this->character->fresh()->current_fight_id);
        $this->assertTrue($fight->isLive());
        $this->assertSame('Thug', $fight->opponent_name);
        $this->assertSame(2, $fight->grade);
        $this->assertSame(['blunt' => 20], $fight->resistances);
        $this->assertSame(['head' => ['blunt' => 30]], $fight->slot_resistances);
        $this->assertSame($this->pistol->id, $fight->ranged_row_id);
        $this->assertNull($fight->melee_row_id);

        $state = $fight->snapshot();
        $this->assertSame(1, $state->round);
        $this->assertSame('ranged', $state->range->value);
        $this->assertSame('b', $state->first->value, 'the enemy acts first');
        $this->assertSame([], $state->log);
        $this->assertSame((int) $this->character->current_hitpoints, $state->a->hp);
        $this->assertSame(40, $state->b->hp);
    }

    public function test_it_refuses_somebody_who_cannot_be_in_one(): void
    {
        $npc = $this->npc();

        $this->character->forceFill(['grid_x' => 3, 'grid_y' => 4])->save();
        $this->assertSame(FightRefusal::JackedIn, $this->fights()->open($this->character, $npc));
        $this->character->forceFill(['grid_x' => null, 'grid_y' => null])->save();

        $this->character->forceFill(['waiting_until' => now()->addMinutes(5)])->save();
        $this->assertSame(FightRefusal::Down, $this->fights()->open($this->character->fresh(), $npc));
        $this->character->forceFill(['waiting_until' => null])->save();

        CityActivity::query()->create(['character_id' => $this->character->id, 'kind' => 'explore', 'pass_ends_at' => now()->addMinute(), 'paid' => 0, 'step' => 0, 'cancelling' => false]);
        $this->assertSame(FightRefusal::Busy, $this->fights()->open($this->character->fresh(), $npc), 'a trip would settle under a fight');
        CityActivity::query()->delete();

        $this->assertInstanceOf(Fight::class, $this->fights()->open($this->character->fresh(), $npc));
        $this->assertSame(FightRefusal::InFight, $this->fights()->open($this->character->fresh(), $npc));
        $this->assertSame(1, Fight::query()->count());
    }

    public function test_a_fight_may_open_inside_an_encounter(): void
    {
        $encounter = Encounter::create(['slug' => 'ambush', 'title' => 'Ambush', 'intro' => 'x', 'starts_on' => 'explore', 'weight' => 10]);
        $open = CharacterEncounter::create(['character_id' => $this->character->id, 'encounter_id' => $encounter->id, 'node_key' => 'start', 'log' => [], 'started_at' => now()]);
        $this->character->forceFill(['current_encounter_id' => $open->id])->save();

        $this->assertInstanceOf(Fight::class, $this->fights()->open($this->character->fresh(), $this->npc()));
        $this->assertSame($open->id, $this->character->fresh()->current_encounter_id, 'the encounter stays');
    }

    // ---- a round ------------------------------------------------------------------------

    public function test_a_round_hurts_both_sides_and_costs_a_round_of_ammunition(): void
    {
        $this->load(12);
        $before = (int) $this->character->fresh()->current_hitpoints;
        $fight = $this->open($this->npc());

        // The enemy first (Dead-on, right leg: 5), then the player (Dead-on, right leg: 8).
        $fights = $this->fights([0.0, 0.5,  0.0, 0.5]);
        $result = $fights->act($this->character->fresh(), Action::attack());

        $this->assertInstanceOf(Fight::class, $result);
        $this->assertSame($before - 5, (int) $this->character->fresh()->current_hitpoints, 'applied to the real body');
        $this->assertSame(11, $this->pistol->fresh()->rounds(), 'one round fired, on the right row');

        $state = $result->fresh()->snapshot();
        $this->assertSame(32, $state->b->hp);
        $this->assertSame(2, $state->round);
        $this->assertSame(2, $state->distance, 'a fight opens at 2 and only an action moves it');
        $this->assertSame('ranged', $state->range->value);
        $this->assertCount(2, $state->log);
        $this->assertTrue($result->fresh()->isLive());
        $this->assertSame($fight->id, $this->character->fresh()->current_fight_id);
    }

    public function test_synaptic_integrity_that_regenerates_between_rounds_is_not_wiped(): void
    {
        $this->load(12);
        $this->character->forceFill(['current_synaptic_integrity' => 10])->save();
        $this->open($this->npc());

        // A minute passes and `characters:regenerate` credits a point.
        Character::query()->whereKey($this->character->id)->update(['current_synaptic_integrity' => 12]);

        $this->fights([0.0, 0.5,  0.0, 0.5])->act($this->character->fresh(), Action::attack());

        $this->assertSame(12, (int) $this->character->fresh()->current_synaptic_integrity, 'the body is the source of truth');
    }

    public function test_neural_damage_lands_on_the_players_mind_and_not_their_body(): void
    {
        $this->load(12);
        $hp = (int) $this->character->fresh()->current_hitpoints;
        $si = (int) $this->character->fresh()->current_synaptic_integrity;
        $this->open($this->npc(damage: 6, class: 'rifle'));   // a neural weapon, at range

        $this->fights([0.0, 0.5,  0.0, 0.5])->act($this->character->fresh(), Action::attack());

        $this->assertSame($hp, (int) $this->character->fresh()->current_hitpoints);
        $this->assertSame($si - 6, (int) $this->character->fresh()->current_synaptic_integrity);
    }

    public function test_a_load_is_replayed_onto_the_weapon_and_the_stash(): void
    {
        $this->stock(30);                                   // an empty magazine, thirty in the stash
        $this->open($this->npc());

        $this->fights([0.0, 0.5])->act($this->character->fresh(), Action::load((string) $this->fmj->id));   // only the enemy rolls

        $this->assertSame(12, $this->pistol->fresh()->rounds());
        $this->assertSame($this->fmj->id, $this->pistol->fresh()->loaded_ammo_id);
        $this->assertSame(18, (int) InventoryItem::query()->where('item_id', $this->fmj->id)->whereNull('loaded_ammo_id')->sum('quantity'));
    }

    public function test_a_win_ends_the_fight_and_lets_go_of_the_character(): void
    {
        $this->load(12);
        $fight = $this->open($this->npc(['hitpoints' => 5]));

        $result = $this->fights([0.0, 0.5,  0.0, 0.5])->act($this->character->fresh(), Action::attack());

        $this->assertSame(Result::BDown, $result->result);
        $this->assertNotNull($result->resolved_at);
        $this->assertNull($this->character->fresh()->current_fight_id);
        $this->assertFalse(app(WaitingRoom::class)->isDown($this->character->fresh()), 'a win does not put you on the floor');
        $this->assertFalse($result->fresh()->isLive());
    }

    public function test_the_player_going_down_goes_to_the_waiting_room(): void
    {
        $this->load(12);
        $this->character->forceFill(['current_hitpoints' => 3])->save();
        $this->open($this->npc());

        $dice = new QueueDice([0.0, 0.5]);                    // the enemy shoots; the player never gets a turn
        $result = (new Fights(new FightEngine(CheckRoller::fromConfig()), $dice))->act($this->character->fresh(), Action::attack());
        $dice->assertSpent();

        $this->assertSame(Result::ADown, $result->result);
        $character = $this->character->fresh();
        $this->assertNull($character->current_fight_id);
        $this->assertSame(0, (int) $character->current_hitpoints);
        $this->assertTrue(app(WaitingRoom::class)->isDown($character));
        $this->assertSame('fight', $character->waiting_cause['reason']);
        $this->assertSame(11 + 1, $this->pistol->fresh()->rounds(), 'their shot was never fired');
    }

    public function test_an_escape_ends_it_with_nothing_lost_or_gained(): void
    {
        $this->load(12);
        $hp = (int) $this->character->fresh()->current_hitpoints;
        $fight = $this->open($this->npc());
        $fight->update(['state' => [...$fight->state, 'distance' => 3]]);                                   // nobody runs from within two

        $result = $this->fights([0.0, 0.5,  0.0])->act($this->character->fresh(), Action::disengage());   // the enemy shoots, then the player gets away

        $this->assertSame(Result::AEscaped, $result->result);
        $this->assertNull($this->character->fresh()->current_fight_id);
        $this->assertFalse(app(WaitingRoom::class)->isDown($this->character->fresh()));
        $this->assertSame($hp - 5, (int) $this->character->fresh()->current_hitpoints, 'the shot it took stays taken');
    }

    // ---- the snapshot, and the double press --------------------------------------------

    public function test_an_opponent_edited_or_deleted_mid_fight_does_not_change_the_fight(): void
    {
        $this->load(12);
        $npc = $this->npc(['resistances' => ['penetrating' => 100]]);   // immune to the player's pistol
        $fight = $this->open($npc);

        $npc->update(['resistances' => null, 'name' => 'Changed']);
        $npc->delete();

        $result = $this->fights([0.0, 0.5,  0.0, 0.5])->act($this->character->fresh(), Action::attack());

        $this->assertInstanceOf(Fight::class, $result);
        $this->assertNull($result->fresh()->npc_id, 'nulled, not cascaded');
        $this->assertSame('Thug', $result->fresh()->opponent_name);
        $this->assertSame(40, $result->fresh()->snapshot()->b->hp, 'the snapshot\'s immunity held');
    }

    public function test_a_second_press_on_a_finished_fight_changes_nothing(): void
    {
        $this->load(12);
        $this->open($this->npc(['hitpoints' => 5]));
        $this->fights([0.0, 0.5,  0.0, 0.5])->act($this->character->fresh(), Action::attack());
        $hp = (int) $this->character->fresh()->current_hitpoints;
        $rounds = $this->pistol->fresh()->rounds();

        $again = $this->fights([])->act($this->character->fresh(), Action::attack());

        $this->assertSame(FightRefusal::NoFight, $again);
        $this->assertSame($hp, (int) $this->character->fresh()->current_hitpoints);
        $this->assertSame($rounds, $this->pistol->fresh()->rounds());
    }

    public function test_acting_with_no_fight_is_refused(): void
    {
        $this->assertSame(FightRefusal::NoFight, $this->fights([])->act($this->character, Action::attack()));
    }

    public function test_a_weapon_that_is_no_longer_in_hand_is_loud_and_nothing_is_kept(): void
    {
        $this->load(12);
        $this->open($this->npc());
        $hp = (int) $this->character->fresh()->current_hitpoints;

        // Something unequipped it behind the lock's back.
        $this->character->forceFill(['equipped_ranged_id' => null])->save();

        try {
            $this->fights([0.0, 0.5,  0.0, 0.5])->act($this->character->fresh(), Action::attack());
            $this->fail('A desynchronised fight must not be applied quietly.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('no longer in hand', $e->getMessage());
        }

        $this->assertSame($hp, (int) $this->character->fresh()->current_hitpoints, 'rolled back');
        $this->assertSame(12, $this->pistol->fresh()->rounds());
        $this->assertCount(0, $this->character->fresh()->currentFight->snapshot()->log);
    }

    // ---- nothing else may start under a fight ------------------------------------------

    public function test_nothing_starts_while_a_fight_is_live(): void
    {
        $this->open($this->npc());
        $character = $this->character->fresh();

        $this->assertSame(TravelRefusal::Fighting, Navigator::fromConfig(app(Resolver::class))->jackIn($character)->refusal);
        $this->assertSame(RunRefusal::Fighting, app(RunStarter::class)->start($character, RunPurpose::Extraction)->refusal);
        $this->assertSame(CityRefusal::Fighting, app(Explorer::class)->begin($character));

        $district = District::query()->where('id', '!=', $character->district_id)->first() ?? District::query()->first();
        $this->assertSame(CityRefusal::Fighting, app(Traveller::class)->quote($character, $district));
        $this->assertSame(CityRefusal::Fighting, app(Traveller::class)->begin($character, $district, TravelMode::Walk));

        $place = Location::query()->first();
        if ($place !== null) {
            $this->assertSame(CityRefusal::Fighting, Presence::refusal($character, $place), 'a shop, a clinic and surgery all ask Presence');
        }

        $this->assertSame(0, CityActivity::query()->count());
    }

    public function test_the_sweep_cannot_open_an_encounter_under_a_fight(): void
    {
        $encounters = app(Encounters::class);

        $this->assertTrue($encounters->canHappenTo($this->character->fresh()));

        $this->open($this->npc());

        $this->assertFalse($encounters->canHappenTo($this->character->fresh()));
    }

    public function test_once_it_is_over_things_start_again(): void
    {
        $this->load(12);
        $this->open($this->npc(['hitpoints' => 5]));
        $this->fights([0.0, 0.5,  0.0, 0.5])->act($this->character->fresh(), Action::attack());

        $this->assertTrue(app(Encounters::class)->canHappenTo($this->character->fresh()));
        $this->assertInstanceOf(CityActivity::class, app(Explorer::class)->begin($this->character->fresh()));
    }

    public function test_the_options_quote_what_the_next_roll_uses(): void
    {
        $this->stock(30);
        $fight = $this->open($this->npc());

        $options = $this->fights()->options($fight);

        $this->assertFalse($options['can_attack'], 'an empty magazine cannot fire');
        $this->assertSame([], $options['attack']);
        $this->assertSame([['key' => (string) $this->fmj->id, 'name' => '9mm FMJ', 'stock' => 30]], $options['loads']);
        $this->assertTrue($options['can_create_distance'], 'backing off is on offer below the maximum');
        $this->assertTrue($options['can_close_in']);
        $this->assertFalse($options['can_disengage'], 'not from within two');
        $this->assertGreaterThan(0.5, $options['closing'], 'the player\'s pursuit against a defence of nothing');
        $this->assertEqualsWithDelta(0.5, $options['flight'], 1e-9, 'defence 8 against pursuit 8');
    }
}
