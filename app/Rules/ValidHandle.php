<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Text;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Normalizer;

/**
 * The definition of a valid player handle: length and character set.
 *
 * Letters and digits from any script (\p{L}\p{N}) so Cyrillic, Greek and
 * accented Latin all work, plus an explicit set of symbols, punctuation and
 * box-drawing glyphs. Still rejected: whitespace, the double quote, `@`, `?`,
 * `{`, `}`, emoji, and invisible formatting characters such as zero-width
 * joiners and RTL overrides.
 *
 * The set is wide, so what matters is where a handle is CONSUMED:
 *
 *  - `<` and `>` are allowed. Blade's `{{ }}` escapes them, but `{!! !!}` and
 *    the UI kit's UI.log() insert raw HTML — handles must go through UI.esc().
 *  - `.`, `/` and `\` are allowed, so `../` is a constructible handle. Never
 *    interpolate a handle into a filesystem path or URL segment unencoded.
 *  - `%` and `_` are LIKE wildcards. Escape them before a handle reaches a
 *    LIKE clause, or a search for `a_b` silently also matches `axb`.
 *  - `'` and `` ` `` are SQL quoting characters. Harmless through Eloquent's
 *    bound parameters; a hazard in raw SQL built by concatenation.
 *  - Allowing every script means Cyrillic `а` can impersonate Latin `a`.
 *    Inherent to the requirement, not an oversight; if impersonation becomes a
 *    problem the answer is a confusable-skeleton check layered on top, not a
 *    narrower character set.
 */
class ValidHandle implements ValidationRule
{
    /**
     * Symbols permitted alongside letters and digits.
     *
     * Kept as a literal string rather than a regex fragment so it stays
     * readable and can be reused for documentation and tests.
     */
    /**
     * Maximum handle length, in characters (not bytes).
     *
     * Measured on the NFC-normalised form: "é" typed as e + combining acute is
     * two code points but one character, and counting the raw input would
     * reject a 24-character handle for being 25. Because combining marks are
     * not in the allowed set, every valid handle has one code point per visible
     * character, so this is also the grapheme count.
     */
    public const MAX_LENGTH = 24;

    public const ALLOWED_SYMBOLS = '¶µ®©«»°²³¢£¥§<>=*+,~¡¤¬¯º×ØÑÐßƩ▀▄█▌▐░▒▓■▪▲►▼◄♥♦♣♠∆∏∑←↑→↓_^-[].:|!#$&%/()\\\'`´;';

    public static function pattern(): string
    {
        return '/^[\p{L}\p{N}'.preg_quote(self::ALLOWED_SYMBOLS, '/').']+$/u';
    }

    /**
     * Check a handle, ignoring Unicode normalisation differences.
     *
     * "é" can arrive precomposed (U+00E9, a letter) or decomposed
     * (U+0065 U+0301, a letter plus a combining mark). The combining mark is
     * \p{M}, not \p{L}, so without normalising first the same visible handle
     * would pass or fail depending on the keyboard that produced it.
     */
    public static function passes(string $value): bool
    {
        $normalized = self::normalize($value);

        return self::withinLength($normalized) && self::matchesCharacterSet($normalized);
    }

    /** Character-set check only. Expects an already-normalised string. */
    public static function matchesCharacterSet(string $normalized): bool
    {
        return preg_match(self::pattern(), $normalized) === 1;
    }

    /** Length check only. Expects an already-normalised string. */
    public static function withinLength(string $normalized): bool
    {
        return mb_strlen($normalized) <= self::MAX_LENGTH;
    }

    /** Canonical composed form. Store this, not the raw input. */
    public static function normalize(string $value): string
    {
        return Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(Text::get('validation.handle_characters', ['symbols' => self::ALLOWED_SYMBOLS]));

            return;
        }

        $normalized = self::normalize($value);

        // Reported separately: "too long" and "bad character" are different
        // problems, and a single message covering both helps with neither.
        if (! self::withinLength($normalized)) {
            $fail(Text::get('validation.handle_length', ['max' => self::MAX_LENGTH]));

            return;
        }

        if (! self::matchesCharacterSet($normalized)) {
            $fail(Text::get('validation.handle_characters', ['symbols' => self::ALLOWED_SYMBOLS]));
        }
    }
}
