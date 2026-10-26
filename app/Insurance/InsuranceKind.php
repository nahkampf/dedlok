<?php

declare(strict_types=1);

namespace App\Insurance;

use App\Support\Text;

/** What a policy covers. */
enum InsuranceKind: string
{
    /** Hardware, programs, files and gear lost to ICE, a hunter, an encounter, a fight or thermal overload. */
    case Property = 'property';

    /** Permanent injuries: lost maximum hit points and Synaptic Integrity. */
    case Medical = 'medical';

    public function label(): string
    {
        return Text::get("insurance.kinds.{$this->value}");
    }
}
