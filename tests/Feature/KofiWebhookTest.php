<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Supporters\Supporters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Ko-fi's membership webhook: a month of Supporter from the moment a subscription payment arrives. */
final class KofiWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.kofi.token' => 'secret']);
    }

    /** @param array<string, mixed> $over */
    private function hook(array $over = [], string $token = 'secret'): TestResponse
    {
        return $this->post('/kofi_webhook', ['data' => json_encode(array_merge([
            'verification_token' => $token, 'message_id' => 'm1', 'type' => 'Subscription',
            'is_subscription_payment' => true, 'is_first_subscription_payment' => true,
            'email' => 'Payer@Example.com', 'tier_name' => 'Member',
        ], $over))]);
    }

    public function test_a_payment_makes_the_matching_account_a_supporter_for_a_month(): void
    {
        $user = User::factory()->create(['email' => 'payer@example.com']);

        $this->hook()->assertOk();

        $user->refresh();
        $this->assertTrue($user->isSupporter());
        $this->assertTrue($user->supporter_until->between(now()->addMonth()->subMinute(), now()->addMonth()->addMinute()));
    }

    public function test_a_retried_delivery_changes_nothing(): void
    {
        $user = User::factory()->create(['email' => 'payer@example.com']);
        $this->hook()->assertOk();
        $until = $user->fresh()->supporter_until;

        $this->travel(1)->days();
        $this->hook()->assertOk();

        $this->assertEquals($until, $user->fresh()->supporter_until);
        $this->assertDatabaseCount('kofi_payments', 1);
    }

    public function test_a_wrong_or_missing_token_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'payer@example.com']);

        $this->hook([], 'nope')->assertForbidden();
        config(['services.kofi.token' => '']);
        $this->hook([], '')->assertForbidden();

        $this->assertFalse($user->fresh()->isSupporter());
    }

    public function test_tips_and_unknown_emails_are_acknowledged_and_grant_nothing(): void
    {
        $user = User::factory()->create(['email' => 'payer@example.com']);

        $this->hook(['type' => 'Donation', 'is_subscription_payment' => false])->assertOk();
        $this->hook(['message_id' => 'm2', 'email' => 'nobody@example.com'])->assertOk();

        $this->assertFalse($user->fresh()->isSupporter());
        $this->assertDatabaseHas('kofi_payments', ['message_id' => 'm2', 'user_id' => null]);
    }

    public function test_a_lapsed_supporter_is_not_one_and_the_sweep_clears_the_flag(): void
    {
        $user = User::factory()->create(['email' => 'payer@example.com']);
        $this->hook()->assertOk();

        $this->travel(32)->days();
        $this->assertFalse($user->fresh()->isSupporter());

        $this->artisan('supporters:expire')->assertSuccessful();
        $this->assertFalse((bool) $user->fresh()->is_supporter);
    }

    public function test_a_payment_never_shortens_a_for_good_supporter(): void
    {
        $user = User::factory()->create(['email' => 'payer@example.com']);
        Supporters::make()->grant($user);

        $this->hook()->assertOk();

        $this->assertNull($user->fresh()->supporter_until);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user->fresh();
    }

    public function test_an_admin_connects_an_unmatched_payment_to_an_account(): void
    {
        $this->hook(['email' => 'other@example.com'])->assertOk();
        $user = User::factory()->create(['email' => 'real@example.com']);

        $this->actingAs($this->admin())->get(route('admin.supporters.index'))->assertSee('other@example.com');
        $this->actingAs($this->admin())->post(route('admin.supporters.connect'), ['kofi_email' => 'other@example.com', 'account' => 'Real@Example.com'])
            ->assertSessionHas('status');

        $this->assertTrue($user->fresh()->isSupporter());
        $this->assertDatabaseHas('kofi_payments', ['email' => 'other@example.com', 'user_id' => $user->id]);
    }

    public function test_connecting_an_old_payment_connects_it_but_grants_nothing(): void
    {
        $this->hook(['email' => 'other@example.com'])->assertOk();
        $user = User::factory()->create(['email' => 'real@example.com']);

        $this->travel(40)->days();
        $this->actingAs($this->admin())->post(route('admin.supporters.connect'), ['kofi_email' => 'other@example.com', 'account' => 'real@example.com']);

        $this->assertFalse($user->fresh()->isSupporter());
        $this->assertDatabaseHas('kofi_payments', ['user_id' => $user->id]);
    }

    public function test_an_unknown_account_is_refused_and_a_player_cannot_connect(): void
    {
        $this->hook(['email' => 'other@example.com'])->assertOk();

        $this->actingAs($this->admin())->post(route('admin.supporters.connect'), ['kofi_email' => 'other@example.com', 'account' => 'ghost@example.com'])
            ->assertSessionHasErrors('account');
        $this->actingAs(User::factory()->create())->post(route('admin.supporters.connect'), ['kofi_email' => 'other@example.com', 'account' => 'ghost@example.com'])
            ->assertForbidden();
    }

    public function test_the_history_lists_every_payment_tier_and_a_total_per_currency(): void
    {
        $user = User::factory()->create(['email' => 'payer@example.com']);
        $this->hook(['message_id' => 'a', 'amount' => '3.00', 'currency' => 'USD', 'tier_name' => 'Member'])->assertOk();
        $this->hook(['message_id' => 'b', 'amount' => '3.50', 'currency' => 'USD', 'tier_name' => 'Gold'])->assertOk();

        $this->actingAs($this->admin())->get(route('admin.supporters.history'))
            ->assertOk()->assertSee('payer@example.com')->assertSee('6.50 USD')->assertSee('Member, Gold')->assertSee('2 Payments');

        $this->actingAs(User::factory()->create())->get(route('admin.supporters.history'))->assertForbidden();
        $this->assertTrue($user->fresh()->isSupporter());
    }
}
