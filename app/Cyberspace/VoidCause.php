<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * Why a read came to nothing.
 *
 * TWO CAUSES, AND THEY ARE NOT THE PLAYER'S TO CONFUSE. One is about their
 * deck — the instrument stopped, was swapped, or the deck it ran on was taken
 * apart — and it is something they did and can avoid doing again. The other is
 * about the world: the machine burned or relocated while it was being read,
 * which nobody could have prevented and which says nothing at all about the
 * scanner.
 *
 * They shared one sentence for a while, and it named the instrument. That sent
 * anybody who lost a read to a stale machine off to inspect a deck that was
 * working perfectly.
 *
 * The pass is spent either way, which is why this is a reason rather than a
 * refusal: the read ran, and it came to nothing.
 */
enum VoidCause: string
{
    /** The scanner was stopped, swapped, or its deck taken apart mid-read. */
    case InstrumentStopped = 'instrument';

    /** The machine stopped answering — burned, or relocated and wearing a new address. */
    case TargetGone = 'target';

    public function message(): string
    {
        return Text::get(match ($this) {
            self::InstrumentStopped => 'cyberspace.scan.voided',
            self::TargetGone => 'cyberspace.scan.gone',
        });
    }
}
