<?php

declare(strict_types=1);

namespace App\Implants;

use App\Models\Character;
use App\Models\InventoryItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `inventory_items.installed_at`.
 *
 * The rules the database cannot reach across a table to check have to live
 * somewhere, and somewhere means one place — the same reason ModuleFitter and
 * DeckEquipper exist. Here they are: the object is yours, it is an implant, it
 * is to hand rather than in a bag or a deck, and the region it wants has room.
 *
 * CAPACITY IS COUNTED PER REGION, not per implant. Four abdominal slots means
 * four altogether, so the count is a query over what is installed in that
 * region rather than anything stored — a stored count is a number that goes
 * wrong the first time a row is deleted around it.
 *
 * Installing and removing are both surgery in the fiction and neither is free,
 * but nothing charges for them yet. When something does, this is the class
 * that knows the character and the implant at once.
 */
final class ImplantFitter
{
    /**
     * Put an implant in.
     *
     * Locked and re-counted inside the transaction, because "is there room"
     * is a question two requests can both answer yes to.
     */
    public function install(Character $character, InventoryItem $implant): ImplantResult
    {
        if ($implant->character_id !== $character->getKey()) {
            return ImplantResult::refused(ImplantRefusal::NotOwned);
        }

        $region = $implant->item?->implantRegion();

        if ($region === null) {
            return ImplantResult::refused(ImplantRefusal::NotAnImplant);
        }

        if ($implant->installed_at !== null) {
            return ImplantResult::refused(ImplantRefusal::AlreadyInstalled);
        }

        // In a container, or fitted into a deck. Take it out yourself.
        if ($implant->parent_id !== null || $implant->fitting()->exists()) {
            return ImplantResult::refused(ImplantRefusal::NotToHand);
        }

        return DB::transaction(function () use ($character, $implant, $region): ImplantResult {
            $used = $this->installedIn($character, $region, lock: true)->count();

            if ($used >= $region->capacity()) {
                return ImplantResult::refused(ImplantRefusal::NoRoom);
            }

            $implant->forceFill(['installed_at' => CarbonImmutable::now()])->save();

            return ImplantResult::installed($implant);
        });
    }

    /**
     * Take one out.
     *
     * It goes back to the stash rather than being destroyed — the same as
     * stripping a deck, and for the same reason: removing is not losing.
     */
    public function remove(Character $character, InventoryItem $implant): ImplantResult
    {
        if ($implant->character_id !== $character->getKey()) {
            return ImplantResult::refused(ImplantRefusal::NotOwned);
        }

        if ($implant->installed_at === null) {
            return ImplantResult::refused(ImplantRefusal::NotInstalled);
        }

        $implant->forceFill(['installed_at' => null])->save();

        return ImplantResult::removed($implant);
    }

    /**
     * What is installed in one region.
     *
     * The region comes from the ITEM, never from the inventory row, so a row
     * cannot disagree with its definition about where it went.
     *
     * @return Collection<int, InventoryItem>
     */
    public function installedIn(Character $character, BodyRegion $region, bool $lock = false): Collection
    {
        $query = InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->whereNotNull('installed_at')
            ->whereHas('item', static fn ($item) => $item->where('implant_region', $region->value))
            ->with('item');

        return $lock ? $query->lockForUpdate()->get() : $query->get();
    }

    /**
     * Everything installed, grouped by region, with empty regions present.
     *
     * The body map draws every slot whether or not anything is in it, so the
     * shape it wants is every region rather than only the occupied ones.
     *
     * @return array<string, Collection<int, InventoryItem>>
     */
    public function body(Character $character): array
    {
        $installed = InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->whereNotNull('installed_at')
            ->with('item')
            ->get()
            ->groupBy(static fn (InventoryItem $row): string => $row->item?->implant_region ?? '');

        $body = [];

        foreach (BodyRegion::cases() as $region) {
            $body[$region->value] = $installed->get($region->value, new Collection);
        }

        return $body;
    }
}
