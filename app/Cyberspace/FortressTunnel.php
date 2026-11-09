<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\System;
use App\Programs\ProgramKind;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Stats\Resolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Secure Tunnelling (docs/CYBERSPACE.md, *Subnet fortresses*), and the only writer of `fortress_tunnels`.
 *
 * While a hosted program of the `tunnel` kind is RUNNING on a Data Fortress, its owner and everybody admitted to it can open
 * a tunnel straight onto it from anywhere: another grid, or not jacked in at all (it jacks them in there). It is the one
 * exception to "the way between grids is a gateway": it bypasses the gateway and its entry rules, and ONLY to a fortress.
 * It costs a few points of Synaptic Integrity (`cyberspace.tunnel.si`, as `DamageOrigin::Tunnel`) and has a cooldown per
 * character per fortress (`cyberspace.tunnel.cooldown_minutes`). It is refused while anything has hold of the character.
 */
final readonly class FortressTunnel
{
    public function __construct(private Resolver $resolver) {}

    public static function make(): self
    {
        return new self(app(Resolver::class));
    }

    /** Can this character tunnel to this fortress, setting the cooldown and the character's state aside? */
    public function usable(Character $character, System $fortress): bool
    {
        return $fortress->type->isFortress()
            && $fortress->abandoned_at === null
            && FortressAccess::admitted($character, $fortress)
            && $this->running($fortress);
    }

    /** Is a tunnel program running on the fortress? */
    public function running(System $fortress): bool
    {
        return (new FortressHost)->hosted($fortress)
            ->contains(fn (InventoryItem $r): bool => $r->fortress_running && $r->item?->program?->kind === ProgramKind::Tunnel);
    }

    /**
     * The fortresses this character could tunnel to, whatever the cooldown.
     *
     * @return Collection<int, System>
     */
    public function available(Character $character): Collection
    {
        $ids = InventoryItem::query()->whereNotNull('fortress_system_id')->where('fortress_running', true)
            ->whereHas('item.program', fn ($q) => $q->where('kind', ProgramKind::Tunnel->value))
            ->pluck('fortress_system_id')->unique();

        return System::query()->with('owner')->whereIn('id', $ids)->get()
            ->filter(fn (System $s): bool => $this->usable($character, $s))->sortBy('id')->values();
    }

    /** Seconds until the next tunnel to this fortress is allowed, 0 when it is. */
    public function cooldownLeft(Character $character, System $fortress): int
    {
        $used = DB::table('fortress_tunnels')->where('character_id', $character->getKey())->where('system_id', $fortress->getKey())->value('used_at');

        if ($used === null) {
            return 0;
        }

        $until = CarbonImmutable::parse($used)->addMinutes((int) Config::get('cyberspace.tunnel.cooldown_minutes', 5));

        return max(0, (int) now()->diffInSeconds($until, false));
    }

    public function tunnel(Character $character, System $fortress): TravelResult
    {
        if (! $this->usable($character, $fortress)) {
            return TravelResult::refused(TravelRefusal::NoTunnel);
        }

        if ($character->current_run_id !== null || $character->current_hunt_id !== null || $character->current_fight_id !== null
            || $character->current_encounter_id !== null || $character->waiting_until !== null) {
            return TravelResult::refused(TravelRefusal::TunnelBusy);
        }

        $toll = (int) Config::get('cyberspace.tunnel.si', 2);

        if ($character->current_synaptic_integrity <= $toll) {
            return TravelResult::refused(TravelRefusal::TunnelTooHurt);
        }

        if ($this->cooldownLeft($character, $fortress) > 0) {
            return TravelResult::refused(TravelRefusal::TunnelCooling);
        }

        $result = Navigator::fromConfig($this->resolver)->tunnelTo($character, $fortress->position());

        if (! $result->succeeded()) {
            return $result;
        }

        DB::table('fortress_tunnels')->updateOrInsert(
            ['character_id' => $character->getKey(), 'system_id' => $fortress->getKey()],
            ['used_at' => now()],
        );

        (new DamageResolver($this->resolver))->apply($character, Damage::synapticIntegrity($toll, DamageOrigin::Tunnel));

        return $result;
    }
}
