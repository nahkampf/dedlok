<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        // The catalogue's rarest few, from the items table. This page is public,
        // so it may only read definitions — never a character's inventory.
        // Physical only: the data container is skillbooks, which say nothing
        // about themselves that the skill they teach does not say better.
        // Listed only. `on_market = false` is how content is RETIRED in this
        // codebase — the documented alternative to deleting it — so an
        // unlisted item on the public landing page is the catalogue
        // advertising something deliberately withdrawn. It is also what keeps
        // placeholders and admin scaffolding off the front page.
        $items = Item::query()
            ->inContainer(Item::CONTAINER_PHYSICAL)
            ->where('on_market', true)
            ->orderByDesc('tier')
            ->orderBy('slug')   // ties would otherwise order by insertion
            ->limit(3)
            ->get();

        return view('home', [
            'items' => $items,
        ]);
    }
}
