<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/** The routing chain box on the cyberspace screen: what the next run would route through, best first, capped by skill. */
final class RoutingChainBoxTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
    }

    private function hold(int $n, int $grade, BackdoorKind $kind = BackdoorKind::Routing): System
    {
        $system = System::factory()->at($n, 1)->grade($grade)->create(['address' => 'RELAY00'.$n, 'name' => 'Relay '.$n]);
        (new Backdoors)->install($this->character->fresh(), $system, $kind);

        return $system;
    }

    private function train(int $level): void
    {
        $skill = Skill::factory()->rank(4)->governedBy('intellect', 'memory')->create([
            'slug' => 'a_routing_skill',
            'modifiers' => array_map(static fn (int $at): array => ['op' => 'add', 'level' => $at, 'amount' => 1, 'target' => ['id' => 'routing_hops']], range(1, 5)),
        ]);
        CharacterSkill::query()->create(['character_id' => $this->character->id, 'skill_id' => $skill->id, 'attained_level' => $level, 'skill_points' => 0]);
    }

    private function page(): string
    {
        return $this->actingAs($this->character->user->fresh())->get(route('cyberspace.index'))->assertOk()->getContent();
    }

    public function test_an_untrained_character_is_told_they_cannot_route(): void
    {
        $this->hold(1, 5);

        $html = $this->page();

        $this->assertStringContainsString('Routing chain', $html);
        $this->assertStringContainsString('You cannot route a run. Routing is the skill for it.', $html);
        $this->assertStringNotContainsString('Relay 1', $html);
    }

    public function test_the_best_systems_are_listed_first_and_only_as_many_as_the_skills_allow(): void
    {
        $this->train(2);
        $this->hold(1, 3);
        $this->hold(2, 9);
        $this->hold(3, 6);
        $this->hold(4, 8, BackdoorKind::Siphon);   // a siphon is not a hop

        $html = $this->page();

        $this->assertMatchesRegularExpression('#Relay 2.*Grade 9.*Relay 3.*Grade 6#s', $html, 'highest grade first');
        $this->assertStringNotContainsString('Relay 1', $html, 'cut by the two hops the skill allows');
        $this->assertStringNotContainsString('Relay 4', $html);
        $this->assertStringContainsString('2/2 hops', $html);
    }

    public function test_trained_with_nothing_held_says_so(): void
    {
        $this->train(3);

        $html = $this->page();

        $this->assertStringContainsString('Nothing. A trace comes straight at you.', $html);
        $this->assertStringContainsString('0/3 hops', $html);
    }
}
