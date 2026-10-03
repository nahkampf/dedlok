<?php

declare(strict_types=1);

namespace App\City;

use App\Checks\CheckRoller;
use App\Implants\BodyRegion;
use App\Implants\ImplantFitter;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\ClinicSurgery;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Location;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Stats\InjuryLedger;
use App\Stats\Resolver;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Implant surgery: the only door to `ImplantFitter` a player can reach.
 *
 * A timed City activity at a clinic that offers it. Charged when it begins (a
 * price per tier of the implant) and resolved when it matures. FITTING is a
 * check — the clinic's rating against a difficulty set by the implant's tier —
 * and a failure costs injury and the implant is rejected, staying in the stash.
 * Taking one out is a fee and time and no roll.
 *
 * `quote()` is what the screen shows and `begin()` charges from the same call,
 * and the chance quoted is `CheckRoller::chance()`, the number `roll()` uses.
 * The activity cannot be cancelled: nobody gets off the table. While one runs
 * the character cannot jack in, so a body is never in cyberspace under the knife.
 */
final class Surgeon
{
    public function __construct(
        private readonly CheckRoller $roller,
        private readonly Resolver $resolver,
        private readonly ImplantFitter $fitter,
        private readonly ShopKeeper $shop,
    ) {}

    /**
     * What this operation would cost, take and risk, or why it cannot be done here.
     *
     * @return array{fee: int, seconds: int, chance: float|null, tier: int}|CityRefusal
     */
    public function quote(Character $character, Location $place, InventoryItem $row, SurgeryOperation $op): array|CityRefusal
    {
        $surgery = ClinicSurgery::query()->find($place->getKey());
        $item = $row->item;
        $region = $item?->implantRegion();

        if ($surgery === null) {
            return CityRefusal::NotOffered;
        }

        if ($row->character_id !== $character->getKey() || $region === null) {
            return CityRefusal::CannotOperate;
        }

        $tier = max(1, (int) $item->tier);

        if ($op === SurgeryOperation::Install) {
            if ($row->installed_at !== null || ! InventoryItem::query()->whereKey($row->getKey())->loose()->exists()) {
                return CityRefusal::CannotOperate;
            }

            if ($this->fitter->installedIn($character, $region)->count() >= $region->capacity()) {
                return CityRefusal::NoRoom;
            }
        } elseif ($row->installed_at === null) {
            return CityRefusal::CannotOperate;
        }

        return $this->figures($character, $surgery, $item, $op);
    }

    /**
     * What buying this implant here and having it fitted would cost and risk: the
     * fitting's figures, or why it cannot be done. Asks the same questions `quote()`
     * asks of an implant already carried, minus the ones about a row that does not exist yet.
     *
     * @return array{fee: int, seconds: int, chance: float|null, tier: int}|CityRefusal
     */
    public function quoteFit(Character $character, Location $place, Item $item): array|CityRefusal
    {
        $surgery = ClinicSurgery::query()->find($place->getKey());
        $region = $item->implantRegion();

        if ($surgery === null) {
            return CityRefusal::NotOffered;
        }

        if ($region === null) {
            return CityRefusal::CannotOperate;
        }

        if ($this->fitter->installedIn($character, $region)->count() >= $region->capacity()) {
            return CityRefusal::NoRoom;
        }

        return $this->figures($character, $surgery, $item, SurgeryOperation::Install);
    }

    /** @return array{fee: int, seconds: int, chance: float|null, tier: int} */
    private function figures(Character $character, ClinicSurgery $surgery, Item $item, SurgeryOperation $op): array
    {
        $tier = max(1, (int) $item->tier);
        $seconds = $this->resolver
            ->resolve('surgery_duration', (float) Config::get("city.surgery.seconds.{$op->value}", 300) * $tier, $character->modifiers())
            ->toInt();

        return [
            'fee' => ($op === SurgeryOperation::Install ? $surgery->install_price : $surgery->removal_price) * $tier,
            'seconds' => max(1, $seconds),
            'chance' => $op === SurgeryOperation::Install
                ? $this->roller->chance((float) $surgery->rating, $this->difficulty($tier), 'surgery')
                : null,
            'tier' => $tier,
        ];
    }

    /**
     * Buy one of this clinic's implants and go straight onto the table with it.
     *
     * One transaction: the shelf's price and the surgeon's fee are charged together
     * or not at all, and a refusal at ANY step — no room in that part of the body
     * included — rolls the purchase back. A fitting that fails leaves the implant in
     * the stash, as any failed fitting does.
     */
    public function buyAndFit(Character $character, Location $place, int $itemId): CityActivity|CityRefusal
    {
        try {
            return DB::transaction(function () use ($character, $place, $itemId): CityActivity|CityRefusal {
                $item = Item::query()->find($itemId);
                $quote = $item === null ? CityRefusal::NotSold : $this->quoteFit($character->fresh(), $place, $item);

                if ($quote instanceof CityRefusal) {
                    return $quote;
                }

                $bought = $this->shop->buy($character, $place, $itemId, 1);

                if ($bought instanceof CityRefusal) {
                    return $bought;
                }

                $row = InventoryItem::query()->with('item')->where('character_id', $character->getKey())
                    ->where('item_id', $itemId)->loose()->latest('id')->firstOrFail();
                $result = $this->begin($character, $place, $row, SurgeryOperation::Install);

                return $result instanceof CityRefusal ? throw new RefusedUnderTheKnife($result) : $result;
            });
        } catch (RefusedUnderTheKnife $e) {
            return $e->refusal;
        }
    }

    public function begin(Character $character, Location $place, InventoryItem $row, SurgeryOperation $op): CityActivity|CityRefusal
    {
        return DB::transaction(function () use ($character, $place, $row, $op): CityActivity|CityRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();

            if ($refusal = Presence::refusal($locked, $place)) {
                return $refusal;
            }

            $row = InventoryItem::query()->with('item')->whereKey($row->getKey())->lockForUpdate()->first();

            if ($row === null) {
                return CityRefusal::CannotOperate;
            }

            $quote = $this->quote($locked, $place, $row, $op);

            if ($quote instanceof CityRefusal) {
                return $quote;
            }

            if ((int) $locked->creds < $quote['fee']) {
                return CityRefusal::CannotAfford;
            }

            Wallet::move($locked, -$quote['fee'], Reason::Surgery, Text::get('city.surgery.note', [
                'op' => $op->label(),
                'implant' => $row->item->displayName(),
                'place' => $place->name,
            ]));

            $activity = CityActivity::query()->create([
                'character_id' => $locked->getKey(),
                'kind' => ActivityKind::Surgery,
                'operation' => $op,
                'subject_id' => $row->getKey(),
                'location_id' => $place->getKey(),
                'step' => 0,
                'paid' => $quote['fee'],
                'cancelling' => false,
                'pass_ends_at' => CarbonImmutable::now()->addSeconds($quote['seconds']),
            ]);

            $character->refresh();

            return $activity;
        });
    }

    /** @return list<array{0: string, 1: array<string, string>}> message key and replacements */
    public function settle(Character $character, ?CarbonInterface $now = null, bool $notify = false): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $message = null;

        DB::transaction(function () use ($character, $now, &$message): void {
            $activity = CityActivity::query()->whereKey($character->getKey())
                ->where('kind', ActivityKind::Surgery->value)->lockForUpdate()->first();

            if ($activity === null || $activity->pass_ends_at > $now) {
                return;
            }

            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $row = InventoryItem::query()->with('item')->whereKey($activity->subject_id)->lockForUpdate()->first();
            $surgery = ClinicSurgery::query()->find($activity->location_id);
            $activity->delete();

            $name = $row?->item?->displayName() ?? '';

            // The row, or the clinic, vanished under the knife: nothing to do.
            if ($row === null || $surgery === null) {
                return;
            }

            if ($activity->operation === SurgeryOperation::Remove) {
                $this->fitter->remove($locked, $row);
                $message = ['surgery_removed', ['implant' => $name]];

                return;
            }

            $tier = max(1, (int) $row->item->tier);
            $passed = $this->roller->roll((float) $surgery->rating, $this->difficulty($tier), 'surgery')->passed;

            if ($passed && $this->fitter->install($locked, $row)->succeeded()) {
                $message = ['surgery_installed', ['implant' => $name]];

                return;
            }

            $region = $row->item->implantRegion();
            $key = in_array($region, [BodyRegion::Head, BodyRegion::Spine], true) ? InjuryLedger::NEURAL : InjuryLedger::TRAUMA;
            (new InjuryLedger)->inflict($locked, $key, $tier * (float) Config::get('city.surgery.injury_per_tier', 2));
            $message = ['surgery_failed', ['implant' => $name]];
        });

        if ($message === null) {
            return [];
        }

        if ($notify) {
            (new Notifier)->send($character, Category::City, $message[0], $message[1]);
        }

        return [$message];
    }

    private function difficulty(int $tier): float
    {
        return (float) Config::get("city.surgery.difficulty.{$tier}", 20);
    }
}
