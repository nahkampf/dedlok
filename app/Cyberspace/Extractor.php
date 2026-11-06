<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Cyberspace\Party\PartyShare;
use App\Decks\Storage;
use App\Inventory\ItemGranter;
use App\Missions\MissionLedger;
use App\Models\Character;
use App\Models\Item;
use App\Models\SystemContent;
use App\Support\Qb;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Taking what a machine holds, and making it the character's.
 *
 * A NODE'S INVENTORY IS ITS OWN MODEL, and this is the one door between it and
 * a stash — `system_contents` rows are never touched anywhere else, which is
 * what keeps "what is in a node" answerable by one query and "did somebody
 * already take it" a fact this class alone decides.
 *
 * ## Creds and items are instant; files cost turns
 *
 * `App\Cyberspace\Simulator` is where the turns-per-QB economy actually lives
 * — this class only exposes the pieces it needs to spend them one turn at a
 * time: `totalFileQb()` to size a transfer before committing to it,
 * `takeCredsAndItems()` for the half of a haul that never waits, and
 * `takeFiles()` for the half that does, called once a transfer's countdown
 * reaches zero. `extract()` still takes everything in one call — composed
 * from those same two methods — for every caller that does not need the
 * turns to be interactive, tests included.
 *
 * ## Three kinds, three ways of becoming a possession
 *
 * Creds are a number and go straight onto the character. A catalogue item is
 * granted through the same merge-or-create the archetype loadout already uses,
 * because two piles of one stackable thing in one place are one pile however
 * they arrived. A file has no catalogue row — see `ExtractedFile` — so it
 * becomes one unstackable "Encrypted Data Dump", carrying its real numbers as
 * that row's own JSON.
 *
 * Written only from `App\Cyberspace\Simulator`, inside the same transaction
 * the turn resolves in: a haul that transferred half of itself because the
 * request died partway through would be worse than one that never started.
 */
final readonly class Extractor
{
    /** The item every extracted file becomes. Looked up by slug, never cached — this runs once a turn at most. */
    private const DUMP_SLUG = 'encrypted-data-dump';

    /**
     * Take everything system $address is holding, right now, in one call.
     *
     * Composed from `takeCredsAndItems()` and `takeFiles()` rather than its
     * own query — a caller with no reason to spread a haul across turns
     * (a test, an atomic tool, anything that is not the interactive
     * simulator) still gets one door and one result.
     */
    public function extract(Character $character, string $address): ExtractionResult
    {
        return DB::transaction(function () use ($character, $address): ExtractionResult {
            $instant = $this->takeCredsAndItems($character, $address);
            $files = $this->takeFiles($character, $address);

            return new ExtractionResult(
                creds: $instant->creds,
                items: $instant->items,
                files: $files->files,
                leftBehind: $instant->leftBehind || $files->leftBehind,
                taken: $this->merged($instant->taken, $files->taken),
            );
        });
    }

    /**
     * How much QB of DATA this node is still holding.
     *
     * READ ONLY, and the number a multi-turn transfer sizes itself against
     * before committing to it. Asked again on the turn a transfer completes,
     * too, so a raider who started counting down against a file somebody else
     * finished taking first sees that honestly rather than trusting a number
     * from turns ago.
     */
    public function filesFit(Character $character, string $address): bool
    {
        return Storage::fits($character, $this->totalFileQb($address, $character));
    }

    public function totalFileQb(string $address, ?Character $for = null): float
    {
        return Qb::of(SystemContent::query()
            ->whereHas('system', fn ($query) => $query->where('address', $address))
            ->visibleTo((int) $for?->getKey())
            ->ofKind(ContentKind::Data)
            ->sum('size_qb'));
    }

    /**
     * Creds and catalogue items only — the half of a haul docs/CYBERSPACE.md never
     * prices in turns. Takes them the moment extraction reaches the core,
     * whatever a file sitting alongside them still costs to pull.
     *
     * Nothing to take is an ordinary answer, not a failure — the node might
     * hold no creds or items at all, or somebody else's raid might have
     * beaten this one to them.
     */
    public function takeCredsAndItems(Character $character, string $address): ExtractionResult
    {
        return DB::transaction(function () use ($character, $address): ExtractionResult {
            $contents = SystemContent::query()
                ->whereHas('system', fn ($query) => $query->where('address', $address))
                ->whereIn('kind', [ContentKind::Creds->value, ContentKind::Item->value])
                ->get();

            if ($contents->isEmpty()) {
                return ExtractionResult::empty();
            }

            $creds = 0;
            $items = 0;
            $room = Storage::free($character);
            $left = [];
            $taken = [];
            $skipped = [];

            foreach ($contents as $content) {
                // A raid is never in the room: only DATA comes out of a system. A physical item a stock rule
                // put there stays where it is, and is not a reason to say something was left for want of room.
                if ($content->kind === ContentKind::Item && ! ($content->item?->isData() ?? false)) {
                    $skipped[] = $content->id;

                    continue;
                }

                // What the deck cannot hold stays on the system, still there
                // for the next run — never taken and lost.
                if ($content->kind === ContentKind::Item) {
                    $size = $content->item === null ? 0 : Qb::of(Storage::sizeOf($content->item) * (int) $content->quantity);

                    if (Qb::tenths($size) > Qb::tenths($room)) {
                        $left[] = $content->id;

                        continue;
                    }

                    $room = Qb::of($room - $size);
                }

                match ($content->kind) {
                    ContentKind::Creds => $creds += $this->takeCreds($character, $content),
                    ContentKind::Item => $items += $got = $this->takeItem($character, $content),
                    ContentKind::Data => null,
                };

                if ($content->kind === ContentKind::Item && ($got ?? 0) > 0) {
                    $name = $content->item->displayName();
                    $taken[$name] = ($taken[$name] ?? 0) + $got;
                }
            }

            SystemContent::query()->whereIn('id', $contents->pluck('id')->diff($left)->diff($skipped))->delete();

            return new ExtractionResult(creds: $creds, items: $items, leftBehind: $left !== [], taken: $taken);
        });
    }

    /**
     * Every file the node is holding, however many turns it took to pull them.
     *
     * NOTHING TO TAKE is an ordinary answer here too, and the one a completing
     * transfer has to be ready for: pricing a file in turns is exactly what
     * lets somebody else's raid finish first while this one is still counting
     * down.
     */
    public function takeFiles(Character $character, string $address): ExtractionResult
    {
        return DB::transaction(function () use ($character, $address): ExtractionResult {
            $contents = SystemContent::query()
                ->with('system')
                ->whereHas('system', fn ($query) => $query->where('address', $address))
                ->visibleTo((int) $character->getKey())
                ->ofKind(ContentKind::Data)
                ->get();

            if ($contents->isEmpty()) {
                return ExtractionResult::empty();
            }

            // All or nothing, because the pull was priced on the total.
            if (! Storage::fits($character, Qb::sum($contents->pluck('size_qb')))) {
                return new ExtractionResult(leftBehind: true);
            }

            $files = 0;
            $taken = [];

            // Who gets which file: a party deals them by its extraction mode, in the order they come off the node.
            $share = PartyShare::for($character);
            $deal = [];

            if ($share !== null) {
                foreach ($share->divide($contents->count(), $share->extraction()) as $id => $count) {
                    array_push($deal, ...array_fill(0, $count, $id));
                }
            }

            foreach ($contents->values() as $i => $content) {
                $to = $character;
                // A job file is its owner's, whoever pulled it.
                $id = $content->for_character_id ?? $deal[$i] ?? $character->getKey();

                if ($id !== $character->getKey()) {
                    $candidate = $this->runner($id);

                    if ($candidate !== null && Storage::fits($candidate, (float) $content->size_qb)) {
                        $to = $candidate;
                    }
                }

                $name = $this->takeFile($to, $content);

                if ($name !== null) {
                    $files++;
                    $taken[$name] = ($taken[$name] ?? 0) + 1;
                    $share?->record($to->getKey(), 'file', $name);
                }
            }

            // Consumed. The node restocks on its own clock — NodeStocker tops
            // nightly rather than this class deciding when it is empty enough
            // to refill, the same division of labour the market's shelf keeps.
            SystemContent::query()->whereIn('id', $contents->pluck('id'))->delete();

            return new ExtractionResult(files: $files, taken: $taken);
        });
    }

    /** Pay creds taken from a system into the raider's wallet, or the party's by its credits mode. */
    public function payCreds(Character $character, int $amount, ?int $systemId = null): void
    {
        // A party divides it by the owner's chosen mode; a solo runner takes all.
        $share = PartyShare::for($character);
        $parts = $share === null ? [$character->getKey() => $amount] : $share->divide($amount, $share->credits());

        foreach ($parts as $id => $part) {
            $who = $id === $character->getKey() ? $character : $this->runner($id);

            if ($who === null || $part <= 0) {
                continue;
            }

            // A heist's target keeps the take for the desk; everything else is the raider's.
            if ($systemId === null || ! app(MissionLedger::class)->holdProceeds($who, $systemId, $part)) {
                Wallet::move($who, $part, Reason::Extraction);
                $share?->record($who->getKey(), 'creds', '', $part);
            }

            // Counted HERE rather than at either call site, because this is the
            // one place creds come off a system — `extract()` and the simulator's
            // turn both arrive through it, and a second call site would be a
            // second answer to how much somebody has ever raided.
            (new Achievements)->record($who, Metric::RaidCreds, $part);
        }
    }

    private function takeCreds(Character $character, SystemContent $content): int
    {
        $amount = (int) $content->amount;

        if ($amount <= 0) {
            return 0;
        }

        $this->payCreds($character, $amount, (int) $content->system_id);

        return $amount;
    }

    /**
     * A real catalogue item — never anything physical, since a raid is never
     * in the room. `NodeStocker` only ever stocks `on_market` items whose
     * container is `data`, so nothing here has to check that again.
     */
    private function takeItem(Character $character, SystemContent $content): int
    {
        $quantity = (int) $content->quantity;

        if ($content->item_id === null || $quantity <= 0) {
            return 0;
        }

        $item = $content->item;

        if ($item === null) {
            return 0;
        }

        // The merge-or-create is App\Inventory\ItemGranter, shared with the
        // archetype loadout and the market: two piles of the same stackable
        // thing in one place are one pile, however each pile arrived.
        $share = PartyShare::for($character);

        if ($share === null) {
            return $this->stamped($character, $item, $content, ItemGranter::grant($character, $item, $quantity));
        }

        // A party deals the copies out; whoever has no room for theirs leaves it with the one who pulled it.
        $granted = 0;
        $size = Storage::sizeOf($item);

        foreach ($share->divide($quantity, $share->extraction()) as $id => $count) {
            if ($count <= 0) {
                continue;
            }

            $who = $id === $character->getKey() ? $character : $this->runner($id);

            if ($who === null || $who->getKey() !== $character->getKey() && ! Storage::fits($who, Qb::of($size * $count))) {
                $who = $character;
            }

            $got = $this->stamped($who, $item, $content, ItemGranter::grant($who, $item, $count));
            $granted += $got;
            $share->record($who->getKey(), 'item', $item->name, $got);
        }

        return $granted;
    }

    /**
     * A Subnet Certificate is valid for the subnet it was taken from and no other: the fresh copies are stamped with the
     * node's grid. Anything else passes straight through.
     */
    private function stamped(Character $who, Item $item, SystemContent $content, int $granted): int
    {
        if ($granted > 0 && $item->slug === Fortresses::CERTIFICATE_SLUG) {
            $who->inventory()->where('item_id', $item->getKey())->whereNull('grid_id')->update(['grid_id' => (int) ($content->system?->grid_id ?? 1)]);
        }

        return $granted;
    }

    private function runner(int $id): ?Character
    {
        return Character::query()->lockForUpdate()->find($id);
    }

    /**
     * A generated file, with no catalogue row of its own.
     *
     * UNSTACKABLE, ALWAYS — the dump item's own `stackable` says so, but it is
     * worth saying again here: two files with different sizes and different
     * values merging into one row with a summed quantity would be exactly the
     * bug `stack_key` exists to prevent, on the one item where merging would
     * also destroy information a quantity cannot hold.
     */
    /** Hand one file to a character as an inventory row; the caller deletes the content. Returns its label. */
    public function deliver(Character $to, SystemContent $content): ?string
    {
        return $this->takeFile($to, $content);
    }

    private function takeFile(Character $character, SystemContent $content): ?string
    {
        $dump = Item::query()->where('slug', self::DUMP_SLUG)->first();

        if ($dump === null) {
            return null;
        }

        $row = $character->inventory()->create([
            'item_id' => $dump->id,
            'parent_id' => null,
            'quantity' => 1,
            'extracted_file' => ExtractedFile::fromContent($content)->toArray(),
        ]);

        return $row->load('item')->label();
    }

    /**
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     * @return array<string, int>
     */
    private function merged(array $a, array $b): array
    {
        foreach ($b as $name => $amount) {
            $a[$name] = ($a[$name] ?? 0) + $amount;
        }

        return $a;
    }
}
