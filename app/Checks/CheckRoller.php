<?php

declare(strict_types=1);

namespace App\Checks;

use Closure;
use Illuminate\Support\Facades\Config;

/**
 * The die. The ONE place a number becomes a yes or a no.
 *
 * `App\Stats\Resolver` has no randomness in it by design: it turns bases and
 * modifiers into a value and stops. This is what happens next, and it is
 * separate for the same reason — a resolver that rolled could not be asked what
 * a character's chances ARE without spending them.
 *
 * PURE ARITHMETIC AND AN INJECTED DIE. It knows nothing about characters,
 * skills or systems; a caller resolves `check.<slug>` off a Sheet and hands
 * over two numbers. That keeps every roll in the game testable against pinned
 * dice, which is the same decision GridSeeder and MarketSeeder take, and for
 * the same reason: a probabilistic thing tested against real randomness is a
 * test that passes most of the time.
 *
 * ## The curve
 *
 *     p = 1 / (1 + exp(-(value - difficulty) / spread))
 *
 * A logistic on the MARGIN, so what matters is how far ahead of the opposition
 * the character is rather than either number on its own — a check of 20 against
 * 10 and one of 120 against 110 are the same problem, and should feel like it.
 *
 * Equal value and difficulty is always a coin flip, whatever the numbers are.
 * That is the property worth having: it means a difficulty IS the level at
 * which a character is evenly matched, which is a thing a designer can reason
 * about, and it makes every other point of margin relative to a fixed centre.
 *
 * **`spread` is how decisive the game is, in one number.** It is the margin
 * worth roughly three-quarters odds — small and the range between hopeless and
 * certain is narrow, so skill dominates and luck rarely intervenes; large and
 * everything drifts towards the coin flip. It is a float in config because it
 * is exactly the sort of number that gets tuned on a wet afternoon, and every
 * roll in the game moves when it does.
 *
 * ## The floor
 *
 * NO CHECK RESOLVES TO A CERTAINTY. The chance is clamped into
 * `[floor, 1 - floor]`, so the best runner alive still trips a household IDS
 * now and then and the worst one still gets past a military firewall. The
 * reasoning is in config/checks.php and it is not fairness: a mechanic that can
 * be reduced to a certainty stops being a mechanic, and cyberspace has several
 * loops that would be farmed.
 *
 * **The floor applies to every roll**, and a KIND may name its own in config.
 * That is the shape deliberately: an override is a line in `checks.floors`
 * where the reasoning can sit next to the number, and never a flag a call site
 * passes — the way a mechanic ends up with a floor nobody can find is one
 * argument at one call site that nobody remembers writing.
 *
 * ## The multiplier, and why it is not the caller's business
 *
 * Some things scale a CHANCE rather than moving a value or a difficulty — an
 * ICE carrying Ensnare takes a quarter off getting out, whatever the numbers
 * on either side were. That factor is applied to the raw curve and **then the
 * floor is applied again**, which is the only ordering that keeps the promise:
 * a multiplier can make leaving expensive and can never close the door.
 *
 * A caller doing its own multiplication would have to re-clamp, and the first
 * one to forget would be the first mechanic with a real certainty in it. Still
 * pure arithmetic: it is a third number, not a fact about the world.
 */
final class CheckRoller
{
    /**
     * @param  Closure(): float  $die  A uniform draw in [0, 1).
     * @param  array<string, float>  $floors  Per-kind overrides.
     */
    public function __construct(
        private readonly float $spread,
        private readonly float $floor,
        private readonly array $floors,
        private readonly Closure $die,
    ) {}

    /** @param  ?Closure(): float  $die */
    public static function fromConfig(?Closure $die = null): self
    {
        return new self(
            (float) Config::get('checks.spread', 8),
            (float) Config::get('checks.certainty_floor', 0.0085),
            array_map('floatval', (array) Config::get('checks.floors', [])),
            $die ?? static fn (): float => random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX,
        );
    }

    /**
     * What this character's odds ARE, without spending them.
     *
     * Public because a screen that warns "about one in three" and the roll that
     * follows must be the same calculation. Already floored, because the floor
     * is part of what the odds are rather than something applied to them later.
     */
    public function chance(float $value, float $difficulty, ?string $kind = null, float $multiplier = 1.0): float
    {
        $floor = $this->floorFor($kind);
        $raw = 1 / (1 + exp(-($value - $difficulty) / $this->spread));

        return max($floor, min(1 - $floor, $raw * $multiplier));
    }

    /** Roll it. */
    public function roll(float $value, float $difficulty, ?string $kind = null, float $multiplier = 1.0): CheckOutcome
    {
        $chance = $this->chance($value, $difficulty, $kind, $multiplier);
        $rolled = ($this->die)();

        return new CheckOutcome(
            // Strictly below, so a die that can return 0 always passes a
            // floored-to-nothing check exactly as often as the floor promises,
            // and one that never returns 1 cannot pass a hopeless one by
            // landing on the boundary.
            passed: $rolled < $chance,
            value: $value,
            difficulty: $difficulty,
            chance: $chance,
            rolled: $rolled,
            kind: $kind,
        );
    }

    /** The floor in force for a kind of roll, which is the default unless config says otherwise. */
    public function floorFor(?string $kind): float
    {
        return $kind === null ? $this->floor : ($this->floors[$kind] ?? $this->floor);
    }
}
