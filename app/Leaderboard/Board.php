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

    public function label(): string
    {
        return Text::get("leaderboard.boards.{$this->value}");
    }

    /** Net worth shows who and in what order, never how much: it would say who is worth robbing. */
    public function showsFigures(): bool
    {
        return $this !== self::NetWorth;
    }
}
