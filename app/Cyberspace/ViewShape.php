<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Stats\Resolver;
use Illuminate\Support\Facades\Config;

/**
 * What a character sees from where they stand: a set of positions around them, by view range level.
 *
 * A LEVEL IS A SHAPE, not a radius (`cyberspace.view_shapes`, drawn as the picture it is). Level 0 is only the
 * position they stand on, and each level contains the one below. The level itself is the resolved stat `view_range`
 * (base `cyberspace.view_range`, a skill and a script or two add to it), so this class learns nothing about where
 * sight comes from.
 *
 * Offsets are relative to the player; `positionsAround()` is clipped to the grid by its caller.
 */
final class ViewShape
{
    public const MIN = 0;

    public const MAX = 9;

    /** @var array<int, self> */
    private static array $cache = [];

    /** @var array<string, true> "dx,dy" keys */
    private array $cells = [];

    /**
     * @param  list<array{0: int, 1: int}>  $offsets
     */
    private function __construct(
        public readonly int $level,
        public readonly array $offsets,
    ) {
        foreach ($offsets as [$dx, $dy]) {
            $this->cells["{$dx},{$dy}"] = true;
        }
    }

    /** The shape of a level, clamped to 0..9. Parsed once per level. */
    public static function for(int $level): self
    {
        $level = max(self::MIN, min(self::MAX, $level));

        return self::$cache[$level] ??= self::parse($level);
    }

    /** The base level of somebody with nothing improving their sight. */
    public static function base(): self
    {
        return self::for((int) Config::get('cyberspace.view_range', 2));
    }

    /** What THIS character sees: their resolved `view_range`. One call per arrival, and the caller keeps the answer. */
    public static function forCharacter(Character $character, Resolver $resolver): self
    {
        $level = $resolver
            ->resolve('view_range', (float) Config::get('cyberspace.view_range', 2), $character->modifiers())
            ->toInt();

        return self::for($level);
    }

    /** Is the position `dx`, `dy` from the player in view? */
    public function sees(int $dx, int $dy): bool
    {
        return isset($this->cells["{$dx},{$dy}"]);
    }

    /** Is `$to` in view of somebody standing at `$from`? */
    public function covers(Position $from, Position $to): bool
    {
        return $this->sees($to->x - $from->x, $to->y - $from->y);
    }

    /** How far the shape reaches in any direction: the radius of the smallest square that holds it. */
    public function radius(): int
    {
        $reach = 0;

        foreach ($this->offsets as [$dx, $dy]) {
            $reach = max($reach, abs($dx), abs($dy));
        }

        return $reach;
    }

    private static function parse(int $level): self
    {
        $rows = (array) Config::get("cyberspace.view_shapes.{$level}", []);
        $offsets = [[0, 0]];

        foreach (array_values($rows) as $y => $row) {
            foreach (str_split((string) $row) as $x => $char) {
                // The middle of the picture is the player, and is already in.
                if ($char === '*' && ! ($x === 4 && $y === 4)) {
                    $offsets[] = [$x - 4, $y - 4];
                }
            }
        }

        return new self($level, $offsets);
    }
}
