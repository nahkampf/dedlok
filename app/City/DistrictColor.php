<?php

declare(strict_types=1);

namespace App\City;

/**
 * The palette a district may be drawn in: the kit's decoration tokens, and
 * nothing that carries a meaning. A district's colour says WHICH district it is
 * and never what state it is in, so ember (live, critical) is not here and
 * neither is any semantic alias. A new case needs its token in `ui-kit.css`.
 */
enum DistrictColor: string
{
    case Steel = 'steel';
    case Cyan = 'cyan';
    case Brass = 'brass';
    case Violet = 'violet';
    case Clay = 'clay';
    case Jade = 'jade';
    case Moss = 'moss';

    /** The CSS value, for a `--hue` or `--tier` custom property. */
    public function token(): string
    {
        return "var(--{$this->value})";
    }
}
