<?php

declare(strict_types=1);

namespace App\Programs;

use RuntimeException;

/**
 * A program that was meant to start would not, inside a transaction that must now be undone.
 *
 * Thrown rather than unwound by stopping what already started: stopping a Script destroys it, so the only
 * honest way back from half a loadout is the rollback.
 */
final class ProcessRefused extends RuntimeException
{
    public function __construct(public readonly ProcessRefusal $refusal)
    {
        parent::__construct($refusal->value);
    }
}
