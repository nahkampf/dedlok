<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Characters\DownedCause;
use App\Characters\WaitingRoom;
use App\Decks\DeckTotals;
use App\Models\Character;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Stats\Resolver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * A deck that stops running throws its owner out of cyberspace.
 *
 * `DeckTotals::isReady()` is checked on the way in. This is the other half:
 * the machine has to keep working while somebody is standing inside it, and
 * when it does not, they are jacked out and it costs them a point of Synaptic
 * Integrity. Not a warning and not a grace period — CYBERSPACE.md, *Readiness
 * is the gate, and it is checked twice*.
 *
 * THE DAMAGE IS SMALL AND IT IS NOT ZERO. The cause is usually not the
 * player's fault, which is why it is one point rather than a share of the
 * pool; being dumped out of cyberspace should never be free, which is why it
 * is not nothing. It carries **no defeat effect** — no ICE was involved, so
 * nothing flatlines, surges or erases.
 *
 * THE ORDER IS FIXED AND IT IS THE SAME ORDER EVERY BAD ENDING USES: damage,
 * then consequence, then `jackOut()`. A character must never be shown a
 * consequence while still holding a position on the grid. There is no
 * consequence in this one, which leaves two steps and the reason they are in
 * this order rather than the other.
 *
 * WHY IT IS ASKED IN TWO PLACES, and why that is not two rules:
 *
 *  - **In place**, by the screens that can change a character's modifiers
 *    while they are jacked in — installing or pulling an implant, starting or
 *    stopping a program. They are the ones that can say so at the moment it
 *    happens, and in the same breath as what the player actually asked for.
 *  - **By middleware**, on every request from somebody who is jacked in, as
 *    the net under everything else: a modifier that expired on its own, a
 *    console command, a change made by something that has not learned to ask.
 *
 * Both call this, so there is one rule with two alarms rather than two rules.
 * The net is a net: a player who is not clicking stays in cyberspace on a dead
 * deck until they next do, and closing that would take a scheduled sweep this
 * game does not have a reason for yet.
 *
 * **IT MUST NEVER BECOME THE ANSWER FOR SOMETHING HAPPENING DURING A RUN.** A
 * module burning out and a Surge destroying fitted hardware are their own
 * endings, with their own damage and their own ejection, and they resolve
 * where they happen. Asking this afterwards is harmless — the character is
 * already out, and an ejection that finds nobody standing anywhere does
 * nothing — but it is not what ends the run.
 *
 * Nothing can currently make this fire. The deck is locked while its owner is
 * inside it, so the only thing that can lower a deck measure is the resolver,
 * and no modifier in the game subtracts from one yet: skills move five
 * measures and only ever upwards. This exists because the day one does, the
 * failure is silent — a player carries on inside a machine that no longer
 * runs — and that day should not also be the day this gets written.
 */
final class ReadinessWatch
{
    public function __construct(
        private readonly Navigator $navigator,
        private readonly Resolver $resolver,
        private readonly WaitingRoom $waitingRoom,
        private readonly int $damage,
    ) {}

    public static function fromConfig(?Resolver $resolver = null): self
    {
        $resolver ??= app(Resolver::class);

        return new self(
            Navigator::fromConfig($resolver),
            $resolver,
            new WaitingRoom($resolver),
            (int) Config::get('cyberspace.readiness.damage', 1),
        );
    }

    /**
     * Is this character still standing on a machine that runs?
     *
     * Null is the ordinary answer and means nothing happened: they are not in
     * cyberspace, or their deck is fine. An Ejection means they were thrown
     * out, and the caller is expected to say so — this is not something to
     * discover on the next screen.
     *
     * Cheap to ask when the answer is no: a character who is not jacked in
     * costs one already-loaded column and nothing else. Expensive when they
     * are, because readiness is RESOLVED — the same numbers the deck screen
     * shows, which is what stops this refusing a deck the player is being told
     * is fine.
     */
    public function check(Character $character): ?Ejection
    {
        if (! $character->isJackedIn()) {
            return null;
        }

        // A deck cannot go missing underneath somebody who is jacked in —
        // unequipping and destroying are both refused by the deck lock — so
        // null here is not an ordinary state. It is still treated as unready
        // rather than as an exception: a character in cyberspace with no
        // machine is exactly the state this class exists to end.
        $deck = $character->equippedDeck;

        if ($deck !== null && DeckTotals::forCharacter($character, $deck, $this->resolver)->isReady()) {
            return null;
        }

        Trail::note('readiness_ejection', $character, ['reason' => $deck === null ? 'no deck' : 'deck not ready']);

        return DB::transaction(function () use ($character): Ejection {
            // Through the resolver, so a character who is good at surviving
            // this particular way of losing a deck is good at it — the
            // mitigation is modifiers on rows, and nothing here knows whether
            // any exist.
            $result = (new DamageResolver($this->resolver))->apply(
                $character,
                Damage::synapticIntegrity($this->damage, DamageOrigin::ReadinessLoss),
            );

            $this->navigator->jackOut($character);

            /*
             * THE ORDER EVERY BAD ENDING FOLLOWS, and this is the smallest one
             * there is: damage, then the consequence it causes, then the
             * ejection, then — only then — the waiting screen.
             *
             * The screen comes after `jackOut()` because a character must never
             * be sitting on it while still holding a position in cyberspace.
             * That is the one ordering CYBERSPACE.md says produces a state
             * nothing else knows how to read, and it is why this is four lines
             * apart rather than one call.
             *
             * ONE POINT CAN DO THIS, and it is meant to be able to. A character
             * on their last point of Synaptic Integrity, whose deck stops
             * running, goes down — which is a hard way to lose and not an
             * unfair one: they were standing in a machine on nothing, and the
             * watch is the thing that noticed.
             */
            $this->waitingRoom->checkFloor($character, new DownedCause(
                reason: 'readiness',
                damage: $result->taken,
                resource: 'current_synaptic_integrity',
            ));

            return new Ejection($result->taken);
        });
    }
}
