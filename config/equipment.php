<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The belt
    |--------------------------------------------------------------------------
    |
    | How many slots the belt has. A slot holds one inventory ROW, so a pile of stims takes one slot however many
    | are in it. Only drugs may be belted today (`App\Equipment\BeltEquipper`).
    |
    | The column behind it, `inventory_items.belt_slot`, has no upper bound in the schema: this is the one number.
    */
    'belt_slots' => 3,
];
