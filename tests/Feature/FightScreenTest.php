<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\Side;
use App\Combat\Fights;
use App\Combat\WeaponClass;
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
 * The fight screen, and the lock that makes it the only one a fighter can reach. COMBAT.md, step 4.
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
        Weapon::create(['item_id' => $gun->id, 'class' => WeaponClass::Pistol, 'damage' => 8, 'damage_profile' => ['penetrating' => 100], 'accuracy' => 0, 'rate' => 2, 'magazine' => 12, 'calibre' => '9mm']);
        $this->pistol = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $gun->id, 'quantity' => 1]);
        $this->character->forceFill(['equipped_ranged_id' => $this->pistol->id])->save();

        $this->fmj = Item::factory()->physical()->create(['name' => '9mm FMJ', 'stackable' => true]);
        Ammunition::create(['item_id' => $this->fmj->id, 'calibre' => '9mm', 'type' => 'full_metal_jacket', 'damage' => 0, 'penetration' => 0]);
    }

    private function npc(int $hp = 40): Npc
    {
        $gun = Item::factory()->physical()->create(['name' => 'Gang pistol', 'stackable' => false]);
        Weapon::create(['item_id' => $gun->id, 'class' => WeaponClass::Pistol, 'damage' => 5, 'damage_profile' => ['penetrating' => 100], 'accuracy' => 0, 'rate' => 1, 'magazine' => 12, 'calibre' => '9mm']);

        return Npc::create(['slug' => 'thug', 'name' => 'Thug', 'grade' => 2, 'hitpoints' => $hp, 'aim' => 99, 'defence' => 0, 'pursuit' => 8, 'ranged_weapon_id' => $gun->id]);
    }

    /** Open a fight, with the screen's own Fights bound to a pinned die. */
    private function fight(array $dice = [], int $npcHp = 40): void
    {
        $this->app->bind(Fights::class, fn (): Fights => new Fights(new FightEngine(CheckRoller::fromConfig()), new QueueDice($dice)));
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
            ->assertSeeText('Disengage')
            ->assertDontSeeText('Create distance')->assertDontSeeText('Brace');
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

    public function test_close_in_it_offers_creating_distance_with_its_chance(): void
    {
        $this->loadMagazine();
        $this->fight([0.0, 0.5, 0.0, 0.5,  0.0, 0.5, 0.0, 0.5]);

        $this->as()->post(route('fight.act'), ['action' => 'attack'])->assertRedirect(route('fight.show'));

        $this->as()->get(route('fight.show'))->assertOk()
            ->assertSeeText('Round 2')->assertSeeText('Close')
            ->assertSeeText('Create distance')->assertSeeText('Chance 50%')
            ->assertSeeText('Attack with Bare hands', false);
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
            ->assertRedirect(route('city.index'))->assertSessionHas('status', 'Thug is down.');

        $this->assertNull($this->character->fresh()->current_fight_id);
        $this->as()->get(route('city.index'))->assertOk();
    }

    public function test_getting_away_says_so(): void
    {
        $this->loadMagazine();
        $this->fight([0.0, 0.5,  0.0]);

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
