<?php

declare(strict_types=1);

namespace App\Insurance;

use App\City\CityRefusal;
use App\City\Presence;
use App\Models\Character;
use App\Models\ClinicOffer;
use App\Models\InsuranceOffer;
use App\Models\InsurancePolicy;
use App\Models\Location;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Stats\InjuryLedger;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Policies, and what they pay. docs/INSURANCE.md.
 *
 * ## One term is one row
 *
 * Signing while cover runs QUEUES the next term to start when this one ends, so nothing is wasted, and a policy that has
 * a successor is neither reminded about nor lapsed. A second renewal is refused: one term ahead is all that is held.
 * The tier of a renewal is the new row's own, so a change of tier takes effect when it starts.
 *
 * ## Two claims, one door each
 *
 * `claimLoss()` is called by the writer of a loss after it has deleted, inside that writer's transaction, with the
 * rows it took. `claimInjury()` likewise, for permanent damage to a maximum. Both pay creds through the wallet on the
 * instance they are given (the caller's locked character) and send one message. Without cover they do nothing. What
 * a player does to their own things (selling, dropping, trading) never comes through here.
 */
final class Insurance
{
    /** The shares of a loss a policy can pay back, in percent. */
    public const TIERS = [50, 70, 90];

    /** Cover in force now: the term that has started and not ended. */
    public function active(Character $character, InsuranceKind $kind): ?InsurancePolicy
    {
        return InsurancePolicy::query()
            ->where('character_id', $character->getKey())
            ->where('kind', $kind->value)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->orderByDesc('share')
            ->first();
    }

    /** The term queued to follow the current one, if one has been bought. */
    public function queued(Character $character, InsuranceKind $kind): ?InsurancePolicy
    {
        return InsurancePolicy::query()
            ->where('character_id', $character->getKey())
            ->where('kind', $kind->value)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->first();
    }

    /** @return int|CityRefusal creds spent */
    public function sign(Character $character, Location $place, InsuranceKind $kind, int $share): int|CityRefusal
    {
        return DB::transaction(function () use ($character, $place, $kind, $share): int|CityRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();

            if ($refusal = Presence::refusal($locked, $place)) {
                return $refusal;
            }

            $offer = InsuranceOffer::query()->where('location_id', $place->getKey())->where('kind', $kind->value)->where('share', $share)->first();

            if ($offer === null) {
                return CityRefusal::NotSold;
            }

            if ($this->queued($locked, $kind) !== null) {
                return CityRefusal::AlreadyCovered;
            }

            if ((int) $locked->creds < $offer->price) {
                return CityRefusal::CannotAfford;
            }

            $current = InsurancePolicy::query()
                ->where('character_id', $locked->getKey())->where('kind', $kind->value)
                ->where('ends_at', '>', now())->orderByDesc('ends_at')->first();

            $starts = $current?->ends_at ?? CarbonImmutable::now();

            Wallet::move($locked, -$offer->price, Reason::Insurance, $kind->label().' '.$share.'%');

            InsurancePolicy::query()->create([
                'character_id' => $locked->getKey(),
                'kind' => $kind,
                'share' => $share,
                'starts_at' => $starts,
                'ends_at' => $starts->addDays((int) Config::get('insurance.term_days')),
            ]);

            $character->refresh();

            return $offer->price;
        });
    }

    /**
     * Pay for what was lost, from the property policy in force. Returns the creds paid (0 without cover).
     *
     * @param  int  $worth  base value of everything lost, quantity counted
     * @param  array<string, int>  $lost  name => copies, for the message
     */
    public function claimLoss(Character $character, int $worth, array $lost): int
    {
        $policy = $this->active($character, InsuranceKind::Property);

        if ($policy === null || $worth <= 0) {
            return 0;
        }

        $paid = (int) floor($worth * $policy->share / 100);

        if ($paid <= 0) {
            return 0;
        }

        Wallet::move($character, $paid, Reason::Insurance, InsuranceKind::Property->label());

        (new Notifier)->send($character, Category::City, 'insurance_property_claim', ['kind' => mb_strtolower(InsuranceKind::Property->label()), 'creds' => number_format($paid), 'share' => $policy->share], details: [
            'lost' => array_map(fn (string $name, int $n): string => $n.' × '.$name, array_keys($lost), $lost),
        ]);

        return $paid;
    }

    /**
     * Pay for permanent damage to a maximum, at what the clinics charge a point to mend it.
     *
     * @param  string  $injury  an InjuryLedger key
     */
    public function claimInjury(Character $character, string $injury, float $points): int
    {
        $policy = $this->active($character, InsuranceKind::Medical);

        if ($policy === null || $points <= 0) {
            return 0;
        }

        $perPoint = (float) ClinicOffer::query()->where('service', $injury)->avg('price');
        $paid = (int) floor($points * $perPoint * $policy->share / 100);

        if ($paid <= 0) {
            return 0;
        }

        Wallet::move($character, $paid, Reason::Insurance, InsuranceKind::Medical->label());

        (new Notifier)->send($character, Category::City, 'insurance_medical_claim', [
            'kind' => mb_strtolower(InsuranceKind::Medical->label()),
            'creds' => number_format($paid),
            'share' => $policy->share,
            'injury' => (string) Config::get("effects.{$injury}.label", $injury),
        ]);

        return $paid;
    }

    /**
     * The messages cover owes: two days before a term ends, and when it has. Neither goes out for a term that has a
     * successor queued. Each once. Returns how many were sent.
     */
    public function remind(): int
    {
        $sent = 0;
        $horizon = now()->addDays((int) Config::get('insurance.remind_days'));

        $due = InsurancePolicy::query()->with('character')
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('reminded_at')->where('ends_at', '<=', $horizon)->where('ends_at', '>', now()))
                ->orWhere(fn ($q) => $q->whereNull('lapsed_at')->where('ends_at', '<=', now())))
            ->get();

        foreach ($due as $policy) {
            $character = $policy->character;
            $followed = InsurancePolicy::query()
                ->where('character_id', $policy->character_id)->where('kind', $policy->kind->value)
                ->where('starts_at', '>=', $policy->ends_at)->exists();

            $ended = $policy->ends_at <= now();

            if ($followed || $character === null) {
                // Nothing is owed for a term that was renewed: mark it so it is not looked at again.
                $policy->forceFill($ended ? ['lapsed_at' => now(), 'reminded_at' => $policy->reminded_at ?? now()] : ['reminded_at' => now()])->save();

                continue;
            }

            (new Notifier)->send($character, Category::City, $ended ? 'insurance_lapsed' : 'insurance_ending', [
                'kind' => $policy->kind->label(),
            ]);

            $policy->forceFill($ended ? ['lapsed_at' => now(), 'reminded_at' => $policy->reminded_at ?? now()] : ['reminded_at' => now()])->save();
            $sent++;
        }

        return $sent;
    }
}
