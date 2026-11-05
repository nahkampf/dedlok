<?php

declare(strict_types=1);

namespace App\City;

use App\Cyberspace\RunEnding;
use App\Cyberspace\RunOutcome;
use App\Models\Character;
use App\Models\Run;
use App\Models\System;
use App\Support\Text;

/**
 * The `{tokens}` a text file may carry, filled ONCE, when the file is left: the words are a snapshot.
 *
 * ONE TABLE FOR THE EDITOR AND THE DELIVERY, so the list the admin reads and the words the player gets cannot drift. What
 * a door knew when it opened is kept on `character_encounters.door` (`context()`), because the outcome runs later, on a
 * click, when a burned system is gone and an ended run no longer belongs to the encounter. A token the door could not fill
 * is refused at save (`unknown()`), and an unknown word in braces is left exactly as typed.
 *
 * Values lose their control characters, so a handle can never carry an escape sequence into a file.
 */
final class FilePlaceholders
{
    /** token => the doors that can fill it; null is every door. */
    private const TOKENS = [
        'handle' => null,
        'date' => null,
        'system' => ['system', 'run', 'after_run', 'spot'],
        'address' => ['system', 'run', 'after_run', 'spot'],
        'type' => ['system', 'run', 'after_run', 'spot'],
        'grade' => ['system', 'run', 'after_run', 'spot'],
        'owner' => ['system', 'run', 'after_run', 'spot'],
        'purpose' => ['run', 'after_run'],
        'outcome' => ['after_run'],
    ];

    /**
     * What a door knew: the system it stood on and the run it filtered on. NULL when it knew neither (a City door).
     *
     * @return array<string, mixed>|null
     */
    public static function context(?System $system, ?Run $run): ?array
    {
        if ($system === null) {
            return null;
        }

        return [
            'system' => $system->displayName(),
            'address' => (string) $system->address,
            'type' => $system->type->label(),
            'grade' => (string) $system->grade,
            'owner' => (string) ($system->owner?->name ?? ''),
            'purpose' => $run?->purpose->label(),
            'outcome' => $run?->ending === null ? null : RunOutcome::of(RunEnding::from((string) $run->ending))->value,
        ];
    }

    /** @return list<string> every token, for the editor's list */
    public static function tokens(): array
    {
        return array_keys(self::TOKENS);
    }

    /** The doors that fill a token, as `starts_on` values; an empty list is every door. @return list<string> */
    public static function doors(string $token): array
    {
        return self::TOKENS[$token] ?? [];
    }

    /**
     * The tokens in $body that this door cannot fill.
     *
     * @return list<string>
     */
    public static function unknown(string $body, ?EncounterStart $door): array
    {
        preg_match_all('/\{(\w+)\}/', $body, $found);

        return array_values(array_filter(
            array_unique($found[1]),
            static fn (string $token): bool => isset(self::TOKENS[$token])
                && self::TOKENS[$token] !== null
                && ($door === null || ! in_array($door->value, self::TOKENS[$token], true)),
        ));
    }

    /** @param  array<string, mixed>|null  $door */
    public static function fill(string $body, Character $character, ?array $door): string
    {
        $values = ['handle' => $character->handle, 'date' => now()->format('Y-m-d')];

        foreach ((array) $door as $token => $value) {
            if ($value !== null) {
                $values[$token] = $token === 'outcome' ? Text::get("cyberspace.run_outcome.{$value}") : (string) $value;
            }
        }

        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (array $m): string => isset($values[$m[1]])
                ? (string) preg_replace('/[\x00-\x1f\x7f]/u', '', (string) $values[$m[1]])
                : $m[0],
            $body,
        );
    }
}
