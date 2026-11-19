<?php

declare(strict_types=1);

namespace App\Cyberspace\Party;

use App\Cyberspace\RunEnding;
use App\Decks\Storage;
use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Run;
use App\Models\RunParty;
use App\Models\RunPoolItem;
use App\Programs\ProgramKind;
use App\Support\Text;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE RUN POOL: items a runner puts up for the others of a co-op run to claim for that run only. THE ONLY WRITER of `run_pool_items`.
 *
 * - An offered item LEAVES the offerer's stash (one copy) and sits in the pool, so nothing else can touch it. A claim hands a copy to
 *   the claimant's stash. Neither a claimed nor an offered copy is ever "the same row": copies of one item are interchangeable.
 * - What may go in: Scripts, icebreakers, evasion programs and Firewall Exploits, loose, unlisted and not running, and not ticked in
 *   the runner's saved loadout. (Leechers are left out: docs/TODO.md.)
 * - It is a PLANNING thing: unclaimed items go back to their offerers when the party launches, and everything goes back when a member
 *   leaves or the party goes. A claimant's unused copies go back to the offerer when their run ends, and are destroyed if it ends badly.
 * - A party row cascades its entries, so a caller deletes a party only AFTER `refundParty()`.
 */
class RunPool
{
    /** The program kinds a runner can lend. */
    private const KINDS = [ProgramKind::Script, ProgramKind::Icebreaker, ProgramKind::Evasion];

    /** Shown to a party that has somebody to trade with: the owner, and one other who joined. */
    public function open(RunParty $party): bool
    {
        return $party->started_at === null && count($party->crew()) >= 2;
    }

    /** @return Collection<int, InventoryItem> What this runner could put in, one row per stack. */
    public function eligible(Character $character, RunParty $party): Collection
    {
        $ticked = $party->owner_character_id === $character->getKey()
            ? (array) $party->owner_loadout
            : (array) $party->members()->where('character_id', $character->getKey())->first()?->loadout;

        return $character->inventory()->loose()
            ->whereDoesntHave('offer')
            ->whereNotIn('id', array_map('intval', $ticked))
            ->whereHas('item', fn ($item) => $item->where(fn ($q) => $q
                ->whereHas('program', fn ($p) => $p->whereIn('kind', array_map(static fn (ProgramKind $k): string => $k->value, self::KINDS)))
                ->orWhereNotNull('key_grade')))
            ->with('item.program')
            ->get()
            ->sortBy(fn (InventoryItem $row): string => mb_strtolower($row->item->displayName()))
            ->values();
    }

    /** What an item is, for the table's Type column: its program kind, or a Firewall Exploit. */
    public static function typeOf(Item $item): string
    {
        return $item->program?->kind->label() ?? Text::get('programs.kinds.firewall_exploit');
    }

    /** @return Collection<int, RunPoolItem> */
    public function entries(RunParty $party): Collection
    {
        return RunPoolItem::query()->where('run_party_id', $party->getKey())->with(['item.program', 'offerer', 'claimant'])->orderBy('id')->get();
    }

    public function offer(Character $character, int $inventoryId): ?PoolRefusal
    {
        return DB::transaction(function () use ($character, $inventoryId): ?PoolRefusal {
            $party = $this->partyOf($character);

            if ($party === null) {
                return PoolRefusal::NoParty;
            }

            if (! $this->open($party)) {
                return PoolRefusal::Alone;
            }

            $row = $this->eligible($character, $party)->firstWhere('id', $inventoryId);

            if ($row === null) {
                return PoolRefusal::NotEligible;
            }

            RunPoolItem::query()->create(['run_party_id' => $party->getKey(), 'item_id' => $row->item_id, 'offered_by_character_id' => $character->getKey()]);
            $row->consumeOne();

            return null;
        });
    }

    public function claim(Character $character, int $entryId): ?PoolRefusal
    {
        return DB::transaction(function () use ($character, $entryId): ?PoolRefusal {
            $entry = RunPoolItem::query()->whereKey($entryId)->lockForUpdate()->with('item.program')->first();
            $party = $this->partyOf($character);

            if ($entry === null || $party === null || (int) $entry->run_party_id !== (int) $party->getKey() || $entry->claimed_by_character_id !== null) {
                return PoolRefusal::Gone;
            }

            if ((int) $entry->offered_by_character_id === (int) $character->getKey()) {
                return PoolRefusal::Yours;
            }

            if (! Storage::fits($character, Storage::sizeOf($entry->item))) {
                return PoolRefusal::NoRoom;
            }

            ItemGranter::grant($character, $entry->item, 1);
            $entry->update(['claimed_by_character_id' => $character->getKey()]);

            return null;
        });
    }

    /** The offerer withdraws an unclaimed copy; the claimant puts a claimed one back into the pool. */
    public function release(Character $character, int $entryId): ?PoolRefusal
    {
        return DB::transaction(function () use ($character, $entryId): ?PoolRefusal {
            $entry = RunPoolItem::query()->whereKey($entryId)->lockForUpdate()->with('item')->first();
            $party = $this->partyOf($character);

            if ($entry === null || $party === null || (int) $entry->run_party_id !== (int) $party->getKey() || $party->started_at !== null) {
                return PoolRefusal::Gone;
            }

            if ($entry->claimed_by_character_id === null) {
                if ((int) $entry->offered_by_character_id !== (int) $character->getKey()) {
                    return PoolRefusal::NotYours;
                }

                $this->giveBack($entry);

                return null;
            }

            if ((int) $entry->claimed_by_character_id !== (int) $character->getKey()) {
                return PoolRefusal::NotYours;
            }

            $copy = $this->looseCopy($character, $entry->item);

            if ($copy === null) {
                return PoolRefusal::NotUnused;
            }

            $copy->consumeOne();
            $entry->update(['claimed_by_character_id' => null]);

            return null;
        });
    }

    /** Everything in a party goes back to its offerers: before the party is deleted. */
    public function refundParty(int $partyId): void
    {
        RunPoolItem::query()->where('run_party_id', $partyId)->with('item')->get()->each(fn (RunPoolItem $e) => $this->giveBack($e));
    }

    /** A runner leaves the party at the door: what they offered goes back to them, and what they hold goes back to whoever offered it. */
    public function refundMember(RunParty $party, Character $character): void
    {
        RunPoolItem::query()->where('run_party_id', $party->getKey())
            ->where(fn ($q) => $q->where('offered_by_character_id', $character->getKey())->orWhere('claimed_by_character_id', $character->getKey()))
            ->with('item')->get()->each(fn (RunPoolItem $e) => $this->giveBack($e));
    }

    /**
     * The party launches: the pool is over. Unclaimed copies go back, and so do those claimed by a member who did not go in. What the
     * runners who did go in hold stays with them until their run ends (`settleRun`).
     *
     * @param  list<int>  $inIds  the characters who started a run
     */
    public function settleLaunch(RunParty $party, array $inIds): void
    {
        RunPoolItem::query()->where('run_party_id', $party->getKey())->with('item')->get()->each(function (RunPoolItem $e) use ($inIds): void {
            if ($e->claimed_by_character_id === null || ! in_array((int) $e->claimed_by_character_id, $inIds, true)) {
                $this->giveBack($e);
            }
        });
    }

    /**
     * A claimant's run ended: what they still hold unused goes back to the offerer, or is destroyed if it ended badly.
     * Used up (a key spent, a Script that ran out) there is nothing to return.
     */
    public function settleRun(Character $character, Run $run, RunEnding $ending): void
    {
        if ($run->run_party_id === null) {
            return;
        }

        RunPoolItem::query()->where('run_party_id', $run->run_party_id)->where('claimed_by_character_id', $character->getKey())->with('item')
            ->get()->each(function (RunPoolItem $e) use ($ending): void {
                if ($ending->isBad()) {
                    $this->takeBack($e);
                    $e->delete();

                    return;
                }

                $this->giveBack($e);
            });
    }

    /** The copy goes to the offerer (taken from the claimant first, if it was claimed and is still unused), and the entry goes. */
    private function giveBack(RunPoolItem $entry): void
    {
        $entry->loadMissing('item', 'offerer');

        if ($entry->claimed_by_character_id !== null && ! $this->takeBack($entry)) {
            $entry->delete();

            return;
        }

        if ($entry->offerer !== null && $entry->item !== null) {
            ItemGranter::grant($entry->offerer, $entry->item, 1);
        }

        $entry->delete();
    }

    /** Remove the claimant's unused copy; false when they no longer hold one (it was used). */
    private function takeBack(RunPoolItem $entry): bool
    {
        $claimant = Character::query()->find($entry->claimed_by_character_id);
        $row = $claimant === null || $entry->item === null ? null : $this->looseCopy($claimant, $entry->item);

        if ($row === null) {
            return false;
        }

        $row->consumeOne();

        return true;
    }

    private function looseCopy(Character $character, Item $item): ?InventoryItem
    {
        return $character->inventory()->loose()->where('item_id', $item->getKey())->whereDoesntHave('offer')->first();
    }

    private function partyOf(Character $character): ?RunParty
    {
        return RunParty::query()->whereNull('started_at')
            ->where(fn ($q) => $q->where('owner_character_id', $character->getKey())
                ->orWhereHas('members', fn ($m) => $m->where('character_id', $character->getKey())->whereNotNull('joined_at')->whereNull('dropped_at')))
            ->first();
    }
}
