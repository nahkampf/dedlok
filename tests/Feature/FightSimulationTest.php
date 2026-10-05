<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Checks\CheckRoller;
use App\Combat\Fight\Arm;
use App\Combat\Fight\Combatant;
use App\Combat\Fight\CombatantBuilder;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\FightMitigation;
use App\Combat\Fight\FightState;
use App\Combat\Fight\NpcMitigation;
use App\Combat\Fight\RoundSpec;
use App\Combat\Fight\Simulation;
use App\Combat\WeaponClass;
use App\Models\Ammunition;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Weapon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The balance tool: many fights with the real engine and a seeded die, writing nothing.
 */
final class FightSimulationTest extends TestCase
{
    use RefreshDatabase;

    private function side(string $name, int $hp, float $damage, float $aim = 10.0, ?float $fleesAt = null): Combatant
    {
        return CombatantBuilder::fromSpec([
            'name' => $name, 'hp' => $hp, 'aim' => $aim, 'defence' => 10, 'pursuit' => 10, 'flees_at' => $fleesAt,
            'ranged' => ['class' => 'pistol', 'damage' => $damage],
            'melee' => ['class' => 'blade', 'damage' => $damage],
        ]);
    }

    private function sim(int $cap = 60): Simulation
    {
        return new Simulation(new FightEngine(CheckRoller::fromConfig()), $cap);
    }

    private function mitigation(): FightMitigation
    {
        return new FightMitigation(new NpcMitigation, new NpcMitigation);
    }

    public function test_the_same_seed_gives_the_same_answer_and_another_gives_another(): void
    {
        $opening = new FightState($this->side('You', 40, 6), $this->side('Them', 40, 6));

        $one = $this->sim()->run($opening, $this->mitigation(), 200, 1);

        $this->assertSame($one, $this->sim()->run($opening, $this->mitigation(), 200, 1));
        $this->assertNotSame($one['rounds'], $this->sim()->run($opening, $this->mitigation(), 200, 2)['rounds']);
    }

    public function test_every_fight_is_accounted_for(): void
    {
        $r = $this->sim()->run(new FightState($this->side('You', 40, 6), $this->side('Them', 40, 6)), $this->mitigation(), 300, 7);

        $this->assertSame(300, $r['wins'] + $r['losses'] + $r['escapes'] + $r['draws']);
        $this->assertSame(300, $r['runs']);
        $this->assertGreaterThan(0, $r['wins']);
        $this->assertGreaterThan(0, $r['losses']);
        $this->assertLessThanOrEqual(61, $r['rounds_max']);
        $this->assertGreaterThanOrEqual(1, $r['rounds_min']);
    }

    public function test_a_far_stronger_side_nearly_always_wins(): void
    {
        $r = $this->sim()->run(new FightState($this->side('You', 40, 40, aim: 25), $this->side('Them', 40, 1, aim: 2)), $this->mitigation(), 200, 3);

        $this->assertSame(200, $r['wins']);
        $this->assertLessThan(3, $r['rounds']);
        $this->assertLessThan(5.0, $r['hp_lost_on_wins']);
    }

    public function test_a_hopeless_one_loses(): void
    {
        $r = $this->sim()->run(new FightState($this->side('You', 10, 1, aim: 2), $this->side('Them', 80, 30, aim: 25)), $this->mitigation(), 200, 3);

        $this->assertSame(200, $r['losses']);
        $this->assertSame(0.0, $r['hp_lost_on_wins']);
    }

    public function test_an_opponent_who_loses_heart_gets_away_and_nobody_wins(): void
    {
        $r = $this->sim()->run(new FightState($this->side('You', 40, 5), $this->side('Them', 400, 1, fleesAt: 0.999)), $this->mitigation(), 100, 5);

        $this->assertGreaterThan(50, $r['escapes'], 'any scratch sends it running');
        $this->assertSame(0, $r['wins'], 'with 400 hit points nobody beats it before it leaves');
    }

    public function test_a_fight_that_never_ends_is_a_draw_and_not_a_loop(): void
    {
        $nothing = CombatantBuilder::fromSpec(['name' => 'Wall', 'hp' => 100000, 'melee' => ['class' => 'unarmed', 'damage' => 0]]);

        $r = $this->sim(cap: 4)->run(new FightState($this->side('You', 40, 1), $nothing), $this->mitigation(), 20, 1);

        $this->assertSame(20, $r['draws']);
    }

    public function test_the_simulated_player_attacks_loads_or_braces_in_that_order_of_preference(): void
    {
        $spec = fn (int $stock) => ['s' => new RoundSpec('s', 'S', 4.0, ['penetrating' => 100], 0, [], $stock)];
        $state = fn (?int $rounds, int $stock) => new FightState(
            new Combatant('You', 40, 10.0, 10.0, new Arm('Pistol', WeaponClass::Pistol, 10.0, 1, $spec($stock), $rounds > 0 ? 's' : null, $rounds, 12), null, 30, [], 40),
            $this->side('Them', 40, 1),
        );

        $this->assertSame('attack', $this->sim()->policy($state(5, 30))->kind->value, 'something to fire');
        $load = $this->sim()->policy($state(0, 30));
        $this->assertSame('load', $load->kind->value, 'empty, and rounds to put in');
        $this->assertSame('s', $load->round);
        $this->assertSame('brace', $this->sim()->policy($state(0, 0))->kind->value, 'empty and nothing to load');
    }

    public function test_a_shooter_who_is_closed_on_backs_off_unless_the_close_weapon_is_better(): void
    {
        $round = ['s' => new RoundSpec('s', 'S', 12.0, ['penetrating' => 100], 0, [], 30)];
        $hands = new Arm('Bare hands', WeaponClass::Unarmed, 10.0, 1, ['own' => new RoundSpec('own', 'Bare hands', 2.0, ['blunt' => 100])], 'own');
        $blade = new Arm('Knife', WeaponClass::Blade, 10.0, 1, ['own' => new RoundSpec('own', 'Knife', 15.0, ['slashing' => 100])], 'own');
        $at = fn (Arm $melee) => new FightState(
            new Combatant('You', 40, 10.0, 10.0, new Arm('Pistol', WeaponClass::Pistol, 10.0, 1, $round, 's', 5, 12), $melee, 30, [], 40),
            $this->side('Them', 40, 1),
            0,
        );

        $this->assertSame('create_distance', $this->sim()->policy($at($hands))->kind->value, 'a gun beats bare hands');
        $this->assertSame('attack', $this->sim()->policy($at($blade))->kind->value, 'a better knife is used');
    }

    public function test_it_does_not_change_the_state_it_was_given(): void
    {
        $opening = new FightState($this->side('You', 40, 6), $this->side('Them', 40, 6));
        $before = $opening->toArray();

        $this->sim()->run($opening, $this->mitigation(), 20, 1);

        $this->assertSame($before, $opening->toArray());
    }

    // ---- the command --------------------------------------------------------------------

    private function armed(): Character
    {
        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $gun = Item::factory()->physical()->create(['name' => 'Pistol', 'stackable' => false]);
        Weapon::create(['item_id' => $gun->id, 'class' => WeaponClass::Pistol, 'accuracy' => 0, 'rate' => 1, 'magazine' => 12, 'calibre' => '9mm']);
        $row = InventoryItem::query()->create(['character_id' => $character->id, 'item_id' => $gun->id, 'quantity' => 1]);
        $character->forceFill(['equipped_ranged_id' => $row->id])->save();

        $ammo = Item::factory()->physical()->create(['name' => '9mm FMJ', 'stackable' => true]);
        Ammunition::create(['item_id' => $ammo->id, 'calibre' => '9mm', 'type' => 'full_metal_jacket', 'damage' => 8, 'damage_profile' => ['penetrating' => 100], 'penetration' => 0]);
        InventoryItem::query()->create(['character_id' => $character->id, 'item_id' => $ammo->id, 'quantity' => 60]);
        $row->forceFill(['loaded_ammo_id' => $ammo->id, 'loaded_rounds' => 12])->save();

        return $character->fresh();
    }

    public function test_the_command_reports_the_odds_and_writes_nothing(): void
    {
        $character = $this->armed();
        $before = [$character->current_hitpoints, InventoryItem::query()->orderBy('id')->get(['id', 'quantity', 'loaded_rounds'])->toArray()];

        $this->artisan('fight:simulate', ['handle' => $character->handle, '--runs' => 40, '--seed' => 3])
            ->expectsOutputToContain('40 fights, enemy first')
            ->expectsOutputToContain('Win')
            ->expectsOutputToContain('the target is 4 to 10')
            ->assertExitCode(0);

        $this->assertSame($before, [$character->fresh()->current_hitpoints, InventoryItem::query()->orderBy('id')->get(['id', 'quantity', 'loaded_rounds'])->toArray()]);
    }

    public function test_the_command_is_repeatable_by_seed(): void
    {
        $character = $this->armed();
        $run = function (int $seed) use ($character): string {
            Artisan::call('fight:simulate', ['handle' => $character->handle, '--runs' => 60, '--seed' => $seed]);

            return Artisan::output();
        };

        $this->assertSame($run(4), $run(4));
    }

    public function test_the_command_takes_its_opponent_from_options(): void
    {
        $character = $this->armed();

        $weak = $this->runOutput($character, ['--foe-hp' => 5, '--foe-ranged' => 'none', '--foe-melee' => 'none']);
        $this->assertStringContainsString('100.0%', $weak, 'an unarmed target with five hit points');
        $this->artisan('fight:simulate', ['handle' => $character->handle, '--runs' => 10, '--foe-resist' => 'penetrating:100'])->assertExitCode(0);
    }

    private function runOutput(Character $character, array $options): string
    {
        Artisan::call('fight:simulate', ['handle' => $character->handle, '--runs' => 30] + $options);

        return Artisan::output();
    }

    public function test_the_command_refuses_an_unknown_character_and_an_unknown_class(): void
    {
        $this->artisan('fight:simulate', ['handle' => 'nobody'])->expectsOutputToContain('No character')->assertExitCode(1);

        $character = $this->armed();
        $this->expectException(\InvalidArgumentException::class);
        Artisan::call('fight:simulate', ['handle' => $character->handle, '--foe-ranged' => 'bazooka:5']);
    }
}
