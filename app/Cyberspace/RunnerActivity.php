<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/** What another runner on the map is doing right now: the first that holds, in this order. */
enum RunnerActivity: string
{
    case Running = 'running';
    case Scanning = 'scanning';
    case Idle = 'idle';

    public function label(): string
    {
        return Text::get("cyberspace.map.activity.{$this->value}");
    }
}
