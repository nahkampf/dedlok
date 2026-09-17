<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\SystemContent;
use Illuminate\Support\Facades\DB;

/**
 * Taking what a machine holds, and making it the character's.
 *
 * A NODE'S INVENTORY IS ITS OWN MODEL, and this is the one door between it and
 * a stash — `system_contents` rows are never touched anywhere else, which is
 * what keeps "what is in a node" answerable by one query and "did somebody
 * already take it" a fact this class alone decides.
 *
 * ## The whole haul, in one turn
 *
 * CYBERSPACE.md prices a bigger haul in TURNS — a file's size in QB costs more
 * of them, cut back down by the deck's bandwidth — and that per-file transfer
 * cost is not built. What is built is the simpler half: everything a machine
 * is holding comes out in the one turn extraction is attempted, or none of it
 * does. The turns-per-QB economy is a real gap and is meant to be closed later
 * without this class changing shape — it already returns one line per kind
 * taken, which is what a multi-turn version would still need.
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
     * Take everything system $address is holding, right now.
     *
     * Nothing to take is an ordinary answer, not a failure — the node might
     * never have held anything, or somebody else's raid might have beaten this
     * one to it, which is exactly what CONCURRENT RUNNERS means: the first
     * extraction takes it.
     */
    public function extract(Character $character, string $address): ExtractionResult
    {
        return DB::transaction(function () use ($character, $address): ExtractionResult {
            $contents = SystemContent::query()
                ->whereHas('system', fn ($query) => $query->where('address', $address))
                ->get();

            if ($contents->isEmpty()) {
                return ExtractionResult::empty();
            }

            $creds = 0;
            $items = 0;
            $files = 0;

            foreach ($contents as $content) {
                match ($content->kind) {
                    ContentKind::Creds => $creds += $this->takeCreds($character, $content),
                    ContentKind::Item => $items += $this->takeItem($character, $content),
                    ContentKind::Data => $files += $this->takeFile($character, $content),
                };
            }

            // Consumed. The node restocks on its own clock — NodeStocker tops
            // nightly rather than this class deciding when it is empty enough
            // to refill, the same division of labour the market's shelf keeps.
            SystemContent::query()->whereIn('id', $contents->pluck('id'))->delete();

            return new ExtractionResult(creds: $creds, items: $items, files: $files);
        });
    }

    private function takeCreds(Character $character, SystemContent $content): int
    {
        $amount = (int) $content->amount;

        if ($amount <= 0) {
            return 0;
        }

        $character->forceFill(['creds' => $character->creds + $amount])->save();

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

        if (! $item->stackable) {
            for ($copy = 0; $copy < $quantity; $copy++) {
                $character->inventory()->create(['item_id' => $item->id, 'parent_id' => null, 'quantity' => 1]);
            }

            return $quantity;
        }

        // Merge rather than insert — the same rule LoadoutGranter follows, and
        // for the same reason: two piles of the same stackable thing in one
        // place are one pile, however each pile arrived.
        $existing = InventoryItem::query()
            ->where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->whereNull('parent_id')
            ->first();

        if ($existing !== null) {
            $existing->quantity += $quantity;
            $existing->save();
        } else {
            $character->inventory()->create(['item_id' => $item->id, 'parent_id' => null, 'quantity' => $quantity]);
        }

        return $quantity;
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
    private function takeFile(Character $character, SystemContent $content): int
    {
        $dump = Item::query()->where('slug', self::DUMP_SLUG)->first();

        if ($dump === null) {
            return 0;
        }

        $character->inventory()->create([
            'item_id' => $dump->id,
            'parent_id' => null,
            'quantity' => 1,
            'extracted_file' => ExtractedFile::fromContent($content)->toArray(),
        ]);

        return 1;
    }
}
