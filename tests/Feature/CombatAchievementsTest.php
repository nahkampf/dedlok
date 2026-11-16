<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Characters\Flags;
use App\Checks\CheckRoller;
use App\Combat\Fight\Action;
use App\Combat\Fight\Arm;
use App\Combat\Fight\Combatant;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\FightState;
use App\Combat\Fight\RoundSpec;
use App\Combat\Fight\Side;
use App\Combat\WeaponClass;
use App\Models\Character;
use App\Models\User;
use App\Wallet\Transfers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MapMitigation;
use Tests\Support\QueueDice;
use Tests\TestCase;

/** The new counters: the engine tags what `Fights` counts, and the set-based ones count different things. */
final class CombatAchievementsTest extends TestCase
{
    use RefreshDatabase;

    private function character(int $creds = 0): Character
    {
        return Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3, 'creds' => $creds]));
    }

    private function knife(array $riders = []): Arm
    {
        return new Arm('Knife', WeaponClass::Blade, 10.0, 1, ['s' => new RoundSpec('s', 'S', 10.0, ['slashing' => 100], 0, $riders)], 's');
    }

    private function foe(float $hp): FightState
    {
        return new FightState(
            new Combatant('You', 100, 10.0, melee: $this->knife([['kind' => 'burning', 'chance' => 100, 'turns' => 3, 'amount' => 1]]), si: 30),
            new Combatant('Gang member', $hp, 10.0, melee: $this->knife()),
            distance: 0,
            first: Side::A,
        );
    }

    private function round(FightState $state, array $dice): FightState
    {
        return (new FightEngine(CheckRoller::fromConfig()))->round($state, ['a' => Action::attack(), 'b' => Action::brace()], new QueueDice($dice), new MapMitigation);
    }

    public function test_a_rider_on_the_foe_is_tagged_for_the_achievements(): void
    {
        $state = $this->round($this->foe(100), [0.0, 0.5, 0.0]);

        $this->assertSame(['burning'], array_values(array_column($state->log, 'ach')));
    }

    public function test_only_a_headshot_that_kills_is_tagged(): void
    {
        $parts = (array) config('combat.body_parts');
        $before = array_sum(array_slice($parts, 0, (int) array_search('head', array_keys($parts), true)));
        $u = ($before + 0.5) / array_sum($parts);

        $hurt = $this->round($this->foe(100), [0.0, $u, 0.9, 0.9]);
        $this->assertNotContains('headshot_kill', array_column($hurt->log, 'ach'));

        $killed = $this->round($this->foe(1), [0.0, $u, 0.9, 0.9]);
        $this->assertContains('headshot_kill', array_column($killed->log, 'ach'));
    }

    public function test_remember_counts_different_things_once(): void
    {
        $c = $this->character();
        $a = new Achievements;

        $this->assertSame(1, $a->remember($c, Metric::DrugsTried, 5));
        $this->assertSame(1, $a->remember($c, Metric::DrugsTried, 5));
        $this->assertSame(2, $a->remember($c, Metric::DrugsTried, 6));
    }

    public function test_rainmaker_needs_enough_people_and_enough_credits(): void
    {
        config(['achievements.rainmaker_credits' => 100]);
        $a = new Achievements;
        $payer = $this->character(1000);

        foreach (range(1, 3) as $i) {
            (new Transfers)->send($payer, $this->character()->handle, 10);
        }
        $this->assertSame(0, $a->progressFor($payer, Metric::Rainmaker), 'three people, thirty credits: not yet');

        (new Transfers)->send($payer, $this->character()->handle, 100);
        $this->assertSame(4, $a->progressFor($payer, Metric::Rainmaker), 'past the line, the people already paid count');
    }

    public function test_setting_the_njord_flag_frees_njordy_once(): void
    {
        $c = $this->character();

        $this->assertTrue(Flags::set($c, 'njord_released'));
        $this->assertFalse(Flags::set($c, 'njord_released'));

        $this->assertSame(1, (new Achievements)->progressFor($c, Metric::NjordReleased));
        $this->assertSame([37], (new Achievements)->unlockedFor(User::query()->findOrFail($c->user_id)));
    }
}
