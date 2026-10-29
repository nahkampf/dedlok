<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A bio that carries ANSI art, drawn as HTML the way a DOS screen would have shown it.
 *
 * A SMALL SUBSET, AS IT WAS WRITTEN: `ESC[...m` with 0 (reset), 1 (high intensity), 5 (blink), 30-37 (foreground) and 40-47
 * (background); and `ESC[nC` (cursor forward, n blank cells that are NOT painted with the current background, as in DOS). Every
 * other parameter and every other escape sequence is dropped, so nothing in a paste can do anything else.
 *
 * `$ice` is iCE colours: blink is no longer blink but a bright BACKGROUND (the video card's blink bit repurposed), so all sixteen
 * colours are available behind the text and nothing flashes. A real file keeps that flag in its SAUCE record, which is cut off
 * with everything after the first U+001A, so the upload reads it first and the character keeps it (`characters.bio_ice`).
 *
 * Wraps at column 80 as DOS did, but only once a character would land in column 81, so a newline after an exactly-80-wide
 * line is not an extra blank row. Colours are the classes `a-f0..15`, `a-b0..15` (the DOS palette's own numbering: 1 blue,
 * 4 red; SGR's order is mapped here) and `a-bl`, all fixed: nothing a player typed ever reaches an attribute.
 * Text without an escape is just escaped, untouched.
 */
final class Ansi
{
    private const COLUMNS = 80;

    /** SGR 30-37 order (black, red, green, yellow, blue, magenta, cyan, white) to the DOS palette index. */
    private const DOS = [0, 4, 2, 6, 1, 5, 3, 7];

    public static function in(?string $text): bool
    {
        return $text !== null && str_contains($text, "\x1b[");
    }

    public static function render(string $text, bool $ice = false): string
    {
        if (! self::in($text)) {
            return e($text);
        }

        $text = explode("\x1a", $text, 2)[0];
        preg_match_all('/\x1b\[([0-?]*)[ -\/]*([@-~])|\n|\t|\p{Cc}|./su', $text, $tokens, PREG_SET_ORDER);

        $out = '';
        $run = '';
        $style = '';
        [$fg, $bg, $bold, $blink, $col] = [7, 0, false, false, 0];

        // Close the run in progress: plain when nothing but the defaults is set.
        $flush = function () use (&$out, &$run, &$style): void {
            // (phpstan does not see $run and $style change through the references, hence the ignores.)
            // @phpstan-ignore identical.alwaysTrue, identical.alwaysTrue
            $out .= $run === '' ? '' : ($style === '' ? e($run) : '<span class="'.$style.'">'.e($run).'</span>');
            $run = '';
        };
        // Blank cells nothing paints: the default style, whatever the current one.
        $skip = function (int $n) use ($flush, &$out, &$col): void {
            $flush();
            $n = max(0, min($n, self::COLUMNS - $col));
            $out .= str_repeat(' ', $n);
            $col += $n;
        };

        foreach ($tokens as $t) {
            $token = $t[0];

            if (str_starts_with($token, "\x1b[")) {
                $params = $t[1];

                if ($t[2] === 'C' && preg_match('/^\d*$/', $params) === 1) {
                    $skip($params === '' ? 1 : (int) $params);
                } elseif ($t[2] === 'm' && preg_match('/^[\d;]*$/', $params) === 1) {
                    foreach (explode(';', $params) as $p) {
                        $p = (int) $p;
                        match (true) {
                            $p === 0 => [$fg, $bg, $bold, $blink] = [7, 0, false, false],
                            $p === 1 => $bold = true,
                            $p === 5 => $blink = true,
                            $p >= 30 && $p <= 37 => $fg = self::DOS[$p - 30],
                            $p >= 40 && $p <= 47 => $bg = self::DOS[$p - 40],
                            default => null,
                        };
                    }

                    $new = trim(
                        ($fg + ($bold ? 8 : 0) !== 7 ? 'a-f'.($fg + ($bold ? 8 : 0)).' ' : '')
                        .($bg + ($ice && $blink ? 8 : 0) !== 0 ? 'a-b'.($bg + ($ice && $blink ? 8 : 0)).' ' : '')
                        .($blink && ! $ice ? 'a-bl' : '')
                    );

                    if ($new !== $style) {
                        $flush();
                        $style = $new;
                    }
                }
            } elseif ($token === "\n") {
                $run .= "\n";
                $col = 0;
            } elseif ($token === "\t") {
                $skip(8 - $col % 8);
            } elseif (preg_match('/^\p{Cc}$/u', $token) !== 1) {
                if ($col === self::COLUMNS) {
                    $run .= "\n";
                    $col = 0;
                }

                $run .= $token;
                $col++;
            }
        }

        $flush();

        return $out;
    }
}
