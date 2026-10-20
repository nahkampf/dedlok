<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

enum FortressRefusal: string
{
    case NotJackedIn = 'not_jacked_in';
    case Busy = 'busy';
    case NotAKernel = 'not_a_kernel';
    case NotYours = 'not_yours';
    case Occupied = 'occupied';
    case NoSkill = 'no_skill';
    case AtTheCap = 'at_the_cap';
    case NoCertificate = 'no_certificate';

    public function message(): string
    {
        return Text::get("cyberspace.fortress.refused.{$this->value}");
    }
}
