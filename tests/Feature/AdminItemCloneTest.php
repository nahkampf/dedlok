<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminItemCloneTest extends TestCase
{
    use RefreshDatabase;

    public function test_clone_opens_a_new_item_form_filled_from_the_original(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $item = Item::query()->create(['slug' => 'orig-thing', 'name' => 'Original Thing', 'base_cost' => 123, 'container' => Item::CONTAINER_DATA]);

        $this->actingAs($admin)->get(route('admin.items.create', ['clone' => $item->id]))
            ->assertOk()
            ->assertSee('Original Thing')
            ->assertSee(route('admin.items.store'), false)
            ->assertDontSee('orig-thing');

        $this->actingAs($admin)->get(route('admin.items.edit', $item))
            ->assertOk()
            ->assertSee(route('admin.items.create', ['clone' => $item->id]), false);
    }
}
