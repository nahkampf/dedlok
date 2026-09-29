<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * PATCHNOTES.md: the players' changelog, one `## X.Y.Z (YYYY-MM-DD)` heading
 * per release, in the author's own words.
 *
 * CHANGELOG.md is the technical record and stays that; this is the one a
 * player reads, in a modal on the front page. A file for the reason NOTICE.md
 * is one: it ships with the release it describes.
 *
 * WHAT IS NEW IS DECIDED BY THE NEWEST NOTE, never by `config('version.app')`.
 * A release nobody wrote a note for has nothing to show, so it must not open
 * an empty modal — or the last one again.
 */
final class PatchNotes
{
    /** A release heading. The trailing colon is optional. */
    private const HEADING = '/^##[ \t]+(\d+\.\d+\.\d+)[ \t]+\((\d{4}-\d{2}-\d{2})\):?[ \t]*$/m';

    /**
     * Every note, newest first. A heading that does not match the format is
     * not a release, so its text runs on as part of the one above it.
     *
     * @return list<array{version: string, date: string, html: string}>
     */
    public static function all(?string $path = null): array
    {
        $path ??= base_path('PATCHNOTES.md');
        $markdown = is_file($path) ? (string) file_get_contents($path) : '';

        $parts = preg_split(self::HEADING, $markdown, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $notes = [];

        // [preamble, version, date, body, version, date, body, …]
        for ($i = 1; $i < count($parts); $i += 3) {
            $notes[] = [
                'version' => $parts[$i],
                'date' => $parts[$i + 1],
                // Raw HTML stripped, which is what makes printing it unescaped
                // safe — the same options NOTICE.md is rendered with.
                'html' => Str::markdown(trim($parts[$i + 2] ?? ''), [
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                ]),
            ];
        }

        usort($notes, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $notes;
    }

    /**
     * What somebody who last saw `$seen` has not read yet, newest first.
     *
     * NULL — a new account, or one from before this existed — is the latest
     * release alone: a first visit is not a history lesson.
     *
     * @param  list<array{version: string, date: string, html: string}>  $notes
     * @return list<array{version: string, date: string, html: string}>
     */
    public static function unseen(array $notes, ?string $seen): array
    {
        if ($seen === null) {
            return array_slice($notes, 0, 1);
        }

        return array_values(array_filter(
            $notes,
            static fn (array $note): bool => version_compare($note['version'], $seen, '>'),
        ));
    }
}
