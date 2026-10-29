<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Support\Ansi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AnsiBioTest extends TestCase
{
    use RefreshDatabase;

    private const E = "\x1b[";

    public function test_text_without_escapes_is_only_escaped(): void
    {
        $this->assertSame('a &lt;b&gt;  x', Ansi::render('a <b>  x'));
        $this->assertFalse(Ansi::in('plain'));
    }

    public function test_colours_use_the_dos_numbering_and_bold_brightens_in_either_order(): void
    {
        $this->assertSame('<span class="a-f4">x</span>', Ansi::render(self::E.'31mx'));
        $this->assertSame('<span class="a-f12">x</span>', Ansi::render(self::E.'1;31mx'));
        $this->assertSame('<span class="a-f12">x</span>', Ansi::render(self::E.'31;1mx'));
        $this->assertSame('<span class="a-f14 a-b1">x</span>', Ansi::render(self::E.'1;33;44mx'));
        $this->assertSame('x', Ansi::render(self::E.'0;1;31m'.self::E.'mx'), 'reset returns to the plain default');
    }

    public function test_blink_and_ice(): void
    {
        $this->assertSame('<span class="a-b2 a-bl">x</span>', Ansi::render(self::E.'5;42mx'));
        $this->assertSame('<span class="a-b10">x</span>', Ansi::render(self::E.'5;42mx', ice: true), 'iCE: bright background, no blink');
        $this->assertSame('<span class="a-b10">x</span>', Ansi::render(self::E.'42;5mx', ice: true), 'in either order');
    }

    public function test_other_parameters_and_sequences_are_dropped(): void
    {
        $this->assertSame('x', Ansi::render(self::E.'2J'.self::E.'7;4;9mx'.self::E.'?7h'));
    }

    public function test_cursor_forward_is_unpainted_blank_cells(): void
    {
        $this->assertSame('<span class="a-b1">a</span>   <span class="a-b1">b</span>', Ansi::render(self::E.'44ma'.self::E.'3Cb'));
    }

    public function test_the_cursor_moves_and_a_later_write_replaces_what_was_there(): void
    {
        $this->assertSame("-b\nc", Ansi::render(self::E.'mab'.self::E.'2D'.'-'.self::E.'1B'.self::E.'2D'.'c'), 'back and down');
        $this->assertSame('- b', Ansi::render(self::E.'ma'.self::E.'1D'.'-'.self::E.'1C'.'b'), 'forward does not paint');
        $this->assertSame("a\n\n\nz", Ansi::render(self::E.'ma'.self::E.'1;1H'.self::E.'3B'.'z'.self::E.'H'.'a'), 'absolute position');
        $this->assertSame("x\ny", Ansi::render(self::E.'mx'.self::E.'s'."\n".'y'.self::E.'u'), 'save and restore');
        $this->assertSame("12\nxy", Ansi::render(self::E.'m1'."\n".'xy'.self::E.'1A'.self::E.'2D'.self::E.'1C'.'2'), 'up');
    }

    public function test_it_wraps_at_80_but_a_newline_after_a_full_line_is_not_a_blank_row(): void
    {
        $full = str_repeat('x', 80);
        $this->assertSame($full."\nx", Ansi::render(self::E.'mx'.substr($full, 1).'x'));
        $this->assertSame($full."\nx", Ansi::render(self::E.'m'.$full."\nx"));
    }

    public function test_a_sauce_record_is_cut_and_markup_stays_text(): void
    {
        $this->assertSame('x', Ansi::render(self::E.'m'."x\x1aSAUCE00"));
        $this->assertSame('&lt;script&gt;', Ansi::render(self::E.'m<script>'));
    }

    public function test_an_uploaded_file_is_converted_from_cp437_and_attached_on_a_new_line(): void
    {
        $character = Character::factory()->create(['bio' => 'Hello']);
        $file = UploadedFile::fake()->createWithContent('a.ans', "\x1b[1;31m\xdb\xb0\xe1\r\n\x1b[0m\xa0\x1aSAUCE");

        $this->actingAs($character->user)->post(route('character.bio.ansi'), ['ansi' => $file])->assertSessionHasNoErrors();

        $this->assertSame("Hello\n\x1b[1;31m█░ß\n\x1b[0má", $character->fresh()->bio);
    }

    /** The flag is ANSiFlags (offset 105 of the record) bit 0, on a character file (DataType 1). */
    public function test_the_sauce_ice_flag_is_read_then_the_record_is_cut(): void
    {
        $sauce = fn (int $flags): string => "\x1a".str_pad('SAUCE00'.str_repeat(' ', 87)."\x01\x01".str_repeat("\0", 9).chr($flags), 127, "\0")."\0";

        $character = Character::factory()->create();
        $this->actingAs($character->user)->post(route('character.bio.ansi'), ['ansi' => UploadedFile::fake()->createWithContent('a.ans', "\x1b[5;42mx".$sauce(1))]);
        $this->assertTrue($character->fresh()->bio_ice);
        $this->assertSame("\x1b[5;42mx", $character->fresh()->bio);

        $character->forceFill(['bio' => null])->save();
        $this->actingAs($character->user)->post(route('character.bio.ansi'), ['ansi' => UploadedFile::fake()->createWithContent('a.ans', "\x1b[5;42mx".$sauce(2))]);
        $this->assertFalse($character->fresh()->bio_ice, 'letter spacing bits are not iCE');

        $this->actingAs($character->user)->from(route('character.index'))->post(route('character.bio'), ['bio' => '']);
        $this->assertFalse($character->fresh()->bio_ice);
    }

    public function test_the_preview_draws_the_box_as_the_profile_would_and_writes_nothing(): void
    {
        $character = Character::factory()->create(['bio' => 'saved', 'bio_ice' => true]);

        $html = $this->actingAs($character->user)->post(route('character.bio.preview'), ['bio' => "\x1b[5;42m<b>x\r\n"])->assertOk()->getContent();

        $this->assertStringContainsString('class="ascii ansi"', $html);
        $this->assertStringContainsString('<span class="a-b10">&lt;b&gt;x</span>', $html, 'escaped, and the stored iCE flag applies');
        $this->assertSame('saved', $character->fresh()->bio);

        $this->actingAs($character->user)->post(route('character.bio.preview'), ['bio' => 'plain'])->assertSee('<pre class="ascii" style="margin:0">plain</pre>', false);
        $this->actingAs($character->user)->post(route('character.bio.preview'), ['bio' => str_repeat('a', 65_001)])->assertSessionHasErrors('bio');
        $this->post(route('logout'));
        $this->post(route('character.bio.preview'), ['bio' => 'x'])->assertRedirect(route('login'));
    }

    public function test_a_file_without_colour_is_refused(): void
    {
        $character = Character::factory()->create();
        $file = UploadedFile::fake()->createWithContent('a.ans', 'just text');

        $this->actingAs($character->user)->post(route('character.bio.ansi'), ['ansi' => $file])->assertSessionHasErrors('ansi');

        $this->assertNull($character->fresh()->bio);
    }
}
