<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_table_has_the_expected_shape(): void
    {
        $columns = Schema::getColumnListing('settings');
        sort($columns);

        $this->assertSame(['category', 'created_at', 'key', 'updated_at', 'value'], $columns);
    }

    public function test_keys_are_unique(): void
    {
        Setting::create(['key' => 'motd', 'value' => 'first']);

        $this->expectException(QueryException::class);
        Setting::create(['key' => 'motd', 'value' => 'second']);
    }

    public function test_it_reads_and_writes_by_key(): void
    {
        Setting::put('motd', 'The city is awake.');

        $this->assertSame('The city is awake.', Setting::get('motd'));
        $this->assertTrue(Setting::has('motd'));
    }

    public function test_put_overwrites_rather_than_duplicating(): void
    {
        Setting::put('motd', 'first');
        Setting::put('motd', 'second');

        $this->assertSame('second', Setting::get('motd'));
        $this->assertDatabaseCount('settings', 1);
    }

    public function test_a_missing_key_returns_the_default(): void
    {
        $this->assertNull(Setting::get('nothing'));
        $this->assertSame('fallback', Setting::get('nothing', 'fallback'));
        $this->assertFalse(Setting::has('nothing'));
    }

    public function test_a_null_value_is_distinct_from_a_missing_key(): void
    {
        Setting::put('unset', null);

        $this->assertTrue(Setting::has('unset'));
        $this->assertNull(Setting::get('unset'));
        // The default must not fire for a key that exists with a null value.
        $this->assertNull(Setting::get('unset', 'fallback'));
    }

    public function test_reads_are_served_from_cache(): void
    {
        Setting::put('motd', 'cached');
        Setting::cached();                       // warm

        DB::enableQueryLog();
        for ($i = 0; $i < 20; $i++) {
            Setting::get('motd');
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], array_filter(
            $queries,
            fn (array $q) => str_contains($q['query'], 'settings')
        ));
    }

    public function test_writing_busts_the_cache(): void
    {
        Setting::put('motd', 'first');
        $this->assertSame('first', Setting::get('motd'));

        Setting::put('motd', 'second');
        $this->assertSame('second', Setting::get('motd'));

        Setting::where('key', 'motd')->first()->delete();
        $this->assertFalse(Setting::has('motd'));
    }

    public function test_query_builder_writes_are_stale_until_the_ttl_or_a_flush(): void
    {
        Setting::put('motd', 'first');
        $this->assertSame('first', Setting::get('motd'));

        // No model events fire for this, so the cache stays warm.
        DB::table('settings')->where('key', 'motd')->update(['value' => 'second']);
        $this->assertSame('first', Setting::get('motd'), 'stale until the TTL lapses — expected');

        Setting::flushCache();
        $this->assertSame('second', Setting::get('motd'));
    }

    public function test_the_ttl_recovers_from_a_bypassed_write(): void
    {
        Setting::put('motd', 'first');
        Setting::get('motd');

        DB::table('settings')->where('key', 'motd')->update(['value' => 'second']);

        $this->travel(Setting::CACHE_TTL_MINUTES + 1)->minutes();

        $this->assertSame('second', Setting::get('motd'));
    }

    public function test_it_does_not_shadow_eloquents_all(): void
    {
        Setting::put('a', '1');

        // Model::all() must still behave like Eloquent's, returning models.
        $all = Setting::all();

        $this->assertInstanceOf(Collection::class, $all);
        $this->assertInstanceOf(Setting::class, $all->first());
        $this->assertSame('a', $all->first()->key);
    }

    public function test_a_setting_can_be_stored_in_a_category(): void
    {
        Setting::put('combat.crit_multiplier', '2', 'combat');

        $this->assertSame('combat', Setting::where('key', 'combat.crit_multiplier')->first()->category);
        $this->assertSame(['combat.crit_multiplier' => '2'], Setting::inCategory('combat'));
    }

    public function test_in_category_groups_settings(): void
    {
        Setting::put('combat.crit', '2', 'combat');
        Setting::put('combat.dodge', '5', 'combat');
        Setting::put('economy.tax', '10', 'economy');
        Setting::put('loose', 'x');

        $this->assertSame(['combat.crit' => '2', 'combat.dodge' => '5'], Setting::inCategory('combat'));
        $this->assertSame(['economy.tax' => '10'], Setting::inCategory('economy'));
        $this->assertSame([], Setting::inCategory('nothing'));
    }

    public function test_null_and_empty_categories_are_the_same_group(): void
    {
        Setting::put('a', '1');                 // category never set -> null
        Setting::put('b', '2', '');             // explicitly empty
        Setting::put('c', '3', 'grouped');

        $ungrouped = Setting::inCategory(null);

        $this->assertSame(['a' => '1', 'b' => '2'], $ungrouped);
        $this->assertSame($ungrouped, Setting::inCategory(''));
    }

    public function test_categories_lists_the_groups_in_use(): void
    {
        Setting::put('a', '1', 'combat');
        Setting::put('b', '2', 'economy');
        Setting::put('c', '3', 'combat');
        Setting::put('d', '4');
        Setting::put('e', '5', '');

        $this->assertSame(['combat', 'economy'], Setting::categories());
    }

    public function test_put_leaves_an_existing_category_alone(): void
    {
        Setting::put('motd', 'first', 'display');
        Setting::put('motd', 'second');

        $this->assertSame('second', Setting::get('motd'));
        $this->assertSame('display', Setting::where('key', 'motd')->first()->category);
    }

    public function test_a_category_can_be_cleared_with_an_empty_string(): void
    {
        Setting::put('motd', 'x', 'display');
        Setting::put('motd', 'x', '');

        $this->assertSame('', Setting::where('key', 'motd')->first()->category);
        $this->assertArrayHasKey('motd', Setting::inCategory(null));
    }

    public function test_category_reads_come_from_the_same_cache_as_values(): void
    {
        Setting::put('a', '1', 'combat');
        Setting::cached();

        DB::enableQueryLog();
        Setting::inCategory('combat');
        Setting::categories();
        Setting::get('a');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], array_filter(
            $queries,
            fn (array $q) => str_contains($q['query'], 'settings')
        ));
    }

    public function test_reserved_word_key_survives_a_round_trip(): void
    {
        Setting::put('key', 'a setting literally named key');

        $this->assertSame('a setting literally named key', Setting::get('key'));
        $this->assertDatabaseHas('settings', ['key' => 'key']);
    }
}
