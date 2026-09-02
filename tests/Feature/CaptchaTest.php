<?php

namespace Tests\Feature;

use App\Captcha\Cap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Js;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cap guards the three unauthenticated POSTs: signing in, registering and
 * asking for a reset link.
 *
 * The widget is only a challenge. What actually protects anything is this
 * application refusing a submission whose token /siteverify has not blessed,
 * so most of what is asserted here is what happens when that answer is no,
 * missing, or never arrives.
 */
class CaptchaTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://cap.example.test/sitekey123/';

    private const VERIFY_URL = 'https://cap.example.test/sitekey123/siteverify';

    /** @return list<array{0: string, 1: array<string, string>}> */
    public static function protectedRoutes(): array
    {
        return [
            'login' => ['login', ['email' => 'runner@example.test', 'password' => 'password']],
            'register' => ['register', [
                'email' => 'runner@example.test',
                'password' => 'Str0ng-passphrase!',
                'password_confirmation' => 'Str0ng-passphrase!',
            ]],
            'password reset request' => ['forgot-password', ['email' => 'runner@example.test']],
        ];
    }

    private function enableCap(): void
    {
        config([
            'cap.enabled' => true,
            'cap.endpoint' => self::ENDPOINT,
            // Pinned, not left to fall through: verification must land on the
            // faked URL whatever the developer's .env says.
            'cap.verify_endpoint' => null,
            'cap.secret' => 'site-key-secret',
        ]);
    }

    private function capAnswers(bool $success): void
    {
        Http::fake([self::VERIFY_URL => Http::response(['success' => $success])]);
    }

    /**
     * What the page must literally contain for a value to reach the browser.
     *
     * @js does not emit raw JSON -- a string becomes a quoted JS literal with
     * its slashes escaped, an array becomes JSON.parse('...') -- so the value
     * is put through the same encoder rather than matched as a substring.
     */
    private function asEmitted(mixed $value): string
    {
        return (string) Js::from($value);
    }

    // ---- switched off -----------------------------------------------------

    #[DataProvider('protectedRoutes')]
    public function test_nothing_is_required_while_cap_is_off(string $uri, array $payload): void
    {
        Http::fake();   // any call at all would be a bug

        $this->post($uri, $payload)->assertSessionDoesntHaveErrors('cap-token');

        Http::assertNothingSent();
    }

    public function test_the_widget_is_absent_from_the_forms_while_cap_is_off(): void
    {
        foreach (['login', 'register', 'forgot-password'] as $uri) {
            $response = $this->get($uri);

            $response->assertDontSee('class="captcha"', false);
            $response->assertDontSee('CAP_ENDPOINT', false);
        }
    }

    // ---- switched on ------------------------------------------------------

    #[DataProvider('protectedRoutes')]
    public function test_a_submission_without_a_token_is_refused(string $uri, array $payload): void
    {
        $this->enableCap();
        Http::fake();

        $this->post($uri, $payload)->assertSessionHasErrors('cap-token');

        // No point asking the server about a token we never received.
        Http::assertNothingSent();
    }

    #[DataProvider('protectedRoutes')]
    public function test_a_token_the_server_rejects_is_refused(string $uri, array $payload): void
    {
        $this->enableCap();
        $this->capAnswers(false);

        $this->post($uri, $payload + ['cap-token' => 'forged'])
            ->assertSessionHasErrors('cap-token');
    }

    #[DataProvider('protectedRoutes')]
    public function test_a_token_the_server_accepts_gets_through(string $uri, array $payload): void
    {
        $this->enableCap();
        $this->capAnswers(true);

        $this->post($uri, $payload + ['cap-token' => 'genuine'])
            ->assertSessionDoesntHaveErrors('cap-token');
    }

    public function test_the_widget_renders_on_all_three_forms_when_cap_is_on(): void
    {
        $this->enableCap();

        foreach (['login', 'register', 'forgot-password'] as $uri) {
            $response = $this->get($uri);

            $response->assertSee('class="captcha"', false);
            $response->assertSee('assets/js/cap-widget.js', false);
            $response->assertSee('window.CAP_ENDPOINT = '.$this->asEmitted(self::ENDPOINT), false);
        }
    }

    /**
     * Every label the widget shows is a lang key, so the widget translates
     * with the rest of the screen instead of staying in English.
     */
    public function test_the_widget_labels_come_from_lang_not_from_cap(): void
    {
        $this->enableCap();

        $labels = __('captcha.widget');

        $this->get('login')->assertSee(
            'window.CAP_I18N = '.$this->asEmitted($labels),
            false
        );

        $this->assertSame("Confirm you're human", $labels['initial-state']);

        // Every key is a real data-cap-i18n-* attribute suffix; ui-kit.js
        // copies them across without knowing what any of them mean.
        $this->assertArrayHasKey('required-label', $labels);
        $this->assertArrayHasKey('verified-aria-label', $labels);
    }

    // ---- the verification call itself -------------------------------------

    public function test_the_secret_is_sent_to_the_server_and_never_to_the_browser(): void
    {
        $this->enableCap();
        $this->capAnswers(true);

        $this->post('login', ['email' => 'a@b.test', 'password' => 'x', 'cap-token' => 'genuine']);

        Http::assertSent(fn (Request $request) => $request->url() === self::VERIFY_URL
            && $request['secret'] === 'site-key-secret'
            && $request['response'] === 'genuine');

        $this->get('login')->assertDontSee('site-key-secret', false);
    }

    // ---- failing closed ---------------------------------------------------

    public function test_an_unreachable_cap_server_refuses_rather_than_waves_through(): void
    {
        $this->enableCap();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->post('login', ['email' => 'a@b.test', 'password' => 'x', 'cap-token' => 'genuine'])
            ->assertSessionHasErrors('cap-token');
    }

    public function test_a_server_error_refuses_rather_than_waves_through(): void
    {
        $this->enableCap();
        Http::fake([self::VERIFY_URL => Http::response('', 500)]);

        $this->post('login', ['email' => 'a@b.test', 'password' => 'x', 'cap-token' => 'genuine'])
            ->assertSessionHasErrors('cap-token');
    }

    /**
     * The dangerous misconfiguration: the switch is on, so the operator
     * believes they are protected, but no credentials were supplied. Silently
     * behaving like a site with no captcha is the one outcome not allowed.
     */
    public function test_enabled_without_a_secret_refuses_every_submission(): void
    {
        config(['cap.enabled' => true, 'cap.endpoint' => self::ENDPOINT, 'cap.secret' => null]);
        Http::fake();

        $this->post('login', ['email' => 'a@b.test', 'password' => 'x', 'cap-token' => 'genuine'])
            ->assertSessionHasErrors('cap-token');

        Http::assertNothingSent();
    }

    // ---- blast radius -----------------------------------------------------

    public function test_routes_outside_the_protect_list_are_untouched(): void
    {
        $this->enableCap();
        Http::fake();

        // Not in config('cap.protect'), so it must not grow a captcha.
        $this->post(route('locale.update'), ['locale' => 'en'])
            ->assertSessionDoesntHaveErrors('cap-token');

        Http::assertNothingSent();
    }

    public function test_registration_actually_completes_behind_the_captcha(): void
    {
        $this->enableCap();
        $this->capAnswers(true);

        $this->post('register', [
            'email' => 'newblood@example.test',
            'password' => 'Str0ng-passphrase!',
            'password_confirmation' => 'Str0ng-passphrase!',
            'cap-token' => 'genuine',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'newblood@example.test']);
    }

    public function test_registration_is_blocked_without_a_token(): void
    {
        $this->enableCap();
        Http::fake();

        $this->post('register', [
            'email' => 'newblood@example.test',
            'password' => 'Str0ng-passphrase!',
            'password_confirmation' => 'Str0ng-passphrase!',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'newblood@example.test']);
    }

    public function test_login_is_blocked_without_a_token_even_with_the_right_password(): void
    {
        $this->enableCap();
        Http::fake();

        $user = User::factory()->create(['password' => bcrypt('correct-horse')]);

        $this->post('login', ['email' => $user->email, 'password' => 'correct-horse'])
            ->assertSessionHasErrors('cap-token');

        $this->assertGuest();
    }

    // ---- configuration ----------------------------------------------------

    /**
     * The widget and its WASM are served by us, not by a CDN. Cap Standalone
     * does not ship the widget, and the whole point of self-hosting is that no
     * third party sees a solve — a jsDelivr default would quietly undo it.
     */
    public function test_the_widget_and_its_wasm_are_served_locally(): void
    {
        config(['cap.widget_script' => null, 'cap.wasm_url' => null]);

        $this->assertSame(asset('assets/js/cap-widget.js'), Cap::widgetScript());
        $this->assertSame(asset('assets/wasm/cap_wasm_bg.wasm'), Cap::wasmUrl());

        $this->assertFileExists(public_path('assets/js/cap-widget.js'));
        $this->assertFileExists(public_path('assets/wasm/cap_wasm_bg.wasm'));
    }

    public function test_the_page_points_the_widget_at_the_local_wasm(): void
    {
        $this->enableCap();

        $this->get('login')
            ->assertSee('window.CAP_CUSTOM_WASM_URL = '.$this->asEmitted(Cap::wasmUrl()), false);
    }

    public function test_explicit_urls_win(): void
    {
        config([
            'cap.widget_script' => 'https://cdn.example.test/cap.js',
            'cap.wasm_url' => 'https://cdn.example.test/cap.wasm',
        ]);

        $this->assertSame('https://cdn.example.test/cap.js', Cap::widgetScript());
        $this->assertSame('https://cdn.example.test/cap.wasm', Cap::wasmUrl());
    }

    public function test_the_endpoint_is_normalised_to_one_trailing_slash(): void
    {
        config(['cap.endpoint' => 'https://cap.example.test/sitekey123']);
        $this->assertSame(self::ENDPOINT, Cap::endpoint());

        config(['cap.endpoint' => 'https://cap.example.test/sitekey123///']);
        $this->assertSame(self::ENDPOINT, Cap::endpoint());
    }

    public function test_verification_can_use_a_different_url_from_the_widget(): void
    {
        // The shape the bundled Docker service actually has: the browser
        // reaches Cap on a published port, the web container by service name.
        config([
            'cap.enabled' => true,
            'cap.endpoint' => 'http://localhost:3010/sitekey123/',
            'cap.verify_endpoint' => 'http://cap:3000/sitekey123/',
            'cap.secret' => 'site-key-secret',
        ]);
        Http::fake(['http://cap:3000/sitekey123/siteverify' => Http::response(['success' => true])]);

        $response = $this->get('login');

        // The page advertises the URL the player can resolve...
        $response->assertSee('window.CAP_ENDPOINT = '.$this->asEmitted('http://localhost:3010/sitekey123/'), false);
        $response->assertDontSee('cap:3000', false);

        // ...while the server hop goes to the one it can.
        $this->post('login', ['email' => 'a@b.test', 'password' => 'x', 'cap-token' => 'genuine'])
            ->assertSessionDoesntHaveErrors('cap-token');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://cap:3000/sitekey123/siteverify');
    }

    public function test_verification_falls_back_to_the_widget_endpoint(): void
    {
        config(['cap.endpoint' => self::ENDPOINT, 'cap.verify_endpoint' => null]);

        $this->assertSame(self::ENDPOINT, Cap::verifyEndpoint());
    }

    public function test_an_unconfigured_endpoint_is_null_rather_than_a_broken_url(): void
    {
        config(['cap.endpoint' => '', 'cap.verify_endpoint' => null]);

        $this->assertNull(Cap::endpoint());
        $this->assertNull(Cap::verifyEndpoint());
    }

    public function test_a_blank_token_is_refused_without_asking_the_server(): void
    {
        $this->enableCap();
        Http::fake();

        $this->assertFalse(Cap::verify(null));
        $this->assertFalse(Cap::verify(''));
        $this->assertFalse(Cap::verify('   '));

        Http::assertNothingSent();
    }
}
