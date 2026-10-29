<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A bio that carries ANSI art, drawn as HTML the way a DOS screen would have shown it.
 *
 * A SMALL SUBSET, AS IT WAS WRITTEN, drawn on an 80-column screen grid so art that moves the cursor lands where DOS put it:
 * `ESC[...m` with 0 (reset), 1 (high intensity), 5 (blink), 30-37 (foreground) and 40-47 (background); and the cursor
 * movements `ESC[nA` up, `B` down, `C` forward, `D` back, `ESC[r;cH` / `f` to a position, `s` and `u` to save and restore it. Moving the
 * cursor does NOT paint (as in DOS): a cell nobody writes stays blank, and a later write replaces what was there. Every other
 * parameter and every other escape sequence (`2J`, `K`, ...) is dropped, so nothing in a paste can do anything else.
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

    /** Rows past this are not drawn: `ESC[9999B` must not make a page of blank lines. */
    private const ROWS = 2000;

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

        /** @var array<int, array<int, array{string, string}>> $grid row => column => [character, style classes] */
        $grid = [];
        [$fg, $bg, $bold, $blink, $row, $col, $last, $saved] = [7, 0, false, false, 0, 0, 0, [0, 0]];

        foreach ($tokens as $t) {
            $token = $t[0];

            if (str_starts_with($token, "\x1b[")) {
                if (preg_match('/^[\d;]*$/', $t[1]) !== 1) {
                    continue;
                }

                $params = array_map('intval', explode(';', $t[1]));
                $n = max(1, $params[0]);

                switch ($t[2]) {
                    case 'm':
                        foreach ($params as $p) {
                            match (true) {
                                $p === 0 => [$fg, $bg, $bold, $blink] = [7, 0, false, false],
                                $p === 1 => $bold = true,
                                $p === 5 => $blink = true,
                                $p >= 30 && $p <= 37 => $fg = self::DOS[$p - 30],
                                $p >= 40 && $p <= 47 => $bg = self::DOS[$p - 40],
                                default => null,
                            };
                        }
                        break;
                    case 'A': $row = max(0, $row - $n);
                        break;
                    case 'B': $row = min(self::ROWS, $row + $n);
                        break;
                    case 'C': $col = min(self::COLUMNS - 1, $col + $n);
                        break;
                    case 'D': $col = max(0, $col - $n);
                        break;
                    case 'H':
                    case 'f':
                        $row = min(self::ROWS, max(1, $params[0]) - 1);
                        $col = min(self::COLUMNS - 1, max(1, $params[1] ?? 1) - 1);
                        break;
                    case 's': $saved = [$row, $col];
                        break;
                    case 'u': [$row, $col] = $saved;
                        break;
                    default:
                }
            } elseif ($token === "\n") {
                $row = min(self::ROWS, $row + 1);
                $col = 0;
                $last = max($last, $row);
            } elseif ($token === "\t") {
                $col = min(self::COLUMNS - 1, intdiv($col, 8) * 8 + 8);
            } elseif (preg_match('/^\p{Cc}$/u', $token) !== 1) {
                // Wraps when a character would land in column 81, not before, so a newline after a full line is no blank row.
                if ($col === self::COLUMNS) {
                    $row = min(self::ROWS, $row + 1);
                    $col = 0;
                }

                $high = $fg + ($bold ? 8 : 0);
                $back = $bg + ($ice && $blink ? 8 : 0);
                $grid[$row][$col] = [$token, trim(
                    ($high !== 7 ? 'a-f'.$high.' ' : '').($back !== 0 ? 'a-b'.$back.' ' : '').($blink && ! $ice ? 'a-bl' : '')
                )];
                $col++;
                $last = max($last, $row);
            }
        }

        $lines = [];

        for ($r = 0; $r <= $last; $r++) {
            $cells = $grid[$r] ?? [];
            $line = '';
            $run = '';
            $style = '';

            for ($c = 0, $end = $cells === [] ? 0 : max(array_keys($cells)) + 1; $c < $end; $c++) {
                [$ch, $css] = $cells[$c] ?? [' ', ''];

                if ($css !== $style) {
                    $line .= $run === '' ? '' : ($style === '' ? e($run) : '<span class="'.$style.'">'.e($run).'</span>');
                    $run = '';
                    $style = $css;
                }

                $run .= $ch;
            }

            $lines[] = $line.($run === '' ? '' : ($style === '' ? e($run) : '<span class="'.$style.'">'.e($run).'</span>'));
        }

        return implode("\n", $lines);
    }
}
