<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Drugs\Family;
use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\DrugUse;
use App\Models\Skill;
use App\Stats\Sheet;
use App\Stats\SkillStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `addiction_recovery`: points of addiction off every family's book each minute, zero for everybody until an effect carries it.
 */
final class AddictionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function hooked(float $by): Character
    {
        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        DrugUse::query()->create(['character_id' => $character->id, 'family' => Family::Opiate, 'addiction' => $by, 'tolerance' => 0]);

        return $character;
    }

    private function carrying(Character $character, float $perMinute): void
    {
        SkillStats::forget();
        Sheet::forgetSkills();
        $skill = Skill::factory()->governedBy('focus', 'intellect')->create([
            'slug' => 'detox', 'title' => 'Detox', 'category' => 'Physical', 'rank' => 4,
            'modifiers' => [['op' => 'add', 'level' => 1, 'amount' => $perMinute, 'target' => ['id' => 'addiction_recovery']]],
        ]);
        SkillStats::forget();
        Sheet::forgetSkills();
        app(SkillInjector::class)->grant($character, $skill, 1);
    }

    public function test_nobody_recovers_without_the_stat(): void
    {
        $character = $this->hooked(5.0);

        $this->artisan('characters:regenerate')->assertSuccessful();

        $this->assertEquals(5.0, DrugUse::query()->where('character_id', $character->id)->value('addiction'));
    }

    public function test_a_tenth_a_minute_comes_off_and_the_book_goes_at_nothing(): void
    {
        $character = $this->hooked(0.25);
        $this->carrying($character, 0.1);

        $this->artisan('characters:regenerate')->assertSuccessful();
        $this->assertEquals(0.15, DrugUse::query()->where('character_id', $character->id)->value('addiction'));

        $this->artisan('characters:regenerate')->assertSuccessful();
        $this->artisan('characters:regenerate')->assertSuccessful();
        $this->assertSame(0, DrugUse::query()->where('character_id', $character->id)->count());
    }
}
