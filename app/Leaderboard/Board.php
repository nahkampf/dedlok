<?php

declare(strict_types=1);

namespace App\Leaderboard;

use App\Support\Text;

/** The boards, in the order the page draws them. The value is what `leaderboard_entries.board` holds. */
enum Board: string
{
    case Runs = 'runs';
    case NetWorth = 'net_worth';
    case SkillPoints = 'skill_points';
    case Explorers = 'explorers';
    case Scanners = 'scanners';
    case MurderHobos = 'murder_hobos';
    case Attributes = 'attributes';

    public function label(): string
    {
        return Text::get("leaderboard.boards.{$this->value}");
    }

    public function about(): string
    {
        return Text::get("leaderboard.about.{$this->value}");
    }

    /** The "nice" figures an approximate sum snaps to, per power of ten. */
    private const LADDER = [1, 2, 3, 5, 7];

    /** The figure beside a name. Net worth snaps to the nearest nice figure (20k, 500k, 1M), since the exact sum would say who is worth robbing. */
    public function figure(int $value): string
    {
        if ($this !== self::NetWorth) {
            return number_format($value);
        }

        $magnitude = 10 ** (int) floor(log10(max(1, $value)));
        $nearest = 10 * $magnitude;

        foreach (self::LADDER as $step) {
            if (abs($value - $step * $magnitude) < abs($value - $nearest)) {
                $nearest = $step * $magnitude;
            }
        }

        $power = (int) floor(log10($nearest) / 3);

        return rtrim(rtrim(number_format($nearest / 1000 ** $power, 1), '0'), '.').['', 'k', 'M', 'B', 'T'][$power];
    }
}
