<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Beta\BetaKeys;
use App\Models\BetaKey;
use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The alpha testing flag (`config/features.php`): no registering without a beta key, and an admin section that makes
 * and hands out the keys. Off, none of it is there.
 */
final class AlphaTestingTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $extra = [], string $email = 'tester@example.test')
    {
        return $this->post(route('register'), array_merge([
            'email' => $email,
            'password' => 'a-long-enough-password-1',
            'password_confirmation' => 'a-long-enough-password-1',
        ], $extra));
    }

    private function on(): void
    {
        config(['features.alpha_testing' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ---- the flag is off by default and off means untouched ---------------------------------------------

    public function test_the_flag_defaults_to_off(): void
    {
        $this->assertFalse((bool) config('features.alpha_testing'));
    }

    public function test_with_the_flag_off_registration_is_open_and_has_no_key_field(): void
    {
        $this->get(route('register'))->assertOk()->assertDontSee('name="code"', false);

        $this->register()->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'tester@example.test']);
    }

    public function test_with_the_flag_off_the_admin_section_is_not_there(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.beta_keys.index'))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.beta_keys.store'))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertDontSee(route('admin.beta_keys.index'), false);
        $this->assertSame(0, BetaKey::count());
    }

    // ---- registering ------------------------------------------------------------------------------------

    public function test_with_the_flag_on_the_form_asks_for_a_key(): void
    {
        $this->on();

        $this->get(route('register'))->assertOk()->assertSee('name="code"', false)->assertSee('Beta key');
    }

    public function test_no_key_or_a_wrong_one_means_no_account(): void
    {
        $this->on();
        (new BetaKeys)->generate();

        $this->register()->assertSessionHasErrors('code');
        $this->register(['code' => 'AAAA-BBBB-CCCC'])->assertSessionHasErrors('code');
        $this->register(['code' => 'nonsense'])->assertSessionHasErrors('code');

        $this->assertDatabaseMissing('users', ['email' => 'tester@example.test']);
    }

    public function test_a_valid_key_registers_and_is_spent_against_that_account(): void
    {
        $this->on();
        $key = (new BetaKeys)->generate();

        $this->register(['code' => $key->code])->assertRedirect();

        $user = User::query()->where('email', 'tester@example.test')->sole();
        $key->refresh();
        $this->assertTrue($key->isUsed());
        $this->assertSame($user->id, $key->user_id);
    }

    public function test_a_key_typed_in_lower_case_without_dashes_still_works(): void
    {
        $this->on();
        $key = (new BetaKeys)->generate();

        $this->register(['code' => '  '.strtolower(str_replace('-', '', $key->code)).' '])->assertSessionHasNoErrors();

        $this->assertTrue($key->fresh()->isUsed());
    }

    public function test_a_key_works_once_and_a_refused_registration_does_not_spend_it(): void
    {
        $this->on();
        $key = (new BetaKeys)->generate();

        // A bad email: refused, and the key is still good afterwards.
        $this->register(['code' => $key->code], 'not-an-email')->assertSessionHasErrors('email');
        $this->assertFalse($key->fresh()->isUsed());

        $this->register(['code' => $key->code])->assertSessionHasNoErrors();
        $this->assertTrue($key->fresh()->isUsed());

        // And nobody else gets it.
        $this->post(route('logout'));
        $this->register(['code' => $key->code], 'second@example.test')->assertSessionHasErrors('code');
        $this->assertDatabaseMissing('users', ['email' => 'second@example.test']);
    }

    public function test_deleting_the_account_does_not_make_the_key_valid_again(): void
    {
        $this->on();
        $key = (new BetaKeys)->generate();
        $this->register(['code' => $key->code])->assertSessionHasNoErrors();
        $this->assertTrue($key->fresh()->isUsed());

        User::query()->where('email', 'tester@example.test')->delete();

        $key->refresh();
        $this->assertNull($key->user_id);
        $this->assertTrue($key->isUsed(), 'spent stays spent');

        // Registering signed that tester in; the next person comes in fresh.
        $this->flushSession();
        Auth::forgetGuards();
        $this->register(['code' => $key->code], 'again@example.test')->assertSessionHasErrors('code');
    }

    // ---- the keys' own shape ----------------------------------------------------------------------------

    public function test_a_generated_key_is_three_groups_of_four_from_an_unambiguous_alphabet(): void
    {
        $this->on();

        foreach (range(1, 20) as $i) {
            $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}$/', (new BetaKeys)->generate()->code);
        }
    }

    // ---- the admin section ------------------------------------------------------------------------------

    public function test_only_an_admin_gets_in(): void
    {
        $this->on();

        $this->actingAs(User::factory()->create())->get(route('admin.beta_keys.index'))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->on();

        $this->get(route('admin.beta_keys.index'))->assertRedirect();
    }

    public function test_the_admin_generates_a_key_and_sees_it_listed(): void
    {
        $this->on();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.beta_keys.store'))->assertRedirect(route('admin.beta_keys.index'));

        $key = BetaKey::query()->sole();
        $this->actingAs($admin)->get(route('admin.beta_keys.index'))->assertOk()->assertSee($key->code)->assertSee('data-copy="'.$key->code.'"', false);
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertSee(route('admin.beta_keys.index'), false);
    }

    public function test_copying_marks_a_key_given_out_and_it_is_struck_through(): void
    {
        $this->on();
        $admin = $this->admin();
        $key = (new BetaKeys)->generate();

        $page = fn () => $this->actingAs($admin)->get(route('admin.beta_keys.index'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/beta-code strike/', $page());

        $this->actingAs($admin)->postJson(route('admin.beta_keys.given', $key))->assertOk();
        $first = $key->fresh()->given_out_at;
        $this->assertNotNull($first);
        $this->assertMatchesRegularExpression('/beta-code\s+strike/', $page());

        // Idempotent: copying it again does not move the moment.
        $this->actingAs($admin)->postJson(route('admin.beta_keys.given', $key))->assertOk();
        $this->assertEquals($first, $key->fresh()->given_out_at);
    }

    public function test_a_spent_key_shows_who_registered_and_their_character(): void
    {
        $this->on();
        $admin = $this->admin();
        $key = (new BetaKeys)->generate();
        $this->register(['code' => $key->code], 'named@example.test');
        $user = User::query()->where('email', 'named@example.test')->sole();

        $this->actingAs($admin)->get(route('admin.beta_keys.index'))->assertOk()
            ->assertSee('named@example.test')->assertSee('no character yet');

        Character::factory()->create(['user_id' => $user->id, 'handle' => 'ZeroCool', 'archetype' => 3]);

        $this->actingAs($admin)->get(route('admin.beta_keys.index'))->assertOk()
            ->assertSee('named@example.test')->assertSee('ZeroCool')->assertDontSee('no character yet');
    }

    public function test_only_an_admin_can_mark_a_key(): void
    {
        $this->on();
        $key = (new BetaKeys)->generate();

        $this->actingAs(User::factory()->create())->postJson(route('admin.beta_keys.given', $key))->assertForbidden();
        $this->assertNull($key->fresh()->given_out_at);
    }

    // ---- what to call them in the credits ---------------------------------------------------------------

    public function test_the_credit_name_is_asked_only_while_the_flag_is_on(): void
    {
        $this->get(route('register'))->assertOk()->assertDontSee('name="credit_name"', false);

        $this->on();
        $this->get(route('register'))->assertOk()->assertSee('name="credit_name"', false)->assertSee('What do we call you (for credits, on the thank you page)');
    }

    public function test_it_is_optional_trimmed_and_kept_on_the_account(): void
    {
        $this->on();

        $this->register(['code' => (new BetaKeys)->generate()->code, 'credit_name' => '  Ada L.  '], 'named@example.test')->assertSessionHasNoErrors();
        $this->assertSame('Ada L.', User::query()->where('email', 'named@example.test')->sole()->credit_name);

        $this->post(route('logout'));
        $this->register(['code' => (new BetaKeys)->generate()->code, 'credit_name' => '   '], 'blank@example.test')->assertSessionHasNoErrors();
        $this->assertNull(User::query()->where('email', 'blank@example.test')->sole()->credit_name);

        $this->post(route('logout'));
        $this->register(['code' => (new BetaKeys)->generate()->code], 'omitted@example.test')->assertSessionHasNoErrors();
        $this->assertNull(User::query()->where('email', 'omitted@example.test')->sole()->credit_name);
    }

    public function test_a_name_that_is_too_long_is_refused_and_does_not_spend_the_key(): void
    {
        $this->on();
        $key = (new BetaKeys)->generate();

        $this->register(['code' => $key->code, 'credit_name' => str_repeat('x', 101)])->assertSessionHasErrors('credit_name');

        $this->assertFalse($key->fresh()->isUsed());
        $this->assertDatabaseMissing('users', ['email' => 'tester@example.test']);
    }

    public function test_with_the_flag_off_a_posted_credit_name_is_ignored(): void
    {
        $this->register(['credit_name' => 'Sneaky'])->assertSessionHasNoErrors();

        $this->assertNull(User::query()->where('email', 'tester@example.test')->sole()->credit_name);
    }

    public function test_the_admin_list_shows_it_beside_the_email(): void
    {
        $this->on();
        $key = (new BetaKeys)->generate();
        $this->register(['code' => $key->code, 'credit_name' => 'Ada L.'], 'named@example.test');

        $this->actingAs($this->admin())->get(route('admin.beta_keys.index'))->assertOk()->assertSee('named@example.test')->assertSee('Ada L.');
    }
}
