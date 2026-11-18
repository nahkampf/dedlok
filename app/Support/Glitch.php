<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Character;

/**
 * THE PAGE GLITCH, said from the server (`UI.glitch` in ui-kit.js draws it; docs/frontend.md).
 *
 * Three weights, and the strongest asked for before the next page is drawn is the one played: `tick` (a miss or a failure), `hit`
 * (something got through: ICE, a blow, a jack in or out) and `crash` (Crash Jackout, going down). It is kept in the session and
 * PULLED when the layout draws, not flashed, so a redirect that only passes through (the City on its way to the waiting room) does
 * not use it up.
 *
 * Only for the person at this request: a party's round is closed by whichever member's request closes it, and nobody else's screen
 * should glitch because of that.
 */
final class Glitch
{
    public const TICK = 'tick';

    public const HIT = 'hit';

    public const CRASH = 'crash';

    private const RANK = [self::TICK => 1, self::HIT => 2, self::CRASH => 3];

    private const KEY = 'glitch';

    /** Ask for an effect on this character's next page, if they are the one making the request. */
    public static function flash(Character $who, string $level): void
    {
        if (! isset(self::RANK[$level]) || ! request()->hasSession() || request()->user()?->character?->getKey() !== $who->getKey()) {
            return;
        }

        $held = (string) session(self::KEY, '');

        if ((self::RANK[$held] ?? 0) < self::RANK[$level]) {
            session([self::KEY => $level]);
        }
    }

    /** What the page being drawn should play, once. */
    public static function pull(): ?string
    {
        return request()->hasSession() ? session()->pull(self::KEY) : null;
    }

    /** Does this log line play success.mp3? The listed ones, and every kind of backdoor installed (`install.routing` and so on). */
    public static function playsSuccess(?string $key): bool
    {
        return $key !== null && (in_array($key, config('glitch.success_sound'), true) || str_starts_with($key, 'cyberspace.run.log.install.'));
    }

    /** The effect for one line of a run's log (`config/glitch.php`), by the key it was written with. */
    public static function forLogKey(?string $key): ?string
    {
        // Indexed, not `config("glitch.log.{$key}")`: the keys are dotted, and that would read them as a path.
        return $key === null ? null : (config('glitch.log')[$key] ?? null);
    }

    /**
     * The strongest effect among lines that carry one (a fight's log lines, `fx`), or null.
     *
     * @param  iterable<array{fx?: string}>  $lines
     */
    public static function strongest(iterable $lines): ?string
    {
        $best = null;

        foreach ($lines as $line) {
            $fx = $line['fx'] ?? null;

            if (isset(self::RANK[$fx]) && self::RANK[$fx] > (self::RANK[$best] ?? 0)) {
                $best = $fx;
            }
        }

        return $best;
    }
}
