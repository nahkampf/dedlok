<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterModifier;
use App\Stats\ModifierMemo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** `Character::modifiers()` is remembered inside a web request, and a write (any kind) makes it forget. */
final class ModifierMemoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ModifierMemo::disable();

        parent::tearDown();
    }

    public function test_it_is_off_unless_a_request_turned_it_on(): void
    {
        $character = Character::factory()->create(['archetype' => 3]);

        DB::enableQueryLog();
        $character->modifiers();
        $first = count(DB::getQueryLog());
        $character->modifiers();

        $this->assertGreaterThan($first, count(DB::getQueryLog()), 'a second call queried again');
    }

    public function test_the_second_call_costs_nothing_and_a_write_makes_it_forget(): void
    {
        ModifierMemo::enable();
        $character = Character::factory()->create(['archetype' => 3]);
        $before = count($character->modifiers());

        DB::enableQueryLog();
        $character->modifiers();
        $this->assertSame([], DB::getQueryLog(), 'remembered');

        // A query-builder write, which no model event would announce.
        DB::table('character_modifiers')->insert(['character_id' => $character->id, 'definition_key' => 'withdrawal_opiate', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertGreaterThan($before, count($character->modifiers()), 'and a write was seen');
        $this->assertSame(1, CharacterModifier::query()->count());
    }

    public function test_a_what_if_is_never_remembered(): void
    {
        ModifierMemo::enable();
        $character = Character::factory()->create(['archetype' => 3]);
        $character->modifiers();

        $character->assumedStopped = [1];
        DB::enableQueryLog();
        $character->modifiers();

        $this->assertNotSame([], DB::getQueryLog());
    }
}
