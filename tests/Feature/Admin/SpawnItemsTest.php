<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SpawnItemsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user->fresh();
    }

    public function test_ticked_items_land_in_the_stash_with_their_quantity(): void
    {
        $target = Character::factory()->create(['handle' => 'Target']);
        $item = Item::factory()->create();

        $this->actingAs($this->admin())->get(route('admin.spawn.index'))->assertOk();
        $this->actingAs($this->admin())
            ->post(route('admin.spawn.store'), ['handle' => 'Target', 'pick' => [$item->id], 'qty' => [$item->id => 3]])
            ->assertSessionHas('status');

        $this->assertSame(3, (int) InventoryItem::where('character_id', $target->id)->where('item_id', $item->id)->sum('quantity'));
    }

    public function test_an_unknown_handle_is_refused_and_a_player_is_kept_out(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->admin())->post(route('admin.spawn.store'), ['handle' => 'Nobody', 'pick' => [$item->id]])->assertSessionHasErrors('handle');
        $this->actingAs(User::factory()->create())->get(route('admin.spawn.index'))->assertForbidden();
    }
}
