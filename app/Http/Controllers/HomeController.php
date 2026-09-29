<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PatchNotes;
use App\Support\Text;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The front page: the notice, and the two places there are to go.
 *
 * NOTICE.md at the repository root is the notice, rendered from Markdown on
 * every request — it is a few lines and editing the file is how it changes.
 * Authored in the repo and never by a player, but raw HTML in it is stripped
 * all the same: Markdown is the whole format, and a notice that could carry a
 * <script> is one careless paste from being a problem.
 */
class HomeController extends Controller
{
    /** The two places, and the picture that stands for each. */
    private const PLACES = [
        'city' => 'images/city.jpg',
        'cyberspace' => 'images/cyberspace.jpg',
    ];

    public function __invoke(): View
    {
        $markdown = is_file($path = base_path('NOTICE.md')) ? (string) file_get_contents($path) : '';

        // The first line's heading is the accordion's summary rather than the
        // first thing inside it — a closed notice still says what it is.
        $title = null;

        if (preg_match('/\A#[ \t]+(.+)\R?/u', $markdown, $m) === 1) {
            $title = trim($m[1]);
            $markdown = substr($markdown, strlen($m[0]));
        }

        $notice = trim($markdown) === '' ? null : Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $patchNotes = PatchNotes::all();
        $user = request()->user();

        return view('home', [
            // Every note, for the version link, and what this player has not
            // read, which opens by itself. Nobody signed in is asked nothing.
            'patchNotes' => $patchNotes,
            'unseenNotes' => $user === null ? [] : PatchNotes::unseen($patchNotes, $user->last_seen_changelog),
            'title' => $title,
            'notice' => $notice,
            // What a closed notice shows: its first 250 characters as plain
            // text. Taken from the RENDERED notice, so no Markdown syntax — a
            // `**` or a link's URL — leaks into it. Blocks keep one line break
            // each (the teaser is pre-line) and every other run of space is one.
            'teaser' => $notice === null ? null : Str::limit(
                (string) preg_replace(['/[ \t]+/u', '/\s*\n\s*/u'], [' ', "\n"], trim(html_entity_decode(strip_tags($notice), ENT_QUOTES | ENT_HTML5))),
                250,
                '…',
            ),

            // A missing picture renders no image rather than a broken one —
            // the same rule every other picture in the game follows.
            'places' => array_map(
                fn (string $key, string $image): array => [
                    'label' => Text::get("home.places.{$key}"),
                    'image' => is_file(public_path($image)) ? $image : null,
                ],
                array_keys(self::PLACES),
                self::PLACES,
            ),
        ]);
    }
}
