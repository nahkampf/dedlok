<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Simulator;
use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\Skill;
use App\Stats\Sheet;
use App\Stats\SkillStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The four "<OS> Internals" skills: each level eases that one operating system's core by 1.5% of its difficulty. */
final class InternalsSkillsTest extends TestCase
{
    use RefreshDatabase;

    /** The suite migrates onto an empty skills table, so the skills these ask for are made first and the migration's work run. */
    private function migrate(): void
    {
        Skill::factory()->create(['slug' => 'cyberdecks', 'rank' => 1]);

        foreach (['auroos' => 2, 'prismv' => 4, 'ironclad' => 8, 'lattice' => 16] as $os => $rank) {
            Skill::factory()->create(['slug' => $os, 'title' => $os, 'category' => 'Operating Systems', 'rank' => $rank]);
        }

        (require database_path('migrations/2026_11_08_100000_the_internals_skills.php'))->seed();
        SkillStats::forget();
        Sheet::forgetSkills();
    }

    public function test_each_skill_is_built_as_asked(): void
    {
        $this->migrate();

        foreach (['auroos' => 4, 'prismv' => 4, 'ironclad' => 8, 'lattice' => 16] as $os => $rank) {
            $skill = Skill::query()->where('slug', $os.'_internals')->firstOrFail();

            $this->assertSame($rank, (int) $skill->rank, $os);
            $this->assertFalse((bool) $skill->on_market, $os);
            $this->assertSame(['auroos' => 3000, 'prismv' => 7500, 'ironclad' => 18000, 'lattice' => 60000][$os], (int) $skill->base_cost, $os);
            $this->assertSame(['memory', 'intellect'], [$skill->primary_attribute, $skill->secondary_attribute], $os);
            $this->assertNotSame('Operating Systems', $skill->category, 'that category is the grid\'s ladder of systems');
            $this->assertSame(
                ['cyberdecks' => 5, $os => 3],
                DB::table('skill_prerequisites')->join('skills', 'skills.id', '=', 'skill_prerequisites.required_skill_id')
                    ->where('skill_id', $skill->id)->pluck('required_level', 'skills.slug')->map(fn ($l): int => (int) $l)->all(),
                $os,
            );
            $this->assertCount(5, $skill->modifiers);
            $this->assertSame([-1.5], array_values(array_unique(array_column($skill->modifiers, 'amount'))));
            $this->assertSame('os_difficulty.'.$os, $skill->modifiers[0]['target']['id']);
        }
    }

    /** Level 5 of Lattice Internals takes 7.5% off Lattice's 35 and nothing off any other operating system. */
    public function test_a_trained_skill_eases_its_own_os_only(): void
    {
        $this->migrate();
        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $lattice = Skill::query()->where('slug', 'lattice')->firstOrFail();
        $auroos = Skill::query()->where('slug', 'auroos')->firstOrFail();
        $injector = app(SkillInjector::class);

        foreach ([$lattice, $auroos] as $os) {
            $injector->grant($character, $os, 5);
        }

        $at = fn (Skill $os): float => app(Simulator::class)->osChanceFor($character->fresh(), $os);
        $chance = fn (float $value, float $difficulty): float => 1 / (1 + exp(-($value - $difficulty) / 8));

        $bareLattice = $at($lattice);
        $bareAura = $at($auroos);

        $injector->grant($character, Skill::query()->where('slug', 'lattice_internals')->firstOrFail(), 5);

        $this->assertEqualsWithDelta($chance(35.1, 35 * 0.925), $at($lattice), 0.001);
        $this->assertGreaterThan($bareLattice, $at($lattice));
        $this->assertSame($bareAura, $at($auroos));
    }

    public function test_kernel_exploitation_leaves_the_operating_systems_category(): void
    {
        Skill::query()->where('slug', 'kernel_exploitation')->update(['category' => 'Operating Systems']);

        (require database_path('migrations/2026_11_08_110000_kernel_exploitation_is_not_an_operating_system.php'))->up();

        $this->assertSame('Intrusion', Skill::query()->where('slug', 'kernel_exploitation')->value('category'));
    }
}
