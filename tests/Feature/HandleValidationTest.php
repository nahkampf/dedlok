<?php

namespace Tests\Feature;

use App\Models\SuggestedHandle;
use App\Models\User;
use App\Rules\ValidHandle;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HandleValidationTest extends TestCase
{
    use RefreshDatabase;

    public static function allowedHandles(): array
    {
        return [
            'ascii alphanumeric' => ['zer0c00l'],
            'latin accented' => ['Ñeo'],
            'cyrillic' => ['Кирилл'],
            'greek' => ['Ωmega'],
            'card suits' => ['♠ace♠'],
            'box drawing' => ['▓blok▓'],
            'angle brackets' => ['a<b>c'],
            'comma and tilde' => ['one,two~three'],
            'maths symbols' => ['∑∆∏'],
            'arrows' => ['←↑→↓'],
            'superscripts' => ['x²y³'],
            'currency' => ['¢£¥¤'],
            'decomposed accent' => ["e\u{0301}clair"],
            // Newly permitted punctuation set
            'underscore' => ['dark_ether'],
            'caret' => ['^warP^'],
            'hyphen' => ['BLOK-M'],
            'square brackets' => ['d[O_o]b'],
            'semicolon' => ['semi;colon'],
            'single quote' => ["o'brien"],
            'backslash' => ['back\\slash'],
            'forward slash' => ['a/b'],
            'dot and colon' => ['v.2:0'],
            'pipe and bang' => ['a|b!'],
            'hash dollar percent' => ['n#1$50%'],
            'ampersand parens' => ['a&b(c)'],
            'backtick and acute' => ['`x´'],
            'path traversal shape' => ['../etc'],
        ];
    }

    public static function rejectedHandles(): array
    {
        return [
            'space' => ['has space'],
            'tab' => ["a\tb"],
            'newline' => ["a\nb"],
            'double quote' => ['quote"x'],
            'at sign' => ['user@host'],
            'question mark' => ['who?'],
            'curly braces' => ['{brace}'],
            'empty' => [''],
            'emoji' => ['fire🔥'],
            'zero width joiner' => ["a\u{200D}b"],
            'rtl override' => ["a\u{202E}b"],
            'soft hyphen' => ["a\u{00AD}b"],
        ];
    }

    #[DataProvider('allowedHandles')]
    public function test_it_allows(string $handle): void
    {
        $this->assertTrue(ValidHandle::passes($handle), "expected [{$handle}] to be allowed");
    }

    #[DataProvider('rejectedHandles')]
    public function test_it_rejects(string $handle): void
    {
        $this->assertFalse(ValidHandle::passes($handle), "expected [{$handle}] to be rejected");
    }

    public function test_a_handle_of_exactly_the_maximum_length_is_allowed(): void
    {
        $this->assertTrue(ValidHandle::passes(str_repeat('a', ValidHandle::MAX_LENGTH)));
    }

    public function test_a_handle_one_character_over_is_rejected(): void
    {
        $this->assertFalse(ValidHandle::passes(str_repeat('a', ValidHandle::MAX_LENGTH + 1)));
    }

    public function test_length_counts_characters_not_bytes(): void
    {
        $handle = str_repeat('Ж', ValidHandle::MAX_LENGTH);

        $this->assertSame(48, strlen($handle));
        $this->assertTrue(ValidHandle::passes($handle));
    }

    public function test_length_is_measured_after_normalisation(): void
    {
        $decomposed = str_repeat("e\u{0301}", ValidHandle::MAX_LENGTH);

        $this->assertSame(48, mb_strlen($decomposed));
        $this->assertTrue(ValidHandle::passes($decomposed));

        $this->assertFalse(ValidHandle::passes(str_repeat("e\u{0301}", ValidHandle::MAX_LENGTH + 1)));
    }

    public function test_the_character_field_caps_input_at_the_same_limit(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            ->assertOk()
            ->assertSee('maxlength="'.ValidHandle::MAX_LENGTH.'"', false);
    }

    public function test_the_offered_pool_excludes_anything_that_would_fail_validation(): void
    {
        SuggestedHandle::create(['handle' => 'has space', 'in_use' => false]);
        SuggestedHandle::create(['handle' => 'quote"x', 'in_use' => false]);
        SuggestedHandle::create(['handle' => 'g0odhandle', 'in_use' => false]);

        // The client picks from this array locally, so every entry in it must
        // be registrable — there is no server-side check on the way back.
        $handles = $this->getJson(route('handles.available'))
            ->assertOk()
            ->json('handles');

        $this->assertSame(['g0odhandle'], $handles);

        foreach ($handles as $handle) {
            $this->assertTrue(ValidHandle::passes($handle));
        }
    }

    public function test_a_pool_of_only_invalid_handles_offers_nothing(): void
    {
        SuggestedHandle::create(['handle' => 'has space', 'in_use' => false]);

        $this->getJson(route('handles.available'))
            ->assertOk()
            ->assertJson(['handles' => [], 'count' => 0]);
    }

    /**
     * The two halves of a validation message now come from two places.
     *
     * Nothing publishes a lang/en/validation.php any more, so the framework's
     * own messages are the only ones the validator knows — and ours are plain
     * strings handed to $fail(), not keys it could shadow.
     */
    public function test_framework_messages_are_the_frameworks_and_ours_are_ours(): void
    {
        $this->assertSame(
            'The name field is required.',
            Validator::make([], ['name' => 'required'])->errors()->first('name')
        );

        $this->assertStringContainsString(
            'letters and digits',
            Text::get('validation.handle_characters', ['symbols' => ''])
        );
    }
}
