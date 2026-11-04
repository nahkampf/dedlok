<?php

declare(strict_types=1);

namespace App\Safehouses;

use App\Models\Character;
use App\Models\CityActivity;
use App\Models\Safehouse;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `characters.safehouse_id` and `rent_due_at`. docs/FATIGUE.md, *Safehouses*.
 *
 * Rent is paid in ADVANCE for each period, the first when it is taken, and charged lazily (whoever looks settles it)
 * with `safehouses:rent` behind it. Never charged into debt: a period that cannot be paid waits, and is paid the
 * moment the money is there. One further period unpaid and the safehouse is lost, and they fall back to the box.
 * Whatever was stored there will be put in storage for 48 hours; nothing can be stored in one yet.
 */
final class Renter
{
    /** The number of explore passes a character has put into a district. */
    public function passes(Character $character, int $districtId): int
    {
        return (int) DB::table('character_district_passes')
            ->where('character_id', $character->getKey())->where('district_id', $districtId)->value('passes');
    }

    /** Is this safehouse theirs, the fallback they hold by having none included? */
    public function holds(Character $character, Safehouse $safehouse): bool
    {
        return $character->home()->getKey() === $safehouse->getKey();
    }

    /** Why they cannot rent it now, or null. Asked again inside the writer's lock. */
    public function refusal(Character $character, Safehouse $safehouse): ?Refusal
    {
        if ($character->isJackedIn()) {
            return Refusal::JackedIn;
        }

        if ($character->current_fight_id !== null) {
            return Refusal::Fighting;
        }

        if (CityActivity::query()->whereKey($character->getKey())->exists()) {
            return Refusal::Busy;
        }

        if ($character->district_id !== $safehouse->district_id) {
            return Refusal::NotHere;
        }

        if ($this->holds($character, $safehouse)) {
            return Refusal::AlreadyYours;
        }

        // Going back to the box needs no familiarity: it is where everybody falls back to anyway.
        if (! $this->isFallback($safehouse) && $this->passes($character, $safehouse->district_id) < (int) Config::get('safehouse.passes_needed')) {
            return Refusal::Unfamiliar;
        }

        if ($safehouse->rent > 0 && (int) $character->creds < $safehouse->rent) {
            return Refusal::CannotAfford;
        }

        return null;
    }

    /** Take a safehouse, dropping the one they had. The first period is paid now. */
    public function rent(Character $character, Safehouse $safehouse): true|Refusal
    {
        return DB::transaction(function () use ($character, $safehouse): true|Refusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $safehouse = Safehouse::query()->find($safehouse->getKey());

            if ($safehouse === null) {
                return Refusal::NotHere;
            }

            $refusal = $this->refusal($locked, $safehouse);

            if ($refusal !== null) {
                return $refusal;
            }

            if ($safehouse->rent > 0) {
                Wallet::move($locked, -$safehouse->rent, Reason::Rent, $this->note($safehouse));
            }

            $previous = $locked->home();

            $locked->forceFill([
                'safehouse_id' => $this->isFallback($safehouse) ? null : $safehouse->getKey(),
                'rent_due_at' => $safehouse->rent > 0 ? $this->period(CarbonImmutable::now()) : null,
            ])->save();

            // Whatever was stored at the home they gave up now has a deadline; this one's own things are theirs again.
            $storage = app(SafehouseStorage::class);

            if ($previous->getKey() !== $safehouse->getKey()) {
                $storage->putAway($locked, $previous);
                $locked->unsetRelation('safehouse');
            }

            $storage->reclaim($locked, $safehouse);

            $character->refresh();

            return true;
        });
    }

    /** Charge what has fallen due, and take the safehouse away if it stayed unpaid too long. */
    public function settle(Character $character, ?CarbonInterface $now = null): void
    {
        if ($character->safehouse_id === null || $character->rent_due_at === null) {
            return;
        }

        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $lost = null;

        DB::transaction(function () use ($character, $now, &$lost): void {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $safehouse = $locked->safehouse;

            if ($safehouse === null || $locked->rent_due_at === null || $locked->rent_due_at > $now) {
                return;
            }

            $due = CarbonImmutable::instance($locked->rent_due_at);

            // One iteration per period that has passed; a long absence pays what it can afford and no more.
            while ($due <= $now && (int) $locked->creds >= $safehouse->rent) {
                Wallet::move($locked, -$safehouse->rent, Reason::Rent, $this->note($safehouse));
                $due = $this->period($due);
            }

            if ($due <= $now && $due->addHours((int) Config::get('safehouse.grace_hours'))->lte($now)) {
                $locked->forceFill(['safehouse_id' => null, 'rent_due_at' => null])->save();
                $locked->unsetRelation('safehouse');
                $lost = $safehouse;
                app(SafehouseStorage::class)->putAway($locked, $safehouse);
            } else {
                $locked->forceFill(['rent_due_at' => $due])->save();
            }

            $character->refresh();
        });

        if ($lost !== null) {
            (new Notifier)->send($character, Category::City, 'safehouse_lost', ['place' => $this->name($lost)]);
        }
    }

    /** Their home burnt down: gone with everything stored in it, back in the box. The caller holds the lock. */
    public function burnDown(Character $locked, Safehouse $home): void
    {
        $locked->forceFill(['safehouse_id' => null, 'rent_due_at' => null])->save();
        $locked->unsetRelation('safehouse');
        app(SafehouseStorage::class)->burn($locked, $home);
    }

    public function isFallback(Safehouse $safehouse): bool
    {
        return $safehouse->slug === (string) Config::get('safehouse.fallback');
    }

    /** "Cardboard box [Knightsbridge Projects]" */
    public function name(Safehouse $safehouse): string
    {
        return $safehouse->title.' ['.($safehouse->district->name ?? '').']';
    }

    private function note(Safehouse $safehouse): string
    {
        return Text::get('wallet.notes.rent', ['place' => $this->name($safehouse)]);
    }

    private function period(CarbonInterface $from): CarbonImmutable
    {
        return CarbonImmutable::instance($from)->addHours((int) Config::get('safehouse.rent_hours'));
    }
}
