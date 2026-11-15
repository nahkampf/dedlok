<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Markdown reader for `public/docs`: `/docs/<name>.md` is rewritten here (public/.htaccess) and drawn with the UI kit;
 * `?download=1` on the same address hands over the raw file.
 *
 * ONLY FILES DIRECTLY IN public/docs, ONLY .md. The name is a plain word with an `.md` suffix (the route's pattern, and
 * again here): no slashes, no dots before the suffix, so no `..`, no subfolders, no encoded tricks reach the disk. The path
 * is then resolved (symlinks included) and must still be a regular file whose folder IS public/docs.
 */
final class DocsController extends Controller
{
    public const NAME = '[A-Za-z0-9][A-Za-z0-9_-]{0,80}\.md';

    public function show(Request $request, string $file): View|BinaryFileResponse
    {
        $path = self::resolve($file);

        // The raw file: `?download=1` on the same address, since Apache serves a real .md path itself before any route
        // under it could be reached.
        if ($request->boolean('download')) {
            return response()->download($path, $file, [
                'Content-Type' => 'text/markdown; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return view('docs.show', [
            'file' => $file,
            'html' => self::render((string) file_get_contents($path)),
        ]);
    }

    /** The file's real path, or a 404 for anything that is not a Markdown file directly in public/docs. */
    private static function resolve(string $file): string
    {
        abort_unless(preg_match('/\A'.self::NAME.'\z/', $file) === 1, 404);

        $dir = realpath(public_path('docs'));
        $path = $dir === false ? false : realpath($dir.DIRECTORY_SEPARATOR.$file);

        abort_unless($path !== false && dirname($path) === $dir && is_file($path) && str_ends_with($path, '.md'), 404);

        return $path;
    }

    /**
     * CommonMark with raw HTML stripped and unsafe links (javascript:, data: and the like) dropped, as the patch notes are.
     * Discord's small text (`-# text` on a line of its own) is marked before rendering and turned into the kit's micro
     * line after, its content escaped by CommonMark like any other; inside fenced code it is left alone.
     */
    public static function render(string $markdown): string
    {
        $mark = "\u{E000}small\u{E000}";
        $fenced = false;
        $lines = [];

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^\s{0,3}(```|~~~)/', $line) === 1) {
                $fenced = ! $fenced;
            }

            $lines[] = ! $fenced && preg_match('/^-#\s+(.*)$/', $line, $m) === 1 ? "\n{$mark} {$m[1]}\n" : $line;
        }

        $html = Str::markdown(implode("\n", $lines), ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        return (string) preg_replace('/<p>'.$mark.' (.*?)<\/p>/s', '<p class="micro" style="color:var(--ink-2)">$1</p>', $html);
    }
}
