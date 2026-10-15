<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\ContentKind;
use App\Cyberspace\FortressRaid;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\Item;
use App\Models\Run;
use App\Models\System;
use App\Models\SystemContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

final class FortressRaidTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    public function test_a_raid_pulls_files_by_itself_until_the_vault_is_empty(): void
    {
        Item::query()->firstOrCreate(['slug' => 'encrypted-data-dump'], ['name' => 'Encrypted Data Dump', 'container' => 'data', 'stackable' => false]);
        $owner = Character::factory()->create();
        $raider = Character::factory()->create(array_fill_keys(Character::ATTRIBUTES, 20));
        $this->equipReadyDeck($raider);
        $fortress = System::factory()->at(3, 3)->create(['type' => SystemType::Fortress, 'owner_character_id' => $owner->id, 'address' => 'F0F0F0F0']);

        foreach ([1.0, 1.0, 1.0] as $i => $size) {
            SystemContent::query()->create(['system_id' => $fortress->id, 'kind' => ContentKind::Data->value, 'label' => "File {$i}", 'size_qb' => $size, 'value_sealed' => 10, 'value_broken' => 20]);
        }

        $run = Run::query()->create(['character_id' => $raider->id, 'address' => 'F0F0F0F0', 'x' => 3, 'y' => 3, 'purpose' => 'extraction', 'turn' => 0, 'started_at' => now()]);
        $run->pull_at = now()->subSeconds(60);
        $run->save();

        $result = (new FortressRaid(fn (int $min, int $max): int => $min))->catchUp($raider->fresh(), $run->fresh());

        $this->assertTrue($result['took']);
        $this->assertSame(0, SystemContent::query()->where('system_id', $fortress->id)->count());
        $this->assertSame(3, $raider->inventory()->whereNotNull('extracted_file')->count());
    }
}
