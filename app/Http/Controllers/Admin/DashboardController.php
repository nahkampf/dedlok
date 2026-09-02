<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Skill;
use Illuminate\View\View;

/**
 * The way in to the content tools.
 *
 * Deliberately thin: it counts what there is and links to the two editors.
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
                ->whereDoesntHave('skillbook')
                ->count(),
        ]);
    }
}
