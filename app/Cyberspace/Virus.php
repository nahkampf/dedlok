<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Characters\DownedCause;
use App\Characters\WaitingRoom;
use App\Models\Character;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Stats\Resolver;
use App\Support\Points;

/**
 * The Virus attack tag's infection (CYBERSPACE.md, *Virus*), and its only writer.
 *
 * ONE INFECTION AT A TIME, kept on the character so it outlives the run: a new hit refreshes it rather than stacking. It
 * takes `virus_damage` Synaptic Integrity on its first tick and less each tick after, reaching nothing by the end of its
 * lifetime. A tick is a run turn inside a run and a minute outside one (`characters:regenerate`).
 *
 * The lifetime is the `virus_lifetime` stat, started from the tag's own length and read on EVERY tick, so an Antiviral
 * (any program whose modifiers lower that stat) started after the infection still shortens it. Damage goes through
 * `DamageResolver` as ICE damage, so a resistance to ICE is a resistance to the virus.
 */
final readonly class Virus
{
    public function __construct(private Resolver $resolver) {}

    public static function make(): self
    {
        return new self(app(Resolver::class));
    }

    public function infected(Character $character): bool
    {
        return $character->virus_damage !== null;
    }

    /** Infect (or refresh) at a tag level; the numbers are `cyberspace.attack_tags.virus`. */
    public function infect(Character $character, int $level): void
    {
        $row = AttackTag::Virus->levelValue($level) ?? [];

        $character->forceFill([
            'virus_damage' => (float) ($row['damage'] ?? 0),
            'virus_turns' => (int) ($row['turns'] ?? 1),
            'virus_elapsed' => 0,
        ])->save();
    }

    public function cure(Character $character): void
    {
        $character->forceFill(['virus_damage' => null, 'virus_turns' => null, 'virus_elapsed' => 0])->save();
    }

    /** Ticks the infection has altogether, after any Antiviral. */
    public function lifetime(Character $character): int
    {
        return max(1, (int) $this->resolver->resolve('virus_lifetime', (float) $character->virus_turns, $character->modifiers())->value);
    }

    /** Ticks left before it clears. */
    public function remaining(Character $character): int
    {
        return $this->infected($character) ? max(0, $this->lifetime($character) - (int) $character->virus_elapsed) : 0;
    }

    /**
     * One tick. Null when there is nothing to do; else what it took and whether that emptied the character.
     *
     * @return ?array{taken: int|float, emptied: bool}
     */
    public function tick(Character $character): ?array
    {
        if (! $this->infected($character)) {
            return null;
        }

        $lifetime = $this->lifetime($character);
        $elapsed = (int) $character->virus_elapsed;

        if ($elapsed >= $lifetime) {
            $this->cure($character);

            return null;
        }

        $amount = Points::of((float) $character->virus_damage * (1 - $elapsed / $lifetime));
        $taken = 0;

        if ($amount > 0) {
            $taken = (new DamageResolver($this->resolver))->apply($character, Damage::synapticIntegrity($amount, DamageOrigin::Ice))->taken;
        }

        if ($elapsed + 1 >= $lifetime || $amount <= 0) {
            $this->cure($character);
        } else {
            $character->forceFill(['virus_elapsed' => $elapsed + 1])->save();
        }

        return ['taken' => $taken, 'emptied' => $character->fresh()->current_synaptic_integrity <= 0];
    }

    /**
     * The minute clock's tick, for somebody not in a run: the bad-ending order if it empties them (jack out, then the
     * floor), and a downed character is clean.
     */
    public function tickAway(Character $character): void
    {
        $result = $this->tick($character);

        if ($result === null || ! $result['emptied']) {
            return;
        }

        Navigator::fromConfig($this->resolver)->jackOut($character);
        (new WaitingRoom($this->resolver))->checkFloor($character, new DownedCause(
            reason: 'virus',
            damage: $result['taken'],
            resource: 'current_synaptic_integrity',
        ));
        $this->cure($character);
    }
}
