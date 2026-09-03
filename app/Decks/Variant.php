<?php

declare(strict_types=1);

namespace App\Decks;

use App\Support\Text;

/**
 * The sub-type of a socket, or of the module that goes in one.
 *
 * One enum for both sides rather than a family per kind, because the question
 * they exist to answer — does this fit that — is asked once, here, and asking
 * it across four little enums would mean four ways to spell the same match.
 * Which values are legal for which kind is App\Decks\SlotKind's business.
 *
 * `Universal` is the only value that is a socket and never a module: a RAM
 * slot may take either kind of stick, but no stick is both. That asymmetry is
 * the whole reason accepts() is not `===`.
 */
enum Variant: string
{
    // CPU sockets, by pin count.
    case SocketG = 'socket_g';

    case SocketK = 'socket_k';

    case SocketZ = 'socket_z';

    // RAM.
    case Opto = 'opto';

    case QDram = 'qdram';

    /** A RAM socket wired for both. Never a module. */
    case Universal = 'universal';

    // Expansion buses.
    case XGate = 'xgate';

    case Pei = 'pei';

    case Horizon = 'horizon';

    // The two ways a deck reaches a nervous system.
    case Electrode = 'electrode';

    case Neuralink = 'neuralink';

    /**
     * May a module of variant $module go into a socket of this variant?
     *
     * Identity everywhere except `Universal`, which is the point of it. A
     * module with no variant at all (an NPU, a NIC, a PSU) never reaches
     * here — its socket has no variant either.
     */
    public function accepts(?Variant $module): bool
    {
        if ($module === null) {
            return false;
        }

        return match ($this) {
            self::Universal => $module === self::Opto || $module === self::QDram,
            default => $this === $module,
        };
    }

    /**
     * Everything a module may be. `Universal` is not among them, and neither
     * are the two IO ports: nothing is fitted into those yet.
     *
     * @return list<self>
     */
    public static function forModules(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $variant): bool => $variant !== self::Universal
                && $variant !== self::Electrode
                && $variant !== self::Neuralink,
        ));
    }

    /** The short name, for a spec line. `name` is taken — enums define it. */
    public function label(): string
    {
        return Text::get("decks.variants.{$this->value}");
    }

    /**
     * The extra half-sentence the editor shows beside the label — a pin
     * count, what the initials stand for. Nothing on screen depends on one
     * existing, so a variant without a note simply has none.
     */
    public function note(): ?string
    {
        $key = "decks.notes.{$this->value}";

        return Text::has($key) ? Text::get($key) : null;
    }
}
