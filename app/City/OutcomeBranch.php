<?php

declare(strict_types=1);

namespace App\City;

/** When an outcome applies: always, or only on a passed or failed check. */
enum OutcomeBranch: string
{
    case Always = 'always';
    case Pass = 'pass';
    case Fail = 'fail';
}
