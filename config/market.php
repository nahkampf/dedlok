<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Market
|--------------------------------------------------------------------------
|
| How the market restocks itself. Item DEFINITIONS live in the database — a
| rarity and a seed quantity per row — and what lives here is the maths that
| turns those two numbers plus recent demand into a shelf count.
|
| Config rather than columns for the usual reason: these are global rules, not
| per-item data, and they are the numbers that will actually be tuned. See
| App\Market\MarketSeeder, which is the only thing that reads them.
|
*/

return [

    /*
    | The chance, in percent, that an item restocks AT ALL in a given pass.
    |
    | Rolled once per item per pass, all or nothing: a failed roll means the
    | shelf stays exactly as the players left it until the next pass eight
    | hours later. That is what makes a rare item rare — not a smaller pile,
    | but a shelf that is empty most of the time you look at it.
    |
    | Keyed by App\Market\Rarity's values, and it must stay exhaustive: a
    | rarity with no entry here would restock at 0%, which is indistinguishable
    | from an item nobody sells. MarketTest asserts every case is covered.
    */
    'seed_chance' => [
        'common' => 100,
        'uncommon' => 70,
        'rare' => 20,
        'ultra_rare' => 5,
    ],

    /*
    | Demand, and what it does to the shelf.
    |
    |     demand     = units bought in the last `window_days` days
    |     ratio      = demand / seed_quantity
    |     target     = ratio < threshold
    |                    ? seed_quantity
    |                    : seed_quantity * min(ratio / threshold, max_multiplier)
    |
    | The market then tops the shelf up TO that target — it does not add it.
    | Stock left over from last pass counts, so a shelf nobody is buying from
    | costs nothing to maintain.
    |
    | `ratio / threshold` rather than `ratio` so the two branches meet: at
    | exactly the threshold the multiplier is 1 and the formula agrees with the
    | quiet case. Anything else would put a step in the middle of the curve,
    | where one extra sale in seven days doubles the shelf.
    |
    | `max_multiplier` is the ceiling on that. Without it a run on an item
    | feeds itself: a big shelf is bought out, which is more demand, which is a
    | bigger shelf. Four times the intended stock is already a glut.
    */
    'demand' => [
        'window_days' => 7,
        'threshold' => 0.5,
        'max_multiplier' => 4.0,
    ],

];
