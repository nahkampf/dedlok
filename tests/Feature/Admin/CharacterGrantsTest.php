<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Character;
use App\Models\User;
use App\Models\WalletEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The admin's grants: creds through the wallet, and a refill of HP or SI to the maximum. */
final class CharacterGrantsTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user->fresh();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3, 'creds' => 100, 'handle' => 'Target']));
    }

    public function test_creds_are_given_through_the_wallet(): void
    {
        $this->actingAs($this->admin())->post(route('admin.characters.creds', $this->character), ['amount' => 3000])->assertSessionHas('status');

        $this->assertSame(3100, $this->character->fresh()->creds);
        $entry = WalletEntry::where('character_id', $this->character->id)->latest('id')->first();
        $this->assertSame([3000, 3100], [$entry->amount, $entry->balance]);
    }

    public function test_a_refill_goes_to_the_current_maximum_and_no_further(): void
    {
        $this->character->forceFill(['current_hitpoints' => 1, 'current_synaptic_integrity' => 1])->save();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.characters.refill', [$this->character, 'hitpoints']))->assertSessionHas('status');
        $fresh = $this->character->fresh();
        $this->assertSame($fresh->maximumFor('current_hitpoints'), $fresh->current_hitpoints);
        $this->assertSame(1, $fresh->current_synaptic_integrity);

        $this->actingAs($admin)->post(route('admin.characters.refill', [$this->character, 'si']));
        $fresh = $this->character->fresh();
        $this->assertSame($fresh->maximumFor('current_synaptic_integrity'), $fresh->current_synaptic_integrity);
    }

    public function test_bad_amounts_unknown_resources_and_players_are_refused(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.characters.creds', $this->character), ['amount' => 0])->assertSessionHasErrors('amount');
        $this->actingAs($admin)->post(route('admin.characters.refill', [$this->character, 'nope']))->assertNotFound();
        $this->actingAs(User::factory()->create())->post(route('admin.characters.creds', $this->character), ['amount' => 5])->assertForbidden();

        $this->assertSame(100, $this->character->fresh()->creds);
        $this->actingAs($admin)->get(route('admin.characters.index', ['q' => 'Targ']))->assertOk()->assertSee('Target');
    }
}
