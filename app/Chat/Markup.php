<?php

declare(strict_types=1);

namespace App\Chat;

use App\Support\Text;

/**
 * A stored chat line, drawn as HTML.
 *
 * ESCAPED FIRST, and only then are the few markdown forms turned into tags, so nothing a player typed can arrive as markup:
 * the tags in the result are the ones written below. Bold, italic, strikethrough and `- ` lists; `addr:XXXXXXXX` becomes the
 * copy-an-address button.
 */
final class Markup
{
    public static function render(string $message): string
    {
        $out = [];
        $list = [];
        $flush = function () use (&$out, &$list): void {
            if ($list !== []) {
                $out[] = '<ul>'.implode('', array_map(fn (string $li): string => "<li>{$li}</li>", $list)).'</ul>';
                $list = [];
            }
        };

        foreach (explode("\n", e($message)) as $line) {
            if (preg_match('/^-\s+(.+)$/u', $line, $m) === 1) {
                $list[] = self::inline($m[1]);

                continue;
            }

            $flush();
            $out[] = self::inline($line);
        }

        $flush();

        return preg_replace('#(?<!</ul>)<br>(?=<ul>)#', '', implode('<br>', $out));
    }

    private static function inline(string $text): string
    {
        $text = preg_replace('/\*\*(?!\s)(.+?)(?<!\s)\*\*/u', '<strong>$1</strong>', $text);
        $text = preg_replace('/~~(?!\s)(.+?)(?<!\s)~~/u', '<s>$1</s>', $text);
        $text = preg_replace('/(?<![*\w])\*(?![\s*])(.+?)(?<![\s*])\*(?![*\w])/u', '<em>$1</em>', $text);
        $text = preg_replace('/(?<![\w])_(?![\s_])(.+?)(?<![\s_])_(?!\w)/u', '<em>$1</em>', $text);

        // Last, so the markup it makes is never meant for the passes above.
        return preg_replace_callback('/\baddr:([0-9A-Fa-f]{8})\b/', fn (array $m): string => sprintf(
            '<button type="button" class="copy" data-copy="%1$s" data-copied="%2$s" data-copy-failed="%3$s" title="%4$s">%1$s</button>',
            strtoupper($m[1]),
            e(Text::get('cyberspace.reading.copied')),
            e(Text::get('cyberspace.reading.copy_failed')),
            e(Text::get('cyberspace.reading.copy')),
        ), $text);
    }
}
