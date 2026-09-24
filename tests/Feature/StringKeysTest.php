<?php

declare(strict_types=1);

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every string key a screen asks for exists.
 *
 * THIS IS THE ONE FAILURE MODE `Text::get()` CANNOT ANNOUNCE. A missing key
 * returns the key — deliberately, so a half-written screen still renders — and
 * nothing throws, nothing logs, and no test fails. What happens instead is that
 * a card prints `ITEMS.SUMMARY.SEPARATOR` in capitals at a player, and it keeps
 * doing it until somebody happens to look at that card.
 *
 * Which is exactly what happened: `items.summary.separator` never existed, the
 * separator lived under `decks`, and three spec lines read
 * "4 QFLOPSITEMS.SUMMARY.SEPARATOR3 QB" from the first release that had them.
 *
 * So the grep is the test. It reads every literal `Text::get('a.b')` and
 * `Text::plural('a.b')` in the source and resolves each against
 * `config/strings.php`.
 *
 * A key BUILT at runtime — `'…intent_'.$purpose->value` — cannot be checked
 * this way and is not pinned here; the enum behind each of those is its own
 * guarantee, and the screens that use them are covered by their own tests.
 */
final class StringKeysTest extends TestCase
{
    /** Where player-visible text is asked for. */
    private const ROOTS = ['app', 'resources', 'routes', 'config', 'database'];

    public function test_every_literal_string_key_resolves(): void
    {
        $missing = [];

        foreach ($this->keysInSource() as $key => $files) {
            if (! $this->resolves($key)) {
                $missing[] = $key.'  <- '.implode(', ', $files);
            }
        }

        $this->assertSame([], $missing, "Text::get() would return these keys verbatim:\n".implode("\n", $missing));
    }

    /**
     * The scan itself, which is also worth asserting: a run that found no keys
     * at all would pass the test above while checking nothing.
     */
    public function test_the_scan_actually_finds_keys(): void
    {
        $this->assertGreaterThan(500, count($this->keysInSource()));
    }

    /** @return array<string, list<string>> key => the files asking for it */
    private function keysInSource(): array
    {
        $found = [];
        $base = base_path();

        foreach (self::ROOTS as $root) {
            $dir = $base.'/'.$root;

            if (! is_dir($dir)) {
                continue;
            }

            /** @var iterable<\SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                // The literal form only. A concatenated key is deliberately
                // out of scope — see the class docblock.
                if (! preg_match_all("/Text::(?:get|plural)\(\s*'([a-z0-9_]+(?:\.[a-z0-9_]+)+)'\s*[,)]/i", $source, $matches)) {
                    continue;
                }

                foreach ($matches[1] as $key) {
                    $found[$key][] = str_replace($base.'/', '', $file->getPathname());
                }
            }
        }

        return array_map(static fn (array $files): array => array_values(array_unique($files)), $found);
    }

    private function resolves(string $key): bool
    {
        $node = config('strings');

        foreach (explode('.', $key) as $part) {
            if (! is_array($node) || ! array_key_exists($part, $node)) {
                return false;
            }

            $node = $node[$part];
        }

        return true;
    }
}
