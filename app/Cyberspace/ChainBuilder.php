<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Models\Backdoor;
use App\Models\Character;
use App\Models\System;
use App\Stats\Resolver;
use Illuminate\Support\Collection;

/**
 * The route a run is about to take, worked out rather than asked for.
 *
 * ## Nobody picks a chain
 *
 * docs/CYBERSPACE.md says the chain is declared per run rather than held standing,
 * and the first reading of that was a picker: a screen where a player chooses
 * which of their backdoors to put on the table. There is nothing to choose.
 * Every routing backdoor a character holds is free to use, costs nothing to
 * include and hinders the trace by exactly as much as its machine's grade — so
 * a picker would be a list where the correct answer is "all of them, best
 * first", asked on every single run.
 *
 * So it is built: everything available, **highest grade first**, capped at
 * `routing_hops`. What the player decides is which machines to hold a routing
 * backdoor on, which is a decision made one backdoor run at a time.
 *
 * ## Highest grade first, and why the order barely matters
 *
 * A trace has to pass every hop in the chain, so the order changes nothing
 * about the total delay it can expect — only the shape of it. Best first means
 * the hardest machine is the one a trace meets while the run is youngest,
 * which is when a held turn is worth most. It is also the order a player would
 * have chosen, which is the point of not asking them.
 *
 * ## It is a PREVIEW before it is a commitment
 *
 * The same call answers the setup screen and the run that follows, so what a
 * player was shown is what they got. Between the two the world may have moved
 * — that is what the degradation rule is for, and `Run::routingGrades()` is
 * where it lives.
 */
final readonly class ChainBuilder
{
    public function __construct(
        private Backdoors $backdoors,
        private Resolver $resolver,
    ) {}

    public static function fromConfig(?Resolver $resolver = null): self
    {
        return new self(new Backdoors, $resolver ?? app(Resolver::class));
    }

    /**
     * The machines this run would route through, in the order a trace meets
     * them.
     *
     * THE TARGET IS NEVER IN IT. Routing a run through the machine it is
     * against is not a route, and a backdoor held on the target is doing its
     * other job — the skipped approach roll — rather than this one.
     *
     * @return Collection<int, System>
     */
    public function for(Character $character, ?System $target = null): Collection
    {
        $hops = $this->hopsAllowed($character);

        if ($hops < 1) {
            return collect();
        }

        $chain = $this->backdoors->heldBy($character, BackdoorKind::Routing)
            ->map(static fn (Backdoor $held): ?System => $held->system)
            ->filter(static fn (?System $system): bool => $system !== null)
            // A machine that has gone is not a hop. `loseAllOn()` takes the
            // backdoor with it in every case that exists, so this is a belt
            // rather than braces — and cheap enough to keep.
            ->filter(static fn (System $system): bool => $target === null || ! $system->is($target))
            ->sortByDesc(static fn (System $system): int => (int) $system->grade)
            ->values()
            ->take($hops);

        /*
         * HOLDING a full chain is the achievement, not running one — so it is
         * reported from the builder, which is also what the setup screen asks
         * before a run exists. High-water, so a preview that recomputes the
         * same chain on every render writes nothing after the first time.
         */
        (new Achievements)->reach($character, Metric::RoutingChain, $chain->count());

        return $chain;
    }

    /**
     * How long a chain this character may run.
     *
     * The resolved `routing_hops` stat and nothing else: base zero, one per
     * level of Routing and of Advanced Routing, and whatever an implant adds
     * the day one does. Floored at zero, so somebody who has trained neither
     * routes through nothing — which is the ordinary state and not a penalty.
     */
    public function hopsAllowed(Character $character): int
    {
        return max(0, (int) $this->resolver
            ->resolve('routing_hops', 0.0, $character->modifiers())
            ->toInt());
    }
}
