<?php

declare(strict_types=1);

/*
| Anything counted goes through trans_choice(), never string concatenation.
| Laravel's pipe syntax handles English's two forms; languages with more
| (Polish has three, Arabic six) get the extra branches in their own file
| without any change here or at the call site.
*/

return [
    'hits' => '{0} No hits|{1} :count hit|[2,*] :count hits',
    'damage' => ':count damage',
    'enemies' => '{0} The street is empty|{1} One hostile|[2,*] :count hostiles',
    'log' => ':attacker hits :target for :damage.',
];
