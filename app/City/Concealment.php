<?php

declare(strict_types=1);

namespace App\City;

/** How well an explorable location hides. Phase 2 maps each to a difficulty in `config/city.php`. */
enum Concealment: string
{
    case Trivial = 'trivial';
    case Difficult = 'difficult';
    case Obscure = 'obscure';
    case Secretive = 'secretive';
}
