<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Skills\SkillProgression;

abstract class Controller
{
    /**
     * What a shop card needs beyond its listing, as the market draws it: the
     * item ids owned anywhere (the corner tick), skill id => level and points
     * (the tick's cyan and an imprint's detail), and the top level.
     *
     * @return array{owned: array<int, int>, injected: array<int, array{level: int, points: int}>, maxLevel: int}
     */
    protected function shopContext(Character $character): array
    {
        return [
            'owned' => $character->inventory()->distinct()->pluck('item_id')->flip()->all(),
            'injected' => $character->characterSkills()
                ->get(['skill_id', 'attained_level', 'skill_points'])
                ->mapWithKeys(fn ($row): array => [
                    (int) $row->skill_id => ['level' => (int) $row->attained_level, 'points' => (int) $row->skill_points],
                ])
                ->all(),
            'maxLevel' => SkillProgression::fromConfig()->maxLevel(),
        ];
    }
}
