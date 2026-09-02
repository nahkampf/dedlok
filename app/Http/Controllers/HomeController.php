<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        // Stand-in for the items table: slug + stats only. Every player-visible
        // string is looked up from lang/<locale>/items.php by slug.
        $items = [
            ['slug' => 'sandevistan_mk5',        'tier_class' => 't5', 'damage' => 0,  'weight' => 1],
            ['slug' => 'kiroshi_optics',         'tier_class' => 't4', 'damage' => 0,  'weight' => 1],
            ['slug' => 'ballistic_coprocessor',  'tier_class' => 't3', 'damage' => 12, 'weight' => 2],
        ];

        $icu = config('i18n.locales.'.app()->getLocale().'.icu', app()->getLocale());

        return view('home', [
            'items' => $items,
            'formatted' => [
                'date' => \IntlDateFormatter::formatObject(now(), \IntlDateFormatter::LONG, $icu),
                'number' => (new \NumberFormatter($icu, \NumberFormatter::DECIMAL))->format(48210.5),
                'relative' => Carbon::now()->subHours(3)->diffForHumans(),
            ],
        ]);
    }
}
