<?php

declare(strict_types=1);

namespace App\Programs;

use App\Support\Text;

/**
 * What a program is for.
 *
 * The kind is asked about by NAME, because programs are gates as much as they
 * are modifiers: you cannot fight ICE without a running icebreaker, and no
 * amount of bonuses adds up to having one. So this is not decoration and it is
 * not derivable from the modifiers a program happens to carry.
 *
 * These are the kinds CYBERSPACE.md actually names work for. A sixth is a case
 * here, a line in the schema's CHECK, and a string — the same shape
 * App\Decks\Hardware has.
 */
enum ProgramKind: string
{
    /** Fights ICE. Without one running, a fight cannot be had at all. */
    case Icebreaker = 'icebreaker';

    /** Breaks the encryption on a stolen file. The background process. */
    case Decryptor = 'decryptor';

    /** Keeps an IDS, a tracer or ICE from noticing you. */
    case Evasion = 'evasion';

    /** Reads a system: what it runs, what it is carrying, what is cloaked. */
    case Analyser = 'analyser';

    /** Everything that quietly does a job — the catch-all, kept narrow. */
    case Daemon = 'daemon';

    /**
     * May a program of this kind run outside a cyberspace run?
     *
     * A default rather than a rule: `programs.background_process` is the
     * per-program answer and this is what the editor offers first. An
     * icebreaker outside a run is a deck spending compute on nothing, which is
     * worse than useless — it is a run that will not start.
     */
    public function runsInBackgroundByDefault(): bool
    {
        return match ($this) {
            // An analyser reads a machine you are standing in front of, which
            // is something you do on the open grid and not only inside a run:
            // scanning IS the safe run type. It idles and listens, which is
            // the shape of a background job.
            self::Decryptor, self::Daemon, self::Analyser => true,
            self::Icebreaker, self::Evasion => false,
        };
    }

    /** Is this kind meaningful only while a run is happening? */
    public function isRunOnly(): bool
    {
        return ! $this->runsInBackgroundByDefault();
    }

    public function label(): string
    {
        return Text::get("programs.kinds.{$this->value}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
