<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Combat\ArmourSlot;
use App\Combat\ArmourWearer;
use App\Models\Armour;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Stats\DamageStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penetration: how much of the target's RESISTANCE a hit ignores. docs/COMBAT.md, *Ammunition*.
 *
 * A resistance counts for `resistance × (1 − penetration / 100)`. Only a negative percentage is a
 * resistance: a vulnerability is not softened and an immunity is left alone.
 */
final class PenetrationTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private string $stat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->stat = DamageStats::id('current_hitpoints', DamageOrigin::Penetrating);
    }

    /**
     * Wear a plate on the torso carrying these modifiers (each against penetrating damage).
     *
     * @param  list<array<string, mixed>>  $rows  op, amount
     */
    private function wearPlate(array $rows): void
    {
        $item = Item::factory()->physical()->create([
            'stackable' => false,
            'modifiers' => array_map(fn (array $r): array => $r + ['target' => ['id' => $this->stat]], $rows),
        ]);
        Armour::create(['item_id' => $item->id, 'slots' => ['torso']]);
        $row = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);

        $this->assertTrue(app(ArmourWearer::class)->wear($this->character, $row));
    }

    private function resist(float $percent): array
    {
        return ['op' => 'percentage_of_base', 'amount' => $percent];
    }

    private function hit(int $amount, int $penetration = 0, ?ArmourSlot $at = ArmourSlot::Torso): int
    {
        return DamageResolver::make()
            ->preview($this->character->fresh(), Damage::hitpoints($amount, DamageOrigin::Penetrating, $at, $penetration))
            ->toInt();
    }

    public function test_no_penetration_is_the_ordinary_hit(): void
    {
        $this->wearPlate([$this->resist(-60)]);

        $this->assertSame(4, $this->hit(10), '60% off 10');
        $this->assertSame($this->hit(10), $this->hit(10, 0));
    }

    public function test_positive_penetration_ignores_that_share_of_the_resistance(): void
    {
        $this->wearPlate([$this->resist(-60)]);

        $this->assertSame(7, $this->hit(10, 50), 'half the vest: 30% off');
        $this->assertSame(10, $this->hit(10, 100), 'all of it');
    }

    public function test_negative_penetration_makes_the_resistance_count_more(): void
    {
        $this->wearPlate([$this->resist(-60)]);

        $this->assertSame(1, $this->hit(10, -50), '90% off');
        $this->assertSame(0, $this->hit(10, -100), '120% off clamps at nothing, not at healing');
    }

    public function test_resistances_are_summed_and_then_scaled(): void
    {
        $this->wearPlate([$this->resist(-30), $this->resist(-20)]);

        $this->assertSame(10, $this->hit(20), 'no penetration: 50% off 20');
        $this->assertSame(15, $this->hit(20, 50), 'each half as strong: 25% off 20');
    }

    public function test_a_vulnerability_is_not_softened(): void
    {
        $this->wearPlate([$this->resist(25)]);

        $this->assertSame(10, $this->hit(8), 'a quarter more');
        $this->assertSame(10, $this->hit(8, 50), 'penetration is about resistance, so nothing changed');
        $this->assertSame(10, $this->hit(8, -50));
    }

    public function test_an_immunity_is_left_alone(): void
    {
        $this->wearPlate([['op' => 'multiplier', 'amount' => 0]]);

        $this->assertSame(0, $this->hit(10));
        $this->assertSame(0, $this->hit(10, 100), 'no round pierces an immunity');
    }

    public function test_it_changes_nothing_where_the_armour_does_not_reach(): void
    {
        $this->wearPlate([$this->resist(-60)]);

        $this->assertSame(10, $this->hit(10, 0, ArmourSlot::LegLeft));
        $this->assertSame(10, $this->hit(10, 50, ArmourSlot::LegLeft), 'the plate was never on this leg');
        $this->assertSame(10, $this->hit(10, 50, null), 'a hit with no location never meets worn armour');
    }

    public function test_the_hit_that_lands_is_the_one_that_was_previewed(): void
    {
        $this->wearPlate([$this->resist(-60)]);
        $before = (int) $this->character->fresh()->current_hitpoints;

        $result = DamageResolver::make()->apply($this->character->fresh(), Damage::hitpoints(10, DamageOrigin::Penetrating, ArmourSlot::Torso, 50));

        $this->assertSame(7, $result->resolved);
        $this->assertSame($before - 7, (int) $this->character->fresh()->current_hitpoints);
    }

    public function test_penetration_is_a_percent_and_nothing_else(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Damage::hitpoints(10, DamageOrigin::Penetrating, null, 101);
    }

    /** ICE has never passed one, and must not start: its fights are the cyberspace engine's own. */
    public function test_ice_and_every_older_source_still_meet_the_whole_resistance(): void
    {
        $this->assertSame(0, Damage::synapticIntegrity(5, DamageOrigin::Ice)->penetration);
        $this->assertSame(0, Damage::hitpoints(5, DamageOrigin::Overdose)->penetration);

        foreach (['app/Cyberspace', 'app/Drugs', 'app/City', 'app/Characters'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir))) as $file) {
                if ($file->getExtension() === 'php') {
                    $this->assertStringNotContainsStringIgnoringCase('penetration', (string) file_get_contents($file->getPathname()), $file->getPathname());
                }
            }
        }
    }
}
