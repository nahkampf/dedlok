<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Checks\CheckRoller;
use App\Cyberspace\Probe;
use App\Cyberspace\Swarm;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemScan;
use App\Programs\ProcessRefusal;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Stats\Sheet;
use App\Stats\SkillStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Wotan: a program that scans the main grid by itself, one whole system after another.
 */
final class SwarmTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    private ?Skill $os = null;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        SkillStats::forget();
        Sheet::forgetSkills();

        Skill::factory()->governedBy('perception', 'memory')->create(['slug' => 'systemprofiling', 'title' => 'System Profiling', 'rank' => 1]);

        SkillStats::forget();
        Sheet::forgetSkills();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
    }

    private function wotan(): InventoryItem
    {
        $row = $this->ownedBy($this->character, Item::factory()->program(
            ProgramKind::Utility,
            qflops: 2,
            memory: 1,
            storage: 0.5,
            background: true,
            abilities: ['swarms' => true, 'multithreaded' => true],
        )->create());

        return $row;
    }

    private function startIt(InventoryItem $row)
    {
        return app(ProgramRunner::class)->start($this->character->fresh(), $row);
    }

    private function system(int $x, string $address, array $with = []): System
    {
        $this->os ??= Skill::factory()->create(['slug' => 'auroos', 'title' => 'AuraOS', 'category' => 'Operating Systems', 'rank' => 2]);

        return System::factory()->at($x, 3)->create(['address' => $address, 'grade' => 1, 'os_skill_id' => $this->os->id] + $with);
    }

    private function sure(): void
    {
        // Every roll passes, so a read completes in one pass.
        $this->app->singleton(Probe::class, fn () => Probe::fromConfig(CheckRoller::fromConfig(static fn (): float => 0.0)));
    }

    public function test_starting_wotan_points_it_at_a_system_and_triples_the_time(): void
    {
        $this->system(1, 'AAAA0001');
        $row = $this->wotan();

        $this->assertTrue($this->startIt($row)->succeeded());

        $scan = SystemScan::query()->sole();
        $this->assertSame('AAAA0001', $scan->address);

        $seconds = $scan->pass_started_at->diffInSeconds($scan->pass_ready_at);
        $by_hand = Probe::fromConfig()->durationFor($this->character->fresh(), System::query()->first());

        $this->assertSame($by_hand * 3, (int) $seconds);
    }

    public function test_it_reads_a_system_to_completion_then_moves_on_without_announcing_it(): void
    {
        $this->sure();
        $this->system(1, 'AAAA0001');
        $this->system(2, 'AAAA0002');
        $this->startIt($this->wotan());

        $first = SystemScan::query()->sole();
        $fatigue = $this->character->fresh()->fatigue ?? null;

        $this->travel(10)->minutes();
        app(Probe::class)->settleAll($this->character->fresh());

        $done = SystemScan::query()->where('address', $first->address)->first();
        $this->assertTrue($done->isComplete());

        // It has gone on to the other system, and there is nothing left to read after that.
        $this->assertSame(2, SystemScan::query()->count());
        $this->assertSame(0, (new Achievements)->progressFor($this->character, Metric::SystemsScanned));
        $this->assertSame(0, Notification::query()->count());
        $this->assertSame($fatigue, $this->character->fresh()->fatigue ?? null);
    }

    public function test_it_skips_what_is_already_known_and_what_is_being_read(): void
    {
        $this->system(1, 'AAAA0001');
        $this->system(2, 'AAAA0002');
        $this->system(3, 'AAAA0003');

        SystemScan::query()->create([
            'character_id' => $this->character->id, 'address' => 'AAAA0001', 'grid_id' => 1, 'x' => 1, 'y' => 3, 'passes' => 1,
            'grade' => 1, 'os_skill_id' => $this->os->id, 'ids_detection' => 0, 'tracer_detection' => 0, 'tracer_rate' => 0, 'ice_level' => 0, 'ice_bounty' => false,
            'scanned_at' => now(), 'expires_at' => now()->addHours(5),
        ]);
        $held = SystemScan::query()->where('address', 'AAAA0001')->first();
        $this->assertTrue($held->isComplete(), 'the fixture has to be a whole scan');

        $this->ownedBy($this->character, Item::query()->where('slug', 'scan-file')->firstOrFail())->forceFill([
            'scan_file' => ['address' => 'AAAA0002', 'expires_at' => now()->addHour()->format(DATE_ATOM)],
        ])->save();

        $picked = Swarm::make()->pick($this->character->fresh());

        $this->assertSame('AAAA0003', $picked?->address);
    }

    public function test_it_never_picks_a_fortress_the_portal_or_a_subnet(): void
    {
        $this->system(1, 'AAAA0001', ['type' => SystemType::Fortress]);
        $this->system(2, 'AAAA0002', ['type' => SystemType::Portal]);

        $this->assertNull(Swarm::make()->pick($this->character->fresh()));
    }

    public function test_it_will_not_start_in_a_subnet(): void
    {
        $row = $this->wotan();
        $this->character->forceFill(['grid_id' => 2, 'grid_x' => 1, 'grid_y' => 1])->save();

        $result = $this->startIt($row);

        $this->assertFalse($result->succeeded());
        $this->assertSame(ProcessRefusal::SwarmSubnet, $result->refusal);
    }

    public function test_two_copies_take_two_different_systems(): void
    {
        Config::set('effects.threading', ['label' => 'Threading', 'stacking' => 'unique', 'retired' => false, 'modifiers' => [['op' => 'add', 'amount' => 1, 'target' => ['id' => 'program_threads']]]]);
        CharacterModifier::query()->create(['character_id' => $this->character->id, 'definition_key' => 'threading']);

        $this->system(1, 'AAAA0001');
        $this->system(2, 'AAAA0002');
        $row = $this->wotan();
        $row->forceFill(['quantity' => 2])->save();

        $this->assertTrue($this->startIt($row)->succeeded());
        $this->assertTrue($this->startIt($row->fresh())->succeeded());

        $this->assertSame(2, SystemScan::query()->count());
        $this->assertSame(2, SystemScan::query()->distinct()->count('address'));
        $this->assertSame(2, SystemScan::query()->distinct()->count('reading_process_id'));
    }

    public function test_stopping_it_voids_the_read_and_nothing_picks_up_until_it_runs_again(): void
    {
        $this->sure();
        $this->system(1, 'AAAA0001');
        $row = $this->wotan();
        $this->startIt($row);

        $running = InventoryItem::query()->whereNotNull('running_on_deck_id')->first();
        app(ProgramRunner::class)->stop($this->character->fresh(), $running);

        $this->travel(10)->minutes();
        app(Probe::class)->settleAll($this->character->fresh());

        $this->assertFalse(SystemScan::query()->first()->isComplete());
    }
}
