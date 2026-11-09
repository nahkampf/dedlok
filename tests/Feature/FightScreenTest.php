<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\Side;
use App\Combat\FightEnding;
use App\Combat\Fights;
use App\Combat\WeaponClass;
use App\Items\ConsumableEditor;
use App\Models\Ammunition;
use App\Models\Character;
use App\Models\Fight;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Npc;
use App\Models\Weapon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * The fight screen, and the lock that makes it the only one a fighter can reach. docs/COMBAT.md, step 4.
 */
final class FightScreenTest extends TestCase
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
        Weapon::create(['item_id' => $gun->id, 'class' => WeaponClass::Pistol, 'accuracy' => 0, 'rate' => 2, 'magazine' => 12, 'calibre' => '9mm']);
        $this->pistol = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $gun->id, 'quantity' => 1]);
        $this->character->forceFill(['equipped_ranged_id' => $this->pistol->id])->save();

        $this->fmj = Item::factory()->physical()->create(['name' => '9mm FMJ', 'stackable' => true]);
        Ammunition::create(['item_id' => $this->fmj->id, 'calibre' => '9mm', 'type' => 'full_metal_jacket', 'damage' => 8, 'damage_profile' => ['penetrating' => 100], 'penetration' => 0]);
    }

    private function npc(int $hp = 40): Npc
    {
        $gun = Item::factory()->physical()->create(['name' => 'Gang pistol', 'stackable' => false]);
        $calibre = 'gang-'.$gun->id;
        Weapon::create(['item_id' => $gun->id, 'class' => WeaponClass::Pistol, 'accuracy' => 0, 'rate' => 1, 'magazine' => 12, 'calibre' => $calibre]);
        $round = Item::factory()->physical()->create(['name' => 'Gang round', 'stackable' => true]);
        Ammunition::create(['item_id' => $round->id, 'calibre' => $calibre, 'type' => 'standard', 'damage' => 5, 'damage_profile' => ['penetrating' => 100], 'penetration' => 0]);

        return Npc::create(['slug' => 'thug', 'name' => 'Thug', 'grade' => 2, 'hitpoints' => $hp, 'aim' => 99, 'defence' => 0, 'pursuit' => 8, 'ranged_weapon_id' => $gun->id, 'ammo_item_id' => $round->id]);
    }

    /** Open a fight, with the screen's own Fights bound to a pinned die. */
    private function fight(array $dice = [], int $npcHp = 40): void
    {
        $this->app->bind(Fights::class, fn (): Fights => new Fights(new FightEngine(CheckRoller::fromConfig()), new QueueDice($dice)));
        // The ending's own die, at the band's floor, so what a win pays or a loss takes is the same every run.
        $this->app->bind(FightEnding::class, fn (): FightEnding => FightEnding::fromConfig(fn (int $min, int $max): int => $min));
        $this->app->make(Fights::class)->open($this->character, $this->npc($npcHp));
    }

    private function loadMagazine(int $rounds = 12): void
    {
        $this->pistol->forceFill(['loaded_ammo_id' => $this->fmj->id, 'loaded_rounds' => $rounds])->save();
    }

    private function as(): static
    {
        return $this->actingAs($this->character->user);
    }

    // ---- the screen ---------------------------------------------------------------------

    public function test_with_no_fight_the_screen_goes_back_to_the_city(): void
    {
        $this->as()->get(route('fight.show'))->assertRedirect(route('city.index'));
    }

    public function test_it_shows_who_you_face_where_you_stand_and_what_you_can_do(): void
    {
        $this->loadMagazine();
        $this->fight();

        $this->as()->get(route('fight.show'))->assertOk()
            ->assertSeeText('Thug')->assertSeeText('Grade 2')->assertSeeText('40 / 40')
            ->assertSeeText('Round 1')->assertSeeText('Ranged')->assertSeeText('They act first.')
            ->assertSeeText('Attack with Pistol')->assertSeeText('2 shots, each a little worse than the last')
            ->assertDontSeeText('Disengage')
            ->assertSeeText('Close in')->assertSeeText('Create distance')->assertDontSeeText('Brace');
    }

    public function test_it_lists_what_the_opponent_carries_and_wears(): void
    {
        $this->loadMagazine();
        $this->fight();
        Fight::query()->update(['resistances' => ['penetrating' => 30], 'slot_resistances' => ['head' => ['penetrating' => 40]]]);

        $this->as()->get(route('fight.show'))->assertOk()
            ->assertSeeText('Ranged: Gang pistol')->assertSeeText('Resistances')->assertSeeText('Shots per attack')->assertSeeText('+30%')->assertSeeText('+40%');
    }

    public function test_chance_opens_an_explanation_of_the_numbers_behind_it(): void
    {
        $this->loadMagazine();
        $this->fight();

        $this->as()->get(route('fight.show'))->assertOk()
            ->assertSee('data-open="#chance-attack"', false)
            ->assertSee('data-open="#chance-close_in"', false)->assertSee('data-open="#chance-create_distance"', false)
            ->assertDontSee('class="link" data-open="#chance-attack"><button', false)->assertSeeText('Chance to hit')->assertSeeText('Your aim')->assertSeeText('Their defence')->assertSeeText('Margin');
    }

    public function test_an_allowed_consumable_on_the_belt_is_a_button_with_its_own_chance(): void
    {
        $this->loadMagazine();
        $this->fight();
        $item = Item::factory()->physical()->create(['name' => 'Field kit', 'stackable' => true]);
        ConsumableEditor::apply($item, [['kind' => 'heal_hitpoints', 'amount' => 7]], true);
        InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 2, 'belt_slot' => 1]);
        $this->character->forceFill(['current_hitpoints' => 5])->save();

        $this->as()->get(route('fight.show'))->assertOk()
            ->assertSeeText('Use Field kit')->assertSee('data-open="#chance-use"', false)->assertSeeText('Chance to get it out');
    }

    public function test_the_bar_shows_where_each_side_stands_about_the_meeting_point(): void
    {
        $this->loadMagazine();
        $this->fight();

        $this->as()->get(route('fight.show'))->assertOk()
            ->assertSee('role="img"', false)->assertSeeText('Face to face')->assertSeeText('You can flee')->assertSeeText('They can flee')
            ->assertSee('You are 1 steps out from the meeting point, they are 1 steps out.', false);
    }

    public function test_an_empty_magazine_offers_loading_and_bracing_instead_of_an_attack(): void
    {
        InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $this->fmj->id, 'quantity' => 30]);
        $this->fight();

        $this->as()->get(route('fight.show'))->assertOk()
            ->assertSeeText('Nothing to attack with at this range.')
            ->assertSeeText('Load 9mm FMJ')->assertSeeText('30 in your stash')
            ->assertSeeText('Brace')
            ->assertDontSeeText('Attack with');
    }

    public function test_the_quoted_chance_is_the_one_the_roll_uses(): void
    {
        $this->loadMagazine();
        $this->fight();

        $quote = (new FightEngine(CheckRoller::fromConfig()))->quote(Fight::query()->sole()->snapshot(), Side::A);
        $expected = implode(' · ', array_map(fn (float $c): string => round($c * 100).'%', $quote));

        $this->as()->get(route('fight.show'))->assertSeeText($expected);
    }

    public function test_the_distance_only_moves_when_somebody_moves_it_and_running_needs_room(): void
    {
        $this->loadMagazine();
        $this->fight([0.0, 0.5, 0.0, 0.5, 0.0, 0.5,  0.0, 0.5, 0.1]);

        $this->as()->get(route('fight.show'))->assertOk()
            ->assertSeeText('Gap 2')->assertSeeText('Close in')->assertSeeText('Create distance')->assertDontSeeText('Disengage');

        $this->as()->post(route('fight.act'), ['action' => 'attack'])->assertRedirect(route('fight.show'));

        $this->as()->get(route('fight.show'))->assertOk()
            ->assertSeeText('Round 2')->assertSeeText('Gap 2')->assertSeeText('Chance 50%');

        // The enemy shoots, then the player backs off: a lucky roll wins two steps and running is possible.
        $this->as()->post(route('fight.act'), ['action' => 'create_distance'])->assertRedirect(route('fight.show'));

        $this->as()->get(route('fight.show'))->assertOk()
            ->assertSeeText('Round 3')->assertSeeText('Gap 4')->assertSeeText('Ranged')->assertSeeText('Disengage');
    }

    // ---- a round ------------------------------------------------------------------------

    public function test_pressing_attack_plays_a_round_and_the_log_says_what_happened(): void
    {
        $this->loadMagazine();
        $this->fight([0.0, 0.5,  0.0, 0.5, 0.0, 0.5]);   // the enemy's shot, then both of the player's

        $this->as()->post(route('fight.act'), ['action' => 'attack'])->assertRedirect(route('fight.show'));

        $this->as()->get(route('fight.show'))->assertOk()
            ->assertSeeText('Thug: Dead-on to the Right leg for 5 penetrating.')
            ->assertSeeText($this->character->handle.': Dead-on to the Right leg for 8 penetrating.')
            ->assertSeeText('24 / 40');
        $this->assertSame(10, $this->pistol->fresh()->rounds(), 'two shots fired');
    }

    public function test_winning_sends_you_back_to_the_city_with_a_line_and_frees_you(): void
    {
        $this->loadMagazine();
        $this->fight([0.0, 0.5,  0.0, 0.5], npcHp: 5);

        $this->as()->post(route('fight.act'), ['action' => 'attack'])
            ->assertRedirect(route('city.index'))->assertSessionHas('status', 'Thug is down. You take '.config('combat.pay.2.min').' ¤ off it.');

        $this->assertNull($this->character->fresh()->current_fight_id);
        $this->as()->get(route('city.index'))->assertOk();
    }

    public function test_getting_away_says_so(): void
    {
        $this->loadMagazine();
        $this->fight([0.0, 0.5,  0.0]);
        $row = Fight::query()->sole();
        $row->update(['state' => [...$row->state, 'distance' => 3, 'you_at' => 3]]);

        $this->as()->post(route('fight.act'), ['action' => 'disengage'])
            ->assertRedirect(route('city.index'))->assertSessionHas('status', 'You get away from Thug.');
    }

    public function test_going_down_puts_you_in_the_waiting_room(): void
    {
        $this->loadMagazine();
        $this->character->forceFill(['current_hitpoints' => 3])->save();
        $this->fight([0.0, 0.5]);

        $this->as()->post(route('fight.act'), ['action' => 'attack'])->assertRedirect(route('city.index'))->assertSessionHas('error', 'You are down.');

        $this->assertTrue(app(WaitingRoom::class)->isDown($this->character->fresh()));
        $this->assertNull($this->character->fresh()->current_fight_id);
    }

    public function test_a_second_press_after_it_is_over_changes_nothing(): void
    {
        $this->loadMagazine();
        $this->fight([0.0, 0.5,  0.0, 0.5], npcHp: 5);
        $this->as()->post(route('fight.act'), ['action' => 'attack']);
        $hp = (int) $this->character->fresh()->current_hitpoints;

        $this->as()->post(route('fight.act'), ['action' => 'attack'])
            ->assertRedirect(route('city.index'))->assertSessionHas('error', 'There is no fight to act in.');

        $this->assertSame($hp, (int) $this->character->fresh()->current_hitpoints);
    }

    public function test_an_unknown_action_is_refused_before_anything_happens(): void
    {
        $this->loadMagazine();
        $this->fight([]);

        $this->as()->post(route('fight.act'), ['action' => 'flee'])->assertSessionHasErrors('action');
        $this->as()->post(route('fight.act'), [])->assertSessionHasErrors('action');
        $this->assertSame(12, $this->pistol->fresh()->rounds());
    }

    // ---- the command that opens one -----------------------------------------------------

    public function test_the_command_opens_a_fight_and_locks_the_character_into_it(): void
    {
        $this->npc();

        $this->artisan('fight:start', ['handle' => $this->character->handle, 'npc' => 'thug'])
            ->expectsOutputToContain('is in a fight with Thug')->assertExitCode(0);

        $this->assertNotNull($this->character->fresh()->current_fight_id);
        $this->as()->get(route('city.index'))->assertRedirect(route('fight.show'));
    }

    public function test_the_command_says_why_not_and_refuses_the_unknown(): void
    {
        $this->npc();
        $this->character->forceFill(['waiting_until' => now()->addMinutes(5)])->save();

        $this->artisan('fight:start', ['handle' => $this->character->handle, 'npc' => 'thug'])
            ->expectsOutputToContain('You are on the floor.')->assertExitCode(1);
        $this->artisan('fight:start', ['handle' => 'nobody', 'npc' => 'thug'])->expectsOutputToContain('No character')->assertExitCode(1);
        $this->artisan('fight:start', ['handle' => $this->character->handle, 'npc' => 'nobody'])->expectsOutputToContain('No opponent')->assertExitCode(1);
        $this->assertSame(0, Fight::query()->count());
    }

    // ---- the lock -----------------------------------------------------------------------

    public function test_a_fighter_can_reach_nothing_but_the_fight(): void
    {
        $this->loadMagazine();
        $this->fight([]);

        foreach (['city.index', 'inventory.index', 'character.index', 'skills.index', 'wallet.index'] as $route) {
            $this->as()->get(route($route))->assertRedirect(route('fight.show'));
        }

        $this->as()->post(route('inventory.wield', $this->pistol))->assertRedirect(route('fight.show'));
        $this->as()->get(route('fight.show'))->assertOk();
    }

    public function test_the_lock_answers_a_json_caller_with_a_409_and_a_url(): void
    {
        $this->fight([]);

        $this->as()->getJson(route('city.index'))->assertStatus(409)->assertJson(['redirect' => route('fight.show')]);
    }

    public function test_an_admin_is_not_exempt(): void
    {
        $this->character->user->forceFill(['is_admin' => true])->save();
        $this->fight([]);

        $this->as()->get(route('admin.items.index'))->assertRedirect(route('fight.show'));
    }
}
