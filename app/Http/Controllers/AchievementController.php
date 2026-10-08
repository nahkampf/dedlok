<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Achievements\Achievements;
use App\Models\User;
use App\Support\Text;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What this account has done.
 *
 * THE ACCOUNT, NOT THE CHARACTER, which is the only thing on this screen that
 * is different from every other screen in the game: the rail item sits beside
 * Skills and Stash, and the page behind it is the one place a player is
 * looking at themselves rather than at the person they are playing.
 *
 * WHAT IS SHOWN IS `Achievements::listableFor()` AND NOTHING ELSE. The secret
 * ones are filtered there rather than here, so a second surface cannot leak
 * one by asking a slightly different question.
 */
class AchievementController extends Controller
{
    public function index(Request $request, Achievements $achievements): View
    {
        /** @var User $user */
        $user = $request->user();

        $rows = [];
        $percent = $achievements->percentages();

        foreach ($achievements->listableFor($user) as $id => $entry) {
            $rows[] = [
                'id' => $id,
                'name' => Text::get('achievements.name', ['id' => $id]),
                'description' => Text::get("achievements.description.{$id}"),
                'unlocked' => $entry['unlocked'],
                'share' => Text::get('achievements.earned_by', ['percent' => $percent[$id]]),
            ];
        }

        /*
         * ALPHABETICALLY, and naturally so — `strnatcasecmp` rather than
         * `strcmp` because the names are placeholders numbered 1 to 13 today
         * and a plain sort would read 1, 10, 11, 2. For the real names, which
         * is what this list will actually hold, the two answer the same.
         */
        usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return view('achievements.index', [
            'gained' => array_values(array_filter($rows, static fn (array $row): bool => $row['unlocked'])),
            'remaining' => array_values(array_filter($rows, static fn (array $row): bool => ! $row['unlocked'])),
        ]);
    }
}
