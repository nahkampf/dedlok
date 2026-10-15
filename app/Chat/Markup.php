<?php

declare(strict_types=1);

namespace App\Chat;

use App\Models\MarketOffer;
use App\Models\RunParty;
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
        // A pasted block (more than one line) is ART: escaped and kept exactly, with no markdown to eat its `*` and `_`.
        // Only the address button still applies. The chat line is `white-space: pre-wrap`, so the newlines show as they are.
        // A block that is nothing but `- ` items is still a list.
        $lines = explode("\n", $message);
        $isList = collect($lines)->every(fn (string $l): bool => preg_match('/^-\s+\S/u', $l) === 1);

        if (count($lines) > 1 && ! $isList) {
            return self::addresses(e($message));
        }

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

        return self::addresses($text);
    }

    /** `market:<id>` becomes a button, titled with the listed item's name, that opens that listing. An unknown id keeps its text. */
    public static function listings(string $text): string
    {
        preg_match_all('/\bmarket:(\d{1,10})\b/', $text, $found);

        if ($found[1] === []) {
            return $text;
        }

        $names = MarketOffer::query()->with('row.item')->whereIn('id', array_unique($found[1]))->get()
            ->mapWithKeys(fn (MarketOffer $o): array => [$o->getKey() => $o->row?->item?->displayName()]);

        return preg_replace_callback('/\bmarket:(\d{1,10})\b/', fn (array $m): string => sprintf(
            '<button type="button" class="copy" data-listing="%s">%s</button>',
            e(route('market.listing', ['offer' => (int) $m[1]], false)),
            e($names[(int) $m[1]] ?? $m[0]),
        ), $text);
    }

    /** `run:<id>` becomes a button that joins that run's party, labelled with the system's address. Unknown id: left as text. */
    public static function runs(string $text): string
    {
        preg_match_all('/\brun:(\d{1,10})\b/', $text, $found);

        if ($found[1] === []) {
            return $text;
        }

        $addresses = RunParty::query()->whereIn('id', array_unique($found[1]))->pluck('address', 'id');

        return preg_replace_callback('/\brun:(\d{1,10})\b/', function (array $m) use ($addresses): string {
            $address = $addresses[(int) $m[1]] ?? null;

            if ($address === null) {
                return $m[0];
            }

            return sprintf(
                '<button type="button" class="copy" data-run-join="%s">%s</button>',
                e(route('run.party.join', ['party' => (int) $m[1]], false)),
                e(Text::get('cyberspace.party.link', ['address' => $address])),
            );
        }, $text);
    }

    /** Last, so the markup it makes is never meant for the passes above. */
    private static function addresses(string $text): string
    {
        return self::runs(self::listings(preg_replace_callback('/\baddr:([0-9A-Fa-f]{8})\b/', fn (array $m): string => sprintf(
            '<button type="button" class="copy" data-copy="%1$s" data-copied="%2$s" data-copy-failed="%3$s" title="%4$s">%1$s</button>',
            strtoupper($m[1]),
            e(Text::get('cyberspace.reading.copied')),
            e(Text::get('cyberspace.reading.copy_failed')),
            e(Text::get('cyberspace.reading.copy')),
        ), $text)));
    }
}
