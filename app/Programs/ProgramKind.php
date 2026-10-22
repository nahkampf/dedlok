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

    /** Spent by running: a program with a lifetime (`programs.lifetime_seconds`) that acts like a timed modifier. */
    case Script = 'script';

    /**
     * A helper that runs in the background on the deck and does one or more of the things the `programs` flags `locates`,
     * `walks` and `jumps` name. Locates: finds the nearest system you have met that fits a description (the map screen offers
     * its search while it runs). Walks: walks you to what a Locator found (Autotraverse). Jumps: puts a Jump button beside
     * every address. A Pathfinder is a program that locates and walks.
     */
    case Utility = 'utility';

    /**
     * Steals one of compute, RAM or storage from the system it is installed on for a Data Fortress, and is spent by
     * installing it (a run action), never run. Its `qflops_cost`, `memory_cost` and `storage_size` are what it TAKES, and
     * exactly one is non-zero (the first non-zero of compute, RAM, storage is the resource).
     */
    case Leecher = 'leecher';

    /** Programs hosted on a Data Fortress, never run on a deck. Tier order for the shutdown: Decryptors and Assemblers, the helpers, IDS, ICE. */
    /** Turns the Encrypted Data Dumps held in the fortress into components, one at a time, deterministically. */
    case FortressDecryptor = 'fortress_decryptor';
    /** Makes a program from a Design and components held in the fortress, one Design at a time. */
    case FortressAssembler = 'fortress_assembler';
    /** Steals Encrypted Data Dumps from the nodes around the fortress into the Decryptors' input area, one roll per node per cycle. */
    case FortressNetworkTap = 'fortress_network_tap';
    /** Skims credits from the nodes around the fortress into its wallet, one roll per node per cycle. */
    case FortressWiretap = 'fortress_wiretap';
    /** A helper that makes the fortress's producers work faster (its `produces.boost`, per cent). */
    case Booster = 'booster';
    /** The fortress's public market: lists files from one storage area. */
    case Market = 'market';
    /** Makes the fortress read as empty; it has a strength, a decloaker beats it. */
    case Cloak = 'cloak';
    /** Moves the fortress every so many hours. */
    case Relocation = 'relocation';
    /** Lets the fortress's owner and members tunnel straight to it from anywhere, bypassing any gateway (`FortressTunnel`). */
    case Tunnel = 'tunnel';
    case FortressIds = 'fortress_ids';
    case FortressIce = 'fortress_ice';
    /** An optional crafted Kernel item; the fortress's own Kernel is implicit (config). Highest tier. */
    case Kernel = 'kernel';

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
            self::Decryptor, self::Daemon, self::Analyser, self::Script, self::Utility => true,
            self::Icebreaker, self::Evasion, self::Leecher,
            self::FortressDecryptor, self::FortressAssembler, self::FortressNetworkTap, self::FortressWiretap, self::Booster, self::Market, self::Cloak, self::Relocation,
            self::FortressIds, self::FortressIce, self::Kernel, self::Tunnel => false,
        };
    }

    /** Never run on a deck: installed (on a system, or on a fortress). */
    public function neverRunsOnADeck(): bool
    {
        return $this === self::Leecher || $this->isHosted();
    }

    /** Hosted on a Data Fortress. */
    public function isHosted(): bool
    {
        return in_array($this, [self::FortressDecryptor, self::FortressAssembler, self::FortressNetworkTap, self::FortressWiretap, self::Booster, self::Market, self::Cloak, self::Relocation, self::Tunnel, self::FortressIds, self::FortressIce, self::Kernel], true);
    }

    /** Where it sits in the shutdown order: the lowest goes first, the Kernel last. */
    public function tier(): int
    {
        return match ($this) {
            self::FortressDecryptor, self::FortressAssembler, self::FortressNetworkTap, self::FortressWiretap => 0,
            self::Booster, self::Market, self::Cloak, self::Relocation, self::Tunnel => 1,
            self::FortressIds => 2,
            self::FortressIce => 3,
            default => 4,
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
