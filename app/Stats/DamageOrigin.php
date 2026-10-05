<?php

declare(strict_types=1);

namespace App\Stats;

use App\Support\Text;

/**
 * What hurt somebody.
 *
 * Damage is never just a number in this game: a point of Synaptic Integrity
 * lost because a deck stopped running and a point lost to ICE are the same
 * arithmetic and different events, and a character is allowed to be good at
 * surviving one of them and not the other. The origin is what makes that
 * expressible — it is half of the stat a damage event resolves as, and it is
 * a TAG, so a modifier can name it without naming the resource.
 *
 * ONE CASE PER CAUSE, and a cause earns a case by existing in the world rather
 * than by being imaginable. (The eight DAMAGE TYPES below are the one exception:
 * they exist before anything deals them, because physical combat is designed in
 * COMBAT.md and armour, implants and drugs resist them from the day it is worn.) There is no vocabulary here for falling off a
 * building, because nothing in this game falls off anything yet. A seventh
 * origin is a case, a string, and nothing else — every stat that needs it is
 * generated from this list by App\Stats\DamageStats.
 *
 * The value is the tag, PLAIN and unprefixed, the way `deck` is: a modifier
 * targets `all_of: ['damage', 'ice']` and reads as a sentence. If a non-damage
 * stat ever wants one of these words for something else, that is the day they
 * get a prefix, and the generator is the only thing that would have to change.
 */
enum DamageOrigin: string
{
    /**
     * The deck stopped meeting its own requirements while its owner was
     * standing inside it. See App\Cyberspace\ReadinessWatch — the only origin
     * anything can currently deal.
     */
    case ReadinessLoss = 'readiness_loss';

    /**
     * A module cooked itself mid-run and took the machine down with it.
     * CYBERSPACE.md, *Burning out a module ends the run*; nothing deals it
     * yet, because nothing runs a deck over time.
     */
    case Burnout = 'burnout';

    /**
     * Something in a system fought back. The defeat TAG says what reaching
     * zero does — flatline, neural shock, surge — and this says who did it,
     * which is
     * the half a resistance cares about. Nothing deals it until the run
     * simulator exists.
     */
    case Ice = 'ice';

    /**
     * The machine went into lockdown, or out of existence, under somebody who
     * was standing in it. See App\Cyberspace\Lockdown.
     *
     * A SEPARATE ORIGIN FROM A READINESS LOSS even though it costs the same
     * flat point, because an origin is the axis a resistance is written
     * against: "tougher when your own deck fails" and "tougher when the world
     * moves out from under you" are different sentences, and folding them into
     * one would make the second unwritable.
     */
    case RunCancelled = 'run_cancelled';

    /**
     * Too much of something, taken on purpose.
     *
     * A SEPARATE ORIGIN AND NOT A KIND OF ICE, because it is the one thing in
     * this list somebody did to themselves. "Tougher when your own deck fails"
     * and "tougher when you take too much" are different sentences and a
     * constitution is exactly the modifier that would write the second one.
     *
     * See App\Drugs\DrugTaker. The neural damage an overdose also deals is an
     * INJURY rather than damage, so it does not carry this — mitigation has
     * nothing to say about a ceiling coming down.
     */
    case Overdose = 'overdose';

    /** Something that happened in the City, by an author's hand. A resistance is written against `all_of: ['damage', 'encounter']`. */
    case Encounter = 'encounter';

    /*
     * THE DAMAGE TYPES — what KIND of hurt a weapon does, which is the axis armour,
     * plating, a skill or a painkiller is written against. COMBAT.md.
     *
     * A hit is split across them by the weapon's profile, and each part resolves on
     * its own, so a vest that stops a round and does nothing against a blade falls
     * out of the arithmetic. Two families, as a second TAG, so one modifier can
     * cover several: `all_of: ['damage', 'kinetic']` is everything that is a blow,
     * a cut, a round or a blast; `all_of: ['damage', 'specialty']` is the rest.
     * ("Kinetic" and not "physical": the game already calls damage to the body
     * physical damage, and the two would be read as one.)
     */

    /** A blow: bats, fists, a fall. */
    case Blunt = 'blunt';

    /** A cut: knives, katanas. */
    case Slashing = 'slashing';

    /** A round through the target: firearms. */
    case Penetrating = 'penetrating';

    /** A burst of pieces: grenades, shotguns. */
    case Fragmentation = 'fragmentation';

    /** Current through the body: stun batons, tasers. */
    case Electrical = 'electrical';

    /** Heat: incendiaries, plasma. */
    case Thermal = 'thermal';

    /** A toxin or an acid. */
    case Chemical = 'chemical';

    /** Damage to the mind: a neural stunner, hostile hardware. The one type that hurts Synaptic Integrity. */
    case Neural = 'neural';

    /**
     * The eight damage TYPES, in the order the editor lists them: the origins that name a KIND
     * of hurt (a blow, a blade, a round…) rather than a cause (ICE, an overdose).
     *
     * @return list<self>
     */
    public static function types(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $origin): bool => $origin->family() !== null));
    }

    /**
     * Which resource this origin hurts by default, or null when it has none of its own.
     *
     * Only the damage types say: seven hurt the body and `neural` the mind. The older
     * origins are causes that may land on either (ICE and a deck failure both cost
     * Synaptic Integrity, an overdose costs the body), and the stats exist for every
     * pair either way — this is the default a weapon is built with, not a limit.
     */
    public function resource(): ?string
    {
        return match ($this) {
            self::Blunt, self::Slashing, self::Penetrating, self::Fragmentation,
            self::Electrical, self::Thermal, self::Chemical => 'current_hitpoints',
            self::Neural => 'current_synaptic_integrity',
            default => null,
        };
    }

    /**
     * The family tag this origin also carries, or null for a cause that is not a type.
     *
     * Null adds NO tag: the six older origins keep exactly the tags they had.
     */
    public function family(): ?string
    {
        return match ($this) {
            self::Blunt, self::Slashing, self::Penetrating, self::Fragmentation => 'kinetic',
            self::Electrical, self::Thermal, self::Chemical, self::Neural => 'specialty',
            default => null,
        };
    }

    /** The tag a modifier names to reach every damage stat of this origin. */
    public function tag(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return Text::get("damage.origins.{$this->value}");
    }
}
