<?php

namespace Tests\Feature\Admin;

use App\Models\Character;
use App\Models\Item;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who can reach the admin tools, and who cannot.
 *
 * The gate is one line, so what is worth testing is everything AROUND it: that
 * the flag cannot be granted over HTTP, that a player who is merely signed in
 * gets nothing, and that RequireCharacter — which redirects every signed-in
 * user without a character — does not swallow these routes on the way past.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{string, string}> */
    public static function adminRoutes(): array
    {
        return [
            'dashboard' => ['get', '/admin'],
            'item list' => ['get', '/admin/items'],
            'new item' => ['get', '/admin/items/create'],
            'skill list' => ['get', '/admin/skills'],
            'new skill' => ['get', '/admin/skills/create'],
            // Archetype 1 is inserted by the migration, so this path exists in
            // every environment the schema does. There is no create route:
            // archetype ids are permanent and the roster arrives by migration.
            'archetype list' => ['get', '/admin/archetypes'],
            'edit archetype' => ['get', '/admin/archetypes/1/edit'],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_a_guest_is_sent_to_the_login_screen(string $method, string $path): void
    {
        $this->{$method}($path)->assertRedirect('/login');
    }

    #[DataProvider('adminRoutes')]
    public function test_a_signed_in_player_is_refused(string $method, string $path): void
    {
        $user = User::factory()->create();
        Character::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->{$method}($path)->assertForbidden();
    }

    #[DataProvider('adminRoutes')]
    public function test_an_admin_gets_through(string $method, string $path): void
    {
        $user = User::factory()->admin()->create();
        Character::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->{$method}($path)->assertOk();
    }

    /**
     * The tools edit the game, not a character, so an admin who has never
     * played must not be bounced into the creation form on the way to them.
     */
    public function test_an_admin_without_a_character_is_not_redirected_to_creation(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get('/admin')->assertOk();
        $this->actingAs($user)->get('/admin/items')->assertOk();
    }

    /** And a player without a character is still sent there, admin routes or not. */
    public function test_a_player_without_a_character_still_goes_to_creation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/inventory')
            ->assertRedirect(route('character.create'));
    }

    /**
     * The flag is outside #[Fillable] precisely so this cannot happen, however
     * the payload is shaped.
     */
    public function test_the_admin_flag_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();

        $user->fill(['is_admin' => true])->save();

        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_the_grant_command_promotes_and_demotes(): void
    {
        $user = User::factory()->create(['email' => 'fixer@arasaka.net']);

        $this->artisan('admin:grant', ['email' => 'fixer@arasaka.net'])->assertSuccessful();
        $this->assertTrue($user->fresh()->isAdmin());

        $this->artisan('admin:grant', ['email' => 'fixer@arasaka.net', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_the_grant_command_refuses_an_unknown_account(): void
    {
        $this->artisan('admin:grant', ['email' => 'nobody@nowhere.net'])->assertFailed();
    }

    /** The nav entry is the door; it should only be visible to those with a key. */
    public function test_the_admin_link_shows_only_for_admins(): void
    {
        $player = User::factory()->create();
        Character::factory()->create(['user_id' => $player->id]);

        $admin = User::factory()->admin()->create();
        Character::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($player)->get('/inventory')->assertDontSee(route('admin.index'));
        $this->actingAs($admin)->get('/inventory')->assertSee(route('admin.index'));
    }

    /** Editing rows is a write, so it needs the gate as much as reading does. */
    public function test_a_player_cannot_write_content(): void
    {
        $user = User::factory()->create();
        Character::factory()->create(['user_id' => $user->id]);

        $item = Item::factory()->create();
        $skill = Skill::factory()->create();

        $this->actingAs($user)->post('/admin/items', [])->assertForbidden();
        $this->actingAs($user)->delete("/admin/items/{$item->id}")->assertForbidden();
        $this->actingAs($user)->post('/admin/skills', [])->assertForbidden();
        $this->actingAs($user)->delete("/admin/skills/{$skill->slug}")->assertForbidden();

        $this->assertDatabaseHas('items', ['id' => $item->id]);
        $this->assertDatabaseHas('skills', ['id' => $skill->id]);
    }
}
