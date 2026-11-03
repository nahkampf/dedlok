<?php

declare(strict_types=1);

namespace App\Characters;

use App\City\EncounterRunner;
use App\City\Traveller;
use App\Combat\Fights;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunStarter;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\Hunt;
use App\Models\Run;
use App\Programs\ProgramRunner;
use App\Safehouses\Renter;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Stats\Resolver;
use App\Support\Points;
use App\Support\Text;
use App\Support\Trace;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * What happens to somebody who has run out of themselves. FATIGUE.md, *Collapse*.
 *
 * Asked on the way past (`EnsureCharacterIsStanding`) once `Fatigue` has flagged `collapse_pending`, never from inside the
 * writer that tipped it: a travel loop, a run turn or a fight round carries on to its own end first. Then, in one
 * transaction on the locked character and in the order every bad ending follows:
 *
 *  1. whatever they were in is ended (an encounter, a hunt, a run, a fight) and they are jacked out;
 *  2. damage to ONE of hit points or Synaptic Integrity, fixed at a share of that maximum, through `DamageResolver`
 *     as `DamageOrigin::Exhaustion` (a body hurts for what the body was doing; the mind otherwise, and when unsure);
 *  3. the mugging, where they fell (a chance, then a share of the creds carried);
 *  4. they are moved home, free (`Traveller::relocate()`), made Exhausted, and put on the floor for the collapse's own
 *     length. They wake at 95% of the maximum (`WaitingRoom::release()`).
 */
final class Collapse
{
    /** @param  Closure(int, int): int  $roll  an integer die, for the mugging only */
    public function __construct(private readonly ?Closure $roll = null) {}

    /** Collapse them if one is pending. Returns whether it happened. */
    public function trigger(Character $character): bool
    {
        if (! $character->collapse_pending) {
            return false;
        }

        return Trace::scope($character, 'collapse', fn () => DB::transaction(function () use ($character): bool {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->collapse_pending) {
                return false;
            }

            $resource = $this->hurts($locked);
            $this->endEverything($locked);

            $effects = [];
            $resolver = app(Resolver::class);

            $amount = Points::of($locked->maximumFor($resource) * (float) Config::get('fatigue.collapse.damage'));
            $damage = $resource === 'current_hitpoints'
                ? Damage::hitpoints($amount, DamageOrigin::Exhaustion)
                : Damage::synapticIntegrity($amount, DamageOrigin::Exhaustion);
            $result = (new DamageResolver($resolver))->apply($locked, $damage);
            $effects[] = Text::get('character.waiting.collapse.hurt', [
                'damage' => Points::format($result->taken),
                'resource' => Text::get("character.waiting.resource.{$resource}"),
            ]);

            if ($line = $this->mugged($locked)) {
                $effects[] = $line;
            }

            // Rent that fell due while they were away is settled first, so they wake where they still live.
            app(Renter::class)->settle($locked);
            $home = $locked->home();
            app(Traveller::class)->relocate($locked, $home->district);
            app(Fatigue::class)->exhaust($locked);
            app(Mood::class)->event($locked, 'collapse');
            $locked->forceFill(['collapse_pending' => false])->save();

            app(WaitingRoom::class)->put($locked, new DownedCause(
                reason: 'collapse',
                damage: $result->taken,
                resource: $resource,
                effects: [...$effects, Text::get('character.waiting.collapse.home', ['home' => $home->title, 'district' => $home->district->name])],
            ), (int) Config::get('fatigue.collapse.down_seconds'));

            // The caller's instance, not only the row.
            $character->refresh();

            return true;
        }), []);
    }

    /** The body for what the body was doing (a fight, a trip, an explore), the mind for the rest and when unsure. */
    private function hurts(Character $locked): string
    {
        $physical = $locked->current_fight_id !== null
            || CityActivity::query()->whereKey($locked->getKey())->exists()
            || ($locked->current_encounter_id !== null && ! $locked->isJackedIn());

        return $physical ? 'current_hitpoints' : 'current_synaptic_integrity';
    }

    private function endEverything(Character $locked): void
    {
        // The encounter first: a fight it opened must not find it waiting with a loss screen.
        app(EncounterRunner::class)->abandon($locked);

        if ($locked->current_hunt_id !== null) {
            $hunt = Hunt::query()->find($locked->current_hunt_id);
            $hunt === null ? app(ProgramRunner::class)->stopRunOnly($locked) : app(ProgramRunner::class)->endHunt($locked, $hunt);
            $locked->forceFill(['current_hunt_id' => null])->save();
            $hunt?->forceFill(['won' => false, 'resolved_at' => now()])->save();
        }

        if ($locked->current_run_id !== null) {
            $run = Run::query()->find($locked->current_run_id);

            if ($run?->isLive()) {
                app(RunStarter::class)->end($locked, $run, RunEnding::Cancelled);
            }
        }

        app(Fights::class)->forfeit($locked);

        // Out of cyberspace, always. After the hunt and run are over, which is what `jackOut()` refuses over.
        $locked->forceFill(['grid_x' => null, 'grid_y' => null])->save();
    }

    /** A chance, then a share of ALL the creds carried, floored. Skipped where it is safe (nowhere is, yet). */
    private function mugged(Character $locked): ?string
    {
        $roll = $this->roll ?? static fn (int $min, int $max): int => random_int($min, $max);

        if ($this->isSafe($locked) || $roll(1, 100) > (int) Config::get('fatigue.collapse.mugging_chance')) {
            return null;
        }

        $percent = $roll((int) Config::get('fatigue.collapse.mugging_min'), (int) Config::get('fatigue.collapse.mugging_max'));
        $taken = (int) floor((int) $locked->creds * $percent / 100);

        if ($taken <= 0) {
            return null;
        }

        Wallet::move($locked, -$taken, Reason::Mugged, Text::get('wallet.notes.mugged'));

        return Text::get('character.waiting.collapse.mugged', ['creds' => $taken]);
    }

    /**
     * Is where they fell a safe place? Homes and safe locations are not built, so nowhere is. The one place this is
     * answered, for the day somewhere is.
     */
    private function isSafe(Character $locked): bool
    {
        return false;
    }
}
