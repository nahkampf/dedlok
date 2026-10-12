<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Flags;
use App\City\Encounters;
use App\City\EncounterStart;
use App\City\PrerequisiteKind;
use App\Models\Character;
use App\Models\Encounter;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use App\Standing\StandingLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** An encounter may ask the character to hold things before it can be drawn. Held, never spent. */
final class EncounterPrerequisitesTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private Encounter $encounter;

    protected function setUp(): void
    {
        parent::setUp();

        Encounter::query()->delete();
        $this->character = Character::factory()->create(['archetype' => 3, 'creds' => 100]);
        $this->encounter = Encounter::create([
            'slug' => 'gated', 'title' => 'Gated', 'starts_on' => 'travel', 'weight' => 100, 'once' => false, 'retired' => false,
        ]);
        $this->encounter->nodes()->create(['node_key' => 'start', 'body' => 'x', 'entry' => true, 'ends' => true]);
    }

    private function require(PrerequisiteKind $kind, array $columns = []): void
    {
        $this->encounter->prerequisites()->create(['kind' => $kind->value, 'position' => 0, 'negate' => false, ...$columns]);
    }

    private function eligible(): bool
    {
        return app(Encounters::class)->eligible($this->character->fresh(), EncounterStart::Travel, $this->character->district_id)->contains('id', $this->encounter->id);
    }

    public function test_no_prerequisites_is_no_condition(): void
    {
        $this->assertTrue($this->eligible());
    }

    public function test_a_flag_and_ten_items_must_both_hold(): void
    {
        $item = Item::factory()->create();
        $this->require(PrerequisiteKind::Flag, ['subject' => 'met_the_fixer']);
        $this->encounter->prerequisites()->create(['kind' => 'item', 'position' => 1, 'item_id' => $item->id, 'amount' => 10, 'negate' => false]);

        $this->assertFalse($this->eligible());

        Flags::set($this->character, 'met_the_fixer');
        $this->assertFalse($this->eligible(), 'one of two is not enough');

        InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 10]);
        $this->assertTrue($this->eligible());

        // Held and never spent.
        $this->assertSame(10, (int) InventoryItem::query()->where('character_id', $this->character->id)->sum('quantity'));
    }

    public function test_not_flips_a_row(): void
    {
        $this->require(PrerequisiteKind::Flag, ['subject' => 'burned_the_bridge', 'negate' => true]);

        $this->assertTrue($this->eligible());

        Flags::set($this->character, 'burned_the_bridge');
        $this->assertFalse($this->eligible());
    }

    public function test_creds_standing_and_district(): void
    {
        $this->require(PrerequisiteKind::Creds, ['amount' => 500]);
        $this->assertFalse($this->eligible());
        $this->character->forceFill(['creds' => 500])->save();
        $this->assertTrue($this->eligible());

        $this->encounter->prerequisites()->delete();
        $entity = Entity::query()->interactable()->firstOrFail();
        $this->encounter->prerequisites()->create(['kind' => 'standing', 'position' => 0, 'entity_id' => $entity->id, 'amount' => 5, 'negate' => false]);
        $this->assertFalse($this->eligible());
        $this->assertGreaterThanOrEqual(0, (new StandingLedger)->standingFor($this->character, $entity));

        $this->encounter->prerequisites()->delete();
        $this->require(PrerequisiteKind::District, ['subject' => $this->character->district->slug]);
        $this->assertTrue($this->eligible());
    }

    public function test_the_editor_saves_and_shows_prerequisites(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $item = Item::factory()->create();

        $payload = [
            'title' => 'Gated', 'starts_on' => 'travel', 'weight' => 10, 'once' => '1',
            'nodes' => [['key' => 'gated_a', 'body' => 'x', 'ends' => '1']],
            'entry' => 0,
            'prerequisites' => [
                ['kind' => 'flag', 'subject' => 'met_the_fixer'],
                ['kind' => 'item', 'item_id' => $item->id, 'amount' => 3, 'negate' => '1'],
                ['kind' => ''],
            ],
        ];

        $this->actingAs($admin)->put(route('admin.encounters.update', $this->encounter), $payload)->assertSessionHasNoErrors();

        $rows = $this->encounter->fresh()->prerequisites;
        $this->assertCount(2, $rows, 'the blank row is skipped');
        $this->assertSame('met_the_fixer', $rows[0]->subject);
        $this->assertTrue($rows[1]->negate);

        $this->actingAs($admin)->get(route('admin.encounters.edit', $this->encounter))->assertOk()->assertSee('met_the_fixer');
    }

    public function test_a_row_missing_what_it_needs_is_refused(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->put(route('admin.encounters.update', $this->encounter), [
            'title' => 'Gated', 'starts_on' => 'travel', 'weight' => 10,
            'nodes' => [['key' => 'gated_a', 'body' => 'x', 'ends' => '1']], 'entry' => 0,
            'prerequisites' => [['kind' => 'scan_files', 'amount' => '0']],
        ])->assertSessionHasErrors('prerequisites.0.amount');
    }
}
