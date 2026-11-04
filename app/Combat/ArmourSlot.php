<?php

declare(strict_types=1);

namespace App\Combat;

use App\Support\Text;

/**
 * Where on the body a hit lands and a piece of armour is worn. docs/COMBAT.md.
 *
 * Six places, a fixed vocabulary: one head, one torso, two arms, two legs. NOT
 * `Implants\BodyRegion`, which counts how many implants a region holds at a coarser
 * grain and has no left and right; the two answer different questions and share no
 * cases on purpose.
 *
 * A piece of armour covers a SET of these (`armour.slots`), and the engine rolls one
 * for every hit. A new slot would be a case and a string.
 */
enum ArmourSlot: string
{
    case Head = 'head';
    case Torso = 'torso';
    case ArmLeft = 'arm_left';
    case ArmRight = 'arm_right';
    case LegLeft = 'leg_left';
    case LegRight = 'leg_right';

    public function label(): string
    {
        return Text::get("combat.slots.{$this->value}");
    }

    /**
     * Where a hit landed, from one die: `config('combat.body_parts')` walked in order, each slot
     * owning its weight's share of the total. Pure; the die is somebody else's.
     *
     * @param  float  $u  the die, uniform in [0, 1)
     */
    public static function roll(float $u): self
    {
        $weights = (array) config('combat.body_parts');
        $point = $u * array_sum($weights);
        $edge = 0.0;

        foreach ($weights as $slot => $weight) {
            $edge += $weight;

            if ($point < $edge) {
                return self::from((string) $slot);
            }
        }

        return self::Torso; // the rest, and a die of exactly 1.0
    }

    /** @return list<string> every slot's value, in body order */
    public static function values(): array
    {
        return array_map(static fn (self $slot): string => $slot->value, self::cases());
    }
}
