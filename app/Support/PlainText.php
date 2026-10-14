<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whatever a player types that other players will read, reduced to plain text.
 *
 * ESCAPING ON OUTPUT IS THE REAL DEFENCE and every view prints these with `{{ }}`; this is the second one, for the day
 * somebody forgets, and so that what is STORED is only text: no tags, no script or style blocks, no control or
 * invisible formatting characters (a right-to-left override can make one handle read as another).
 *
 * Entities are decoded first, so `&lt;script&gt;` cannot be stored as something that turns into a tag later, and tags
 * are removed in a loop until none are left, so a tag assembled from the pieces of another (`<scr<b>ipt>`) goes too.
 * Only something that LOOKS like a tag is removed: "I <3 you" and "a < b" are text and stay.
 */
final class PlainText
{
    /**
     * `$verbatim` is for chat, where a line may be art: tags, script and invisible characters still go, but newlines,
     * tabs, runs of blank lines and every leading or trailing space are kept exactly as typed.
     */
    public static function clean(?string $text, bool $multiline = false, bool $verbatim = false): string
    {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\r\n", "\n", $text);

        // Script, style and their contents, comments and CDATA, then every remaining tag, until nothing changes.
        do {
            $before = $text;
            // (A script that is never closed takes everything after it with it, once the closed ones are gone.)
            $text = (string) preg_replace(
                ['#<\s*(script|style|iframe|object|embed)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '#<!--.*?-->#s', '#<!\[CDATA\[.*?\]\]>#s', '#<\s*(script|style|iframe|object|embed)\b(?!.*<\s*/\s*\1\s*>).*#is', '#</?[a-z!?][^>]*>#i'],
                '',
                $text,
            );
        } while ($text !== $before);

        // Control and invisible formatting characters (zero-width, bidi overrides), keeping a newline where one is allowed.
        $text = (string) preg_replace(match (true) {
            $verbatim => '/[^\P{C}\n\t]/u',
            $multiline => '/[^\P{C}\n]/u',
            default => '/\p{C}/u',
        }, '', $text);

        if ($verbatim) {
            return $text;
        }

        $text = $multiline
            ? (string) preg_replace("/\n{3,}/", "\n\n", $text)
            : (string) preg_replace('/\s+/u', ' ', $text);

        // A block of text keeps the indent of its first line (it may be art); only blank lines and trailing space go.
        return $multiline ? rtrim(ltrim($text, "\n")) : trim($text);
    }
}
