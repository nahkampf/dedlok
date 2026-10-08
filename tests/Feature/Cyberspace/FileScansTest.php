<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Models\Character;
use App\Models\Skill;
use App\Models\SystemScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FileScansTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_files_finished_reads_once_and_leaves_unfinished_ones(): void
    {
        $character = Character::factory()->create();
        $os = Skill::factory()->create(['slug' => 'lattice', 'rank' => 1]);
        $base = ['character_id' => $character->id, 'x' => 1, 'y' => 1, 'passes' => 1, 'scanned_at' => now(), 'expires_at' => now()->addDay()];

        SystemScan::query()->create($base + ['address' => 'AAAA0001', 'grade' => 2, 'os_skill_id' => $os->id, 'ids_detection' => 0, 'tracer_detection' => 0, 'tracer_rate' => 0, 'ice_level' => 0]);
        SystemScan::query()->create($base + ['address' => 'AAAA0002', 'grade' => 2]);

        $this->artisan('scans:file', ['handle' => $character->handle])->expectsOutputToContain('1 filed.')->assertSuccessful();
        $this->artisan('scans:file', ['handle' => $character->handle])->expectsOutputToContain('0 filed.')->assertSuccessful();

        $this->assertSame(1, $character->inventory()->whereNotNull('scan_file')->count());
    }
}
