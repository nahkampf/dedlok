<?php

declare(strict_types=1);

namespace App\Cyberspace;

use Closure;
use Illuminate\Support\Facades\Config;

/**
 * What a machine defends itself with, decided once and then carried on the row.
 *
 * TYPE SETS THE FLOOR AND GRADE ROLLS THE REST. Every kind of machine
 * guarantees two countermeasures and leaves the third to chance, and every
 * countermeasure is guaranteed by two types and rolled by one — so what a scan
 * is for is never "does this have anything", it is which of the three this one
 * happens to be missing.
 *
 * PURE, AND THE ROLL IS INJECTED, the same shape as `Grade::roll()` and for the
 * same reason: a probabilistic generator tested against real randomness is a
 * test that passes most of the time.
 *
 * ## Why there is no override mechanism
 *
 * Because there is nothing to override. This is asked once, by `GridSeeder`, at
 * the moment a machine is created, and the answer lands in columns — so a
 * mission node that has to be dangerous is a row with different numbers in the
 * same columns. Nothing derives on read, so nothing has to be told not to.
 * `systems.stock_rule` exists because a node's CONTENTS are re-rolled nightly
 * and therefore need a standing instruction; defences never are.
 *
 * ## ICE is not here yet
 *
 * It is the third countermeasure and `defences.always` names it, because the
 * rotation is unreadable without it — the compute node rolls for its detection
 * precisely because its ICE and tracer are certain. What ICE has that these two
 * do not is combat numbers: integrity, an attack, and a defeat tag, none of
 * which can be calibrated until something runs a turn. It arrives as a fourth
 * field here and changes nothing else.
 */
final readonly class Defences
{
    private function __construct(
        public int $idsDetection,
        public int $tracerDetection,
        public int $tracerRate,
    ) {}

    /** A machine that defends itself with nothing. The portal, and any misread type. */
    public static function none(): self
    {
        return new self(0, 0, 0);
    }

    /**
     * What a newly instantiated machine carries.
     *
     * @param  Closure(int, int): int  $roll  Inclusive both ends, like random_int.
     */
    public static function roll(SystemType $type, int $grade, Closure $roll): self
    {
        if (! $type->isInteractable()) {
            return self::none();
        }

        $guaranteed = self::guaranteedFor($type);
        $chance = self::table('presence')[$grade] ?? 0;

        // The one its type does not guarantee is the one that gets rolled for.
        // Never zero and never certain: a grade 1 that happens to have a tracer
        // is the machine that teaches somebody to scan.
        $carries = static fn (string $what): bool => in_array($what, $guaranteed, true)
            || $roll(1, 100) <= $chance;

        $ids = $carries('ids') ? (self::table('ids')[$grade] ?? 0) : 0;

        // Rolled before either value is read, because the roll has to happen
        // exactly once for the pair — asking twice would be two chances at one
        // tracer, and the CHECK constraint would catch it only half the time.
        $tracer = $carries('tracer');

        return new self(
            $ids,
            $tracer ? (self::table('tracer.detection')[$grade] ?? 0) : 0,
            $tracer ? (self::table('tracer.rate')[$grade] ?? 0) : 0,
        );
    }

    /** Ready to be spread into a `systems` row. */
    public function columns(): array
    {
        return [
            'ids_detection' => $this->idsDetection,
            'tracer_detection' => $this->tracerDetection,
            'tracer_rate' => $this->tracerRate,
        ];
    }

    /**
     * What this type of machine always has.
     *
     * An unconfigured type carries nothing rather than throwing: a world of
     * harmless machines beats no world, which is the call `Grade::roll()`
     * already makes about its own table.
     *
     * @return list<string>
     */
    private static function guaranteedFor(SystemType $type): array
    {
        /** @var array<string, list<string>> $always */
        $always = Config::get('cyberspace.defences.always', []);

        return $always[$type->value] ?? [];
    }

    /** @return array<int, int> */
    private static function table(string $key): array
    {
        /** @var array<int|string, int|string> $configured */
        $configured = Config::get("cyberspace.defences.{$key}", []);
        $table = [];

        foreach ($configured as $grade => $value) {
            if (Grade::isValid((int) $grade)) {
                $table[(int) $grade] = (int) $value;
            }
        }

        return $table;
    }
}
