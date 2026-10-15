<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Decks\Storage;
use App\Models\Character;
use App\Models\FortressArea;
use App\Models\FortressListing;
use App\Models\InventoryItem;
use App\Models\System;
use App\Models\SystemContent;
use App\Programs\ProgramKind;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * A Data Fortress's public market: a hosted Market program lists files from ONE storage area, each at its own seller's
 * price. A visitor standing on the node buys without being a member. The tax (the owner's or an administrator's to set,
 * default 3, at most 50) goes to the fortress wallet; the rest to the seller, through `Wallet`.
 */
final class FortressMarket
{
    public const MAX_TAX = 50;

    /** Open means the fortress is online, not frozen, and a Market program is running on it. */
    public static function isOpen(System $fortress): bool
    {
        if (! $fortress->type->isFortress() || $fortress->abandoned_at !== null || Fortresses::isFrozen($fortress)) {
            return false;
        }

        return InventoryItem::query()->where('fortress_system_id', $fortress->getKey())->where('fortress_running', true)
            ->whereHas('item.program', fn ($q) => $q->where('kind', ProgramKind::Market->value))->exists();
    }

    /** Who may stand at the counter: public to anybody, invited to the admitted, private to the admitted too (a private fortress offers no market to strangers). */
    public static function mayVisit(Character $character, System $fortress): bool
    {
        if ($fortress->access === 'public') {
            return true;
        }

        return FortressAccess::admitted($character, $fortress);
    }

    public function configure(Character $by, System $fortress, ?int $areaId, int $tax): ?string
    {
        if (! FortressAccess::can($by, $fortress, 'host')) {
            return 'not_yours';
        }

        if ($tax < 0 || $tax > self::MAX_TAX) {
            return 'bad_tax';
        }

        if ($areaId !== null && ! FortressArea::query()->where('system_id', $fortress->getKey())->whereKey($areaId)->exists()) {
            return 'not_yours';
        }

        $fortress->forceFill(['market_area_id' => $areaId, 'market_tax' => $tax])->save();
        FortressLog::write($fortress, $by, 'settings', "market area {$areaId}, tax {$tax}%");

        return null;
    }

    /** Put a file in the market area up at a price. Only its depositor (or the owner) prices it. */
    public function list(Character $seller, System $fortress, SystemContent $file, int $price): ?string
    {
        if ($price < 1) {
            return 'bad_price';
        }

        if ($file->kind !== ContentKind::Data) {
            return 'not_in_market_area';
        }

        if ((int) $file->system_id !== (int) $fortress->getKey() || $fortress->market_area_id === null || (int) $file->area_id !== (int) $fortress->market_area_id) {
            return 'not_in_market_area';
        }

        $mine = (int) $file->deposited_by === (int) $seller->getKey() || FortressAccess::roleOf($seller, $fortress) === 'owner';

        if (! $mine) {
            return 'not_yours';
        }

        FortressListing::query()->updateOrCreate(
            ['content_id' => $file->getKey()],
            ['system_id' => $fortress->getKey(), 'seller_character_id' => $seller->getKey(), 'price' => $price],
        );

        return null;
    }

    public function unlist(Character $seller, FortressListing $listing): ?string
    {
        if ((int) $listing->seller_character_id !== (int) $seller->getKey()) {
            return 'not_yours';
        }

        $listing->delete();

        return null;
    }

    public function buy(Character $buyer, System $fortress, FortressListing $listing): ?string
    {
        if (! self::isOpen($fortress)) {
            return 'market_closed';
        }

        if (! self::mayVisit($buyer, $fortress)) {
            return 'not_yours';
        }

        $at = $buyer->gridPosition();

        if ($at === null || $at->x !== $fortress->x || $at->y !== $fortress->y) {
            return 'not_here';
        }

        return DB::transaction(function () use ($buyer, $fortress, $listing): ?string {
            $listing = FortressListing::query()->lockForUpdate()->find($listing->getKey());
            $file = $listing === null ? null : SystemContent::query()->find($listing->content_id);

            if ($listing === null || $file === null || (int) $listing->system_id !== (int) $fortress->getKey()) {
                return 'gone';
            }

            $buyer = Character::query()->lockForUpdate()->find($buyer->getKey());

            if ((int) $buyer->creds < (int) $listing->price) {
                return 'too_dear';
            }

            if (! Storage::fits($buyer, (float) $file->size_qb)) {
                return 'no_room';
            }

            $fortress = System::query()->lockForUpdate()->find($fortress->getKey());
            $price = (int) $listing->price;
            $tax = (int) floor($price * (int) $fortress->market_tax / 100);
            $seller = Character::query()->lockForUpdate()->find($listing->seller_character_id);

            Wallet::move($buyer, -$price, Reason::FortressMarket);

            if ($seller !== null) {
                Wallet::move($seller, $price - $tax, Reason::FortressSale);
            } else {
                $tax = $price;
            }

            $fortress->forceFill(['wallet' => (int) $fortress->wallet + $tax])->save();
            FortressLog::write($fortress, $buyer, 'sale', "{$file->label} for {$price} (tax {$tax})");

            (new Extractor)->deliver($buyer, $file);
            $file->delete();
            $listing->delete();

            return null;
        });
    }

    /** Take creds out of the fortress wallet: the owner and anybody who may administer it. Each movement is logged. */
    public function withdraw(Character $by, System $fortress, int $amount): ?string
    {
        if (! FortressAccess::can($by, $fortress, 'host')) {
            return 'not_yours';
        }

        return DB::transaction(function () use ($by, $fortress, $amount): ?string {
            $fortress = System::query()->lockForUpdate()->find($fortress->getKey());

            if ($amount < 1 || $amount > (int) $fortress->wallet) {
                return 'bad_amount';
            }

            $fortress->forceFill(['wallet' => (int) $fortress->wallet - $amount])->save();
            $by = Character::query()->lockForUpdate()->find($by->getKey());
            Wallet::move($by, $amount, Reason::FortressWallet);
            FortressLog::write($fortress, $by, 'wallet', "took {$amount}");

            return null;
        });
    }
}
