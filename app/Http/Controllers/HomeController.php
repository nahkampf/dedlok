<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        // The catalogue's rarest few, from the items table. This page is public,
        // so it may only read definitions — never a character's inventory.
        // Physical only: the data container is skillbooks, which say nothing
        // about themselves that the skill they teach does not say better.
        $items = Item::query()
            ->inContainer(Item::CONTAINER_PHYSICAL)
            ->orderByDesc('tier')
            ->orderBy('slug')   // ties would otherwise order by insertion
            ->limit(3)
            ->get();

        $icu = config('i18n.locales.'.app()->getLocale().'.icu', app()->getLocale());

        return view('home', [
            'items' => $items,
            'icu' => $icu,
            'formatted' => [
                'date' => \IntlDateFormatter::formatObject(now(), \IntlDateFormatter::LONG, $icu),
                'number' => (new \NumberFormatter($icu, \NumberFormatter::DECIMAL))->format(48210.5),
                'relative' => Carbon::now()->subHours(3)->diffForHumans(),
            ],
        ]);
    }
}
