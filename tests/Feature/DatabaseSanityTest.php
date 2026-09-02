<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSanityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_against_an_isolated_database(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        $user = User::factory()->create(['locale' => 'en']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'locale' => 'en']);
    }
}
