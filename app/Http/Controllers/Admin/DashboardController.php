<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Item;
use App\Models\Skill;
use Illuminate\View\View;

/**
 * The way in to the content tools.
 *
 * Deliberately thin: it counts what there is and links to the editors.
 * Anything that looks like a metric belongs on the thing it measures.
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.index', [
            'items' => Item::count(),
            'itemsOnMarket' => Item::onMarket()->count(),
            'skills' => Skill::count(),
            'skillsWithoutBooks' => Skill::query()
                ->whereDoesntHave('imprint')
                ->count(),
            'archetypes' => Archetype::count(),
            // An archetype that hands out nothing is not broken, but it is
            // almost always unfinished — the loadout is the newest thing on
            // the row and the one most likely to have been left empty.
            'archetypesWithoutLoadout' => Archetype::query()
                ->whereDoesntHave('startingSkills')
                ->whereDoesntHave('startingItems')
                ->where('starting_creds', 0)
                ->count(),
        ]);
    }
}
