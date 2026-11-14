<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PointsCast;
use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Position;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\Tree\RunTree;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An intrusion run.
 *
 * DURABLE, BECAUSE IT IS INTERACTIVE. A run used to be resolved end to end
 * inside one request and needed no row; making it a thing a player plays out
 * turn by turn made it a state machine that has to survive a disconnect, a
 * crash and a deploy. This is that state.
 *
 * NO FOREIGN KEY TO `systems`, on purpose and for the third time in this
 * codebase: a run has to outlive the machine it is against, because the machine
 * being burned or relocated out from under it is precisely the case that
 * cancels one. `system()` may return null and that is an ordinary answer.
 *
 * Written by App\Cyberspace\RunStarter and nothing else.
 */
#[Fillable(['character_id', 'run_party_id', 'turn_at', 'ice_attack', 'pull_at', 'pull_content_id', 'pending_action', 'ice_damage', 'address', 'grid_id', 'x', 'y', 'purpose', 'infiltrated', 'pressure', 'flood_turn', 'extracted', 'backdoored', 'backdoor_kind', 'turn', 'os_locked', 'detected', 'ice_id', 'ice_integrity', 'extracting_qb', 'trace', 'trace_hop', 'chain', 'thermal_load', 'started_at', 'party_seen_at', 'ended_at', 'ending', 'log', 'node', 'known', 'mapped', 'opened', 'loopback', 'tunnel', 'traps', 'snared_until', 'log_wiped', 'alert_cost', 'sentries', 'controller', 'pending_node', 'pull_node', 'noise', 'alerted', 'acted_at'])]
class Run extends Model
{
    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'turn' => 'integer',
            'os_locked' => 'boolean',
            'extracted' => 'boolean',
            'backdoored' => 'boolean',
            'backdoor_kind' => BackdoorKind::class,
            'detected' => 'boolean',
            'ice_integrity' => PointsCast::class,
            'ice_attack' => PointsCast::class,
            'ice_damage' => PointsCast::class,
            'extracting_qb' => 'float',
            'pressure' => 'float',
            'trace' => 'integer',
            'trace_hop' => 'integer',
            'chain' => 'array',
            'thermal_load' => 'integer',
            'purpose' => RunPurpose::class,
            'started_at' => 'immutable_datetime',
            'party_seen_at' => 'immutable_datetime',
            'pull_at' => 'immutable_datetime',
            'turn_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'log' => 'array',
            'node' => 'integer',
            'known' => 'array',
            'mapped' => 'boolean',
            'opened' => 'array',
            'loopback' => 'integer',
            'tunnel' => 'integer',
            'traps' => 'array',
            'snared_until' => 'integer',
            'log_wiped' => 'boolean',
            'alert_cost' => 'float',
            'sentries' => 'array',
            'flood_turn' => 'integer',
            'controller' => 'array',
            'pending_node' => 'integer',
            'pull_node' => 'integer',
            'noise' => 'float',
            'alerted' => 'boolean',
            'acted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /**
     * What is fighting, if anything woke up.
     *
     * A key here where `address` is deliberately not one, and the difference is
     * what each points at: an address names a machine that may stop existing,
     * and the ICE catalogue is authored content that gets edited rather than
     * deleted.
     *
     * @return BelongsTo<Ice, $this>
     */
    public function ice(): BelongsTo
    {
        return $this->belongsTo(Ice::class);
    }

    /** Does this run walk the system's tree (docs/RUN_TREE.md)? Decided once, when it started. */
    public function isTreeRun(): bool
    {
        return $this->node !== null;
    }

    /**
     * Was this run noticed in the sense that costs the owner's goodwill and shuts the system afterwards? On a tree run that
     * is the ALERT; a running trace alone is not. On any other run it is being detected, as it always was.
     */
    public function noticed(): bool
    {
        // On a tree run, an alert whose logs the party wiped leaves nothing behind.
        return $this->isTreeRun() ? (bool) $this->alerted && ! $this->logWiped() : (bool) $this->detected;
    }

    /** Somebody in the party wiped a log server after the alert went off. */
    public function logWiped(): bool
    {
        return $this->run_party_id === null
            ? (bool) $this->log_wiped
            : self::query()->where('run_party_id', $this->run_party_id)->where('log_wiped', true)->exists();
    }

    /**
     * The tree nodes whose kind this runner knows: their own reveals, and on a party run everybody's (a party shares what
     * it finds, and a round's reveals land together because the round resolves together).
     *
     * @return list<int>
     */
    public function knownNodes(): array
    {
        $lists = $this->run_party_id === null
            ? [$this->known ?? []]
            : self::query()->where('run_party_id', $this->run_party_id)->get(['known'])->map(fn (self $r): array => $r->known ?? [])->all();

        return array_values(array_unique(array_map('intval', array_merge(...$lists))));
    }

    /** Did anybody in the party (or the runner alone) start with a scan that had read the Layout? */
    public function mapped(): bool
    {
        return $this->run_party_id === null
            ? (bool) $this->mapped
            : self::query()->where('run_party_id', $this->run_party_id)->where('mapped', true)->exists();
    }

    /**
     * What this runner's party knows of the tree's traps, as {node: found|sprung|spent|cut}: the union of every run of the party,
     * the furthest state winning (cut, then spent, then found).
     *
     * @return array<int, string>
     */
    public function trapStates(): array
    {
        $maps = $this->run_party_id === null
            ? [$this->traps ?? []]
            : self::query()->where('run_party_id', $this->run_party_id)->get(['traps'])->map(fn (self $r): array => $r->traps ?? [])->all();
        // Sprung (it went off) and spent (disarmed) are both done with; the map tells them apart.
        $rank = ['found' => 1, 'spent' => 2, 'sprung' => 2, 'cut' => 3];
        $out = [];

        foreach ($maps as $map) {
            foreach ($map as $node => $state) {
                if (! isset($out[(int) $node]) || $rank[$state] > $rank[$out[(int) $node]]) {
                    $out[(int) $node] = $state;
                }
            }
        }

        return $out;
    }

    /** @return list<int> The nodes a Node Disconnect tore out of the tree for this party. */
    public function cutNodes(): array
    {
        return array_keys(array_filter($this->trapStates(), static fn (string $s): bool => $s === 'cut'));
    }

    /** Mark a trap node on this runner's own map; the party reads them together. */
    public function markTrap(int $node, string $state): void
    {
        $traps = $this->traps ?? [];
        $traps[$node] = $state;
        $this->traps = $traps;
    }

    /** @return list<int> Nodes where an Egress Tunnel holds: this runner's own, and on a party run every live ally's. */
    public function tunnelNodes(): array
    {
        $runs = $this->run_party_id === null
            ? collect($this->tunnel !== null ? [$this] : [])
            : self::query()->live()->where('run_party_id', $this->run_party_id)->whereNotNull('tunnel')->get(['node']);

        return $runs->pluck('node')->map(static fn ($n): int => (int) $n)->unique()->values()->all();
    }

    /** Turns the node has been flooded for: since the first Flood on a tree run, since the start otherwise. */
    public function floodedTurns(): int
    {
        return (int) $this->turn - (int) ($this->flood_turn ?? 0);
    }

    /** A Loopback holds on an internal relay, theirs or (on a party run) a live ally's: the trace stalls. */
    public function loopbackHolds(): bool
    {
        return $this->run_party_id === null
            ? $this->loopback !== null
            : self::query()->live()->where('run_party_id', $this->run_party_id)->whereNotNull('loopback')->exists();
    }

    /** The firewalls this runner may pass: their own opening, and on a party run everybody's. @return list<int> */
    public function openedFirewalls(): array
    {
        $lists = $this->run_party_id === null
            ? [$this->opened ?? []]
            : self::query()->where('run_party_id', $this->run_party_id)->get(['opened'])->map(fn (self $r): array => $r->opened ?? [])->all();

        return array_values(array_unique(array_map('intval', array_merge(...$lists))));
    }

    /**
     * Can the system's ICE reach this runner? On a tree run it stands on the core and hits nobody anywhere else; on any
     * other run there is only the one room.
     */
    public function iceReachesHere(): bool
    {
        if (! $this->isTreeRun()) {
            return true;
        }

        $system = $this->system();

        return $system !== null && (int) $this->node === RunTree::of($system)->core;
    }

    /** Is something still swinging at them? */
    public function inAFight(): bool
    {
        return $this->ice_id !== null && (float) $this->ice_integrity > 0;
    }

    /** How fast the trace is walking, per turn. Zero when nothing is following. */
    public function traceRate(): int
    {
        if (! $this->detected) {
            return 0;
        }

        return (int) (System::query()->where('address', $this->address)->value('tracer_rate') ?? 0);
    }

    /**
     * Turns before it reaches you, or null when nothing is coming.
     *
     * THE NUMBER THE EGRESS DECISION IS MADE ON, which is why it is worked out
     * here rather than left for a player to divide in their head while
     * something is hitting them. Null means either nothing has noticed or this
     * machine has no tracer — both of which are the same good news.
     */
    public function turnsUntilTraced(): ?int
    {
        $rate = $this->traceRate();

        if ($rate <= 0 || $this->trace >= 100) {
            return null;
        }

        return (int) ceil((100 - $this->trace) / $rate);
    }

    /**
     * The grade of every backdoored system the trace has to come through.
     *
     * The route this run declared when it began, as grades — one slot per hop,
     * in the order a trace meets them. A trace attempts the next one each turn
     * and only advances if it gets through, so a long chain of deep machines is
     * turns of nothing happening.
     *
     * An empty list is no routing at all, which is the ordinary state for
     * anybody who holds no routing backdoors or has trained neither Routing
     * skill: the trace comes on unopposed from the first turn.
     *
     * @return list<?int> One slot per stored hop; null where it has gone.
     */
    public function routingGrades(): array
    {
        $chain = (array) ($this->chain ?? []);

        if ($chain === []) {
            return [];
        }

        /*
         * LOSING ONE HOP IS NOT LOSING THE CHAIN. A completed trace is the only
         * thing that takes the whole route; a hop lost any other way — somebody
         * else's lockdown, a burn, the nightly ageing roll — simply is not
         * there any more, and the rest of the chain stands.
         *
         * The stored list is NEVER rewritten and this returns a slot for every
         * entry in it, null where the machine has gone or the backdoor with
         * it. That is what keeps `runs.trace_hop` honest: it indexes this
         * list, so a hop going dark must not shift the ones behind it. A null
         * is walked straight past — one less thing in the way — and a hop
         * already passed stays passed because the index only ever moves
         * forward.
         *
         * Read fresh every turn rather than cached on the run, because the
         * whole point is that it can change underneath one.
         */
        $live = System::query()
            ->whereIn('id', $chain)
            ->whereIn('id', Backdoor::query()
                // A crew on a run tree came in over the network of the runner the owner picked: theirs are the backdoors.
                ->where('character_id', ($this->isTreeRun() && $this->run_party_id !== null
                    ? RunParty::query()->whereKey($this->run_party_id)->value('chain_character_id')
                    : null) ?? $this->character_id)
                ->where('kind', BackdoorKind::Routing->value)
                ->select('system_id'))
            ->pluck('grade', 'id');

        return array_map(
            static fn (mixed $id): ?int => ($grade = $live->get((int) $id)) === null ? null : (int) $grade,
            array_values($chain),
        );
    }

    /** How much of the route is still standing, for a screen that says so. */
    public function liveHops(): int
    {
        return count(array_filter($this->routingGrades(), static fn (?int $grade): bool => $grade !== null));
    }

    /**
     * The chain the trace has to walk, as the Trace panel draws it: the machine, every hop of the route this run declared
     * in the order the trace meets them, and you. A hop that has since gone dark keeps its place (`gone`) because the
     * stored route is never rewritten; `passed` is where the trace has got to.
     *
     * @return list<array{label: ?string, kind: string, passed: bool, gone: bool}>
     */
    public function traceChain(): array
    {
        $route = array_values((array) ($this->chain ?? []));
        $addresses = $route === [] ? collect() : System::query()->whereIn('id', $route)->pluck('address', 'id');
        $grades = $this->routingGrades();

        $nodes = [['label' => $this->address, 'kind' => 'target', 'passed' => true, 'gone' => false]];

        foreach ($route as $i => $id) {
            $gone = ($grades[$i] ?? null) === null;
            $nodes[] = ['label' => $gone ? null : $addresses->get($id), 'kind' => 'hop', 'passed' => $i < (int) $this->trace_hop, 'gone' => $gone];
        }

        $nodes[] = ['label' => null, 'kind' => 'you', 'passed' => $this->trace >= 100, 'gone' => false];

        return $nodes;
    }

    /**
     * The machine, if it is still there.
     *
     * NULL IS AN ORDINARY ANSWER — it means the target was burned or has
     * relocated and is wearing a new address, which is what cancels a run
     * rather than what breaks one.
     */
    public function system(): ?System
    {
        return System::query()->where('address', $this->address)->first();
    }

    public function position(): Position
    {
        return new Position($this->x, $this->y, (int) ($this->grid_id ?? 1));
    }

    /** Is this run still being played? */
    public function isLive(): bool
    {
        return $this->ended_at === null;
    }

    /** @param  Builder<self>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    /**
     * What this run came to install, or null where that is not the question.
     *
     * NULL IS A REFUSAL AND NOT A DEFAULT. A backdoor run with no kind on it
     * is a hand-built request rather than something a player can reach, and
     * picking one for them would be picking the permanent property of a
     * machine on their behalf.
     */
    public function backdoorKind(): ?BackdoorKind
    {
        return $this->purpose === RunPurpose::Backdoor ? $this->backdoor_kind : null;
    }
}
