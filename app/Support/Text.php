<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * Player-visible strings, out of config/strings.php.
 *
 * This is what is left of the translation layer, and deliberately much less
 * than it replaced. There is one locale, so there is no locale to resolve, no
 * fallback chain, and no plural rule engine — English has two forms and they
 * are spelled out in the config rather than parsed out of a pipe syntax.
 *
 * It exists at all because strings carry :placeholders. Without it every call
 * site would be a config() lookup wrapped in strtr(), which is noisier than
 * the thing it replaced.
 */
final class Text
{
    /**
     * One string, with :placeholders substituted.
     *
     * A missing key returns the key itself rather than throwing, the way the
     * translator did: a wrong label on screen is a smaller failure than a 500,
     * and it is just as visible.
     *
     * @param  array<string, string|int|float>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $value = Config::get("strings.{$key}");

        if (! is_string($value)) {
            return $key;
        }

        return self::substitute($value, $replace);
    }

    /**
     * The form matching $count, from a {zero, one, many} group.
     *
     * `:count` is substituted for free, since a counted string that does not
     * name the number is the exception.
     *
     * @param  array<string, string|int|float>  $replace
     */
    public static function plural(string $key, int $count, array $replace = []): string
    {
        $forms = Config::get("strings.{$key}");

        if (! is_array($forms)) {
            return $key;
        }

        $form = match (true) {
            $count === 0 => $forms['zero'] ?? $forms['many'] ?? '',
            $count === 1 => $forms['one'] ?? $forms['many'] ?? '',
            default => $forms['many'] ?? '',
        };

        return self::substitute($form, $replace + ['count' => $count]);
    }

    /**
     * A whole group of strings, for the rare consumer that wants the set.
     *
     * Cap's widget is the only one: every key under captcha.widget is the
     * suffix of a data-cap-i18n-* attribute, handed over wholesale.
     *
     * @return array<string, mixed>
     */
    public static function group(string $key): array
    {
        $value = Config::get("strings.{$key}");

        return is_array($value) ? $value : [];
    }

    /**
     * Does a key exist and hold a string?
     *
     * For the handful of places that render something optional and want
     * nothing at all rather than the key.
     */
    public static function has(string $key): bool
    {
        return is_string(Config::get("strings.{$key}"));
    }

    /** @param  array<string, string|int|float>  $replace */
    private static function substitute(string $value, array $replace): string
    {
        if ($replace === []) {
            return $value;
        }

        $pairs = [];

        foreach ($replace as $token => $with) {
            $pairs[':'.$token] = (string) $with;
        }

        // Longest token first, so :count_max is not eaten by :count.
        uksort($pairs, static fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return strtr($value, $pairs);
    }
}
