<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Support\PatchNotes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PATCHNOTES.md in a modal on the front page, opened by itself for what a
 * player has not read, and remembered when it is closed.
 */
final class PatchNotesTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'notes');
        file_put_contents($this->file, <<<'MD'
            ## 0.9.0 (2026-09-01)

            Nine.

            ## 0.10.0 (2026-09-20):

            Ten, <script>alert(1)</script> **bold**.

            ## not a release

            Still ten.
            MD);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_notes_are_read_newest_first_by_version_not_by_string(): void
    {
        $notes = PatchNotes::all($this->file);

        $this->assertSame(['0.10.0', '0.9.0'], array_column($notes, 'version'));
        $this->assertSame('2026-09-20', $notes[0]['date']);
        $this->assertStringContainsString('<strong>bold</strong>', $notes[0]['html']);
        $this->assertStringContainsString('Still ten.', $notes[0]['html']);
        $this->assertStringNotContainsString('<script', $notes[0]['html']);
    }

    public function test_everything_since_the_last_one_read_and_nothing_for_null(): void
    {
        $notes = PatchNotes::all($this->file);

        $this->assertSame([], PatchNotes::unseen($notes, null));
        $this->assertSame(['0.10.0', '0.9.0'], array_column(PatchNotes::unseen($notes, '0.0.0'), 'version'));
        $this->assertSame(['0.10.0', '0.9.0'], array_column(PatchNotes::unseen($notes, '0.8.2'), 'version'));
        $this->assertSame(['0.10.0'], array_column(PatchNotes::unseen($notes, '0.9.0'), 'version'));
        $this->assertSame([], PatchNotes::unseen($notes, '0.10.0'));
    }

    public function test_the_front_page_opens_what_a_player_has_not_read(): void
    {
        $latest = PatchNotes::latest() ?? $this->markTestSkipped('PATCHNOTES.md has no release.');
        $user = $this->player();
        $user->forceFill(['last_seen_changelog' => '0.0.0'])->save();

        $this->actingAs($user)->get(route('home'))
            ->assertOk()
            ->assertSee('id="patch-notes-new"', false)
            ->assertSee('data-show-on-load', false)
            ->assertSee('data-open="#patch-notes"', false);

        $user->forceFill(['last_seen_changelog' => $latest])->save();

        $this->actingAs($user)->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-show-on-load', false)
            ->assertSee('id="patch-notes"', false);
    }

    public function test_a_new_player_starts_caught_up(): void
    {
        $this->post(route('register'), [
            'email' => 'new@example.test',
            'password' => 'a-long-enough-password-1',
            'password_confirmation' => 'a-long-enough-password-1',
        ]);

        $user = User::query()->where('email', 'new@example.test')->sole();

        $this->assertSame(PatchNotes::latest(), $user->last_seen_changelog);
        $this->assertSame([], PatchNotes::unseen(PatchNotes::all(), $user->last_seen_changelog));
    }

    public function test_an_account_nobody_could_place_is_shown_nothing(): void
    {
        $this->actingAs($this->player())->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-show-on-load', false);
    }

    public function test_nobody_signed_out_is_shown_it(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-show-on-load', false);
    }

    public function test_closing_it_remembers_the_newest_release_and_never_goes_back(): void
    {
        $latest = PatchNotes::latest() ?? $this->markTestSkipped('PATCHNOTES.md has no release.');
        $user = $this->player();

        $this->actingAs($user)->post(route('patch-notes.seen'))->assertNoContent();
        $this->assertSame($latest, $user->fresh()->last_seen_changelog);

        $user->forceFill(['last_seen_changelog' => '99.0.0'])->save();
        $this->actingAs($user)->post(route('patch-notes.seen'))->assertNoContent();
        $this->assertSame('99.0.0', $user->fresh()->last_seen_changelog);
    }

    public function test_marking_it_seen_needs_an_account(): void
    {
        $this->post(route('patch-notes.seen'))->assertRedirect(route('login'));
    }

    private function player(): User
    {
        return Character::factory()->create()->user;
    }
}
