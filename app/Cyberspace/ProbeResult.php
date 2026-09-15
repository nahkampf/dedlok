<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Checks\CheckOutcome;
use App\Models\SystemScan;

/**
 * What one pass of a probe came to.
 *
 * Carries the scan it wrote, what this pass found out, and the rolls
 * themselves. The rolls are here for the reason `CheckOutcome` carries its
 * chance: a player who learns nothing on a 12% pass has learned something about
 * the machine, and a screen that only says "nothing" is a screen that teaches
 * them to stop trying.
 */
final class ProbeResult
{
    /**
     * @param  list<string>  $found  The properties this pass revealed.
     * @param  array<string, CheckOutcome>  $rolls  Keyed by property.
     */
    private function __construct(
        public readonly ?SystemScan $scan,
        public readonly array $found,
        public readonly array $rolls,
        public readonly ?ProbeRefusal $refusal,
        public readonly bool $voided = false,
    ) {}

    /**
     * @param  list<string>  $found
     * @param  array<string, CheckOutcome>  $rolls
     */
    public static function scanned(SystemScan $scan, array $found, array $rolls): self
    {
        return new self($scan, $found, $rolls, null);
    }

    /**
     * A pass has been started and will have an answer later.
     *
     * No `found` and no rolls, because nothing has been decided: the roll is
     * made when the pass matures, by whoever is standing there then.
     */
    public static function begun(SystemScan $scan): self
    {
        return new self($scan, [], [], null);
    }

    public static function refused(ProbeRefusal $refusal): self
    {
        return new self(null, [], [], $refusal);
    }

    /**
     * The read was lost: the scanner stopped before it finished.
     *
     * A success in the sense that nothing was refused — the pass ran and came
     * to nothing — which is why it is a flag on a result rather than a
     * refusal. The pass is spent either way.
     */
    public static function voided(SystemScan $scan): self
    {
        return new self($scan, [], [], null, true);
    }

    /** Started rather than finished: there is a clock running on this scan. */
    public function isRunning(): bool
    {
        return $this->scan?->isRunning() ?? false;
    }

    public function succeeded(): bool
    {
        return $this->refusal === null;
    }

    /** Did this pass learn anything at all? A pass that found nothing is ordinary. */
    public function foundSomething(): bool
    {
        return $this->found !== [];
    }
}
