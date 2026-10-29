<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The migration that makes five entities' desks buy components: each pair once, at half value, skipping what is missing
 * and what is already listed.
 */
final class ExchangesBuyComponentsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require base_path('database/migrations/2026_11_03_100000_exchanges_buy_components.php');
    }

    public function test_it_lists_each_pair_once_and_leaves_an_existing_listing_alone(): void
    {
        $golden = Entity::query()->where('name', 'Golden Path')->value('id');
        $record = Item::query()->where('slug', 'customer-record')->value('id');
        DB::table('entity_buys')->insert(['entity_id' => $golden, 'item_id' => $record, 'min_grade' => 2, 'max_grade' => 3, 'percent' => 90, 'created_at' => now(), 'updated_at' => now()]);

        $this->migration()->seed();
        $this->migration()->seed();

        $this->assertSame(20, DB::table('entity_buys')->count(), 'twenty pairs, none twice');
        $this->assertSame(90, (int) DB::table('entity_buys')->where('entity_id', $golden)->where('item_id', $record)->value('percent'), 'the earlier listing stands');

        $project = DB::table('entity_buys')->where('entity_id', $golden)->where('item_id', Item::query()->where('slug', 'project-file')->value('id'))->first();
        $this->assertSame([1, 5, 50, null], [(int) $project->min_grade, (int) $project->max_grade, (int) $project->percent, $project->max_quantity]);
    }

    public function test_down_removes_only_what_it_listed_at_its_own_price(): void
    {
        $this->migration()->seed();
        DB::table('entity_buys')->where('entity_id', Entity::query()->where('name', 'Lockjaw')->value('id'))
            ->where('item_id', Item::query()->where('slug', 'security-profile')->value('id'))->update(['percent' => 80]);

        $this->migration()->down();

        $this->assertSame(1, DB::table('entity_buys')->count(), 'a listing at another price is not ours to remove');
    }
}
