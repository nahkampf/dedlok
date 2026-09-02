<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Compares every locale's keys against the fallback locale.
 *
 * With all game content in lang files, a missing key is a missing item name or
 * quest brief, and Laravel renders the raw key rather than failing — so drift
 * is invisible until a player sees "items.foo.name" on screen. Run this in CI.
 */
class CheckTranslations extends Command
{
    protected $signature = 'i18n:check {--fix-report : List missing keys in a form you can paste into a lang file}';

    protected $description = 'Verify every locale defines the same translation keys as the fallback locale';

    public function handle(): int
    {
        $fallback = (string) config('app.fallback_locale');
        $locales = array_keys((array) config('i18n.locales', []));

        if (! in_array($fallback, $locales, true)) {
            $this->error("Fallback locale [{$fallback}] is not listed in config/i18n.php.");

            return self::FAILURE;
        }

        $reference = $this->keysFor($fallback);
        $this->line(sprintf('Reference <info>%s</info>: %d keys', $fallback, count($reference)));

        if (count($locales) === 1) {
            $this->newLine();
            $this->info('Only the fallback locale is configured — nothing to compare against.');
            $this->line('Add a locale to <comment>config/i18n.php</comment> and copy '
                ."<comment>lang/{$fallback}</comment> to start translating.");

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($locales as $locale) {
            if ($locale === $fallback) {
                continue;
            }

            $keys = $this->keysFor($locale);
            $missing = array_diff_key($reference, $keys);
            $extra = array_diff_key($keys, $reference);

            if ($missing === [] && $extra === []) {
                $this->line(sprintf('  <info>✔</info> %-6s %d keys', $locale, count($keys)));

                continue;
            }

            $failed = true;
            $this->line(sprintf('  <error>✘</error> %-6s %d keys', $locale, count($keys)));

            foreach (array_keys($missing) as $key) {
                $this->line("      <comment>missing</comment>  {$key}");
            }

            foreach (array_keys($extra) as $key) {
                $this->line("      <comment>orphan </comment>  {$key}");
            }
        }

        if ($failed) {
            $this->newLine();
            $this->error('Translation keys are out of sync.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All locales are in sync.');

        return self::SUCCESS;
    }

    /** @return array<string, true> */
    private function keysFor(string $locale): array
    {
        $keys = [];

        foreach (glob(lang_path($locale).'/*.php') ?: [] as $file) {
            $group = basename($file, '.php');
            $keys += $this->flatten([$group => require $file]);
        }

        return $keys;
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array<string, true>
     */
    private function flatten(array $items, string $prefix = ''): array
    {
        $flat = [];

        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            $flat += is_array($value) ? $this->flatten($value, $path) : [$path => true];
        }

        return $flat;
    }
}
