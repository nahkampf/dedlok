<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Decks\Storage;
use App\Models\Character;
use App\Models\FortressArea;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\System;
use App\Models\SystemContent;
use App\Support\Qb;
use Illuminate\Support\Facades\DB;

/**
 * Files kept in a Data Fortress, as ordinary `system_contents` data rows on the fortress system so the same extraction
 * machinery reads them. Counts against the fortress's storage total. (The file's origin is not kept in the vault.)
 */
final class FortressVault
{
    public function deposit(Character $character, InventoryItem $row, System $fortress, FortressHost $host, Leeches $leeches, ?int $areaId = null): ?string
    {
        // Anything that is not a stolen file (a component, a Design, a program) is kept as an item.
        $slug = $row->item?->slug;
        $isFile = in_array($slug, [ExtractedFile::SEALED_SLUG, ExtractedFile::DECRYPTED_SLUG], true);
        $file = $isFile ? ExtractedFile::fromArray($row->extracted_file) : null;

        $area = $areaId === null ? null : FortressArea::query()->where('system_id', $fortress->getKey())->find($areaId);

        if ($areaId !== null && $area === null) {
            return 'not_yours';
        }

        if (! FortressAccess::areaCan($character, $fortress, $area, 'deposit')) {
            return 'not_yours';
        }

        if ($row->character_id !== $character->getKey()) {
            return 'not_yours';
        }

        if (! $isFile) {
            return $this->depositItem($character, $row, $fortress, $host, $leeches, $area);
        }

        if ($file === null) {
            return 'not_a_file';
        }

        return DB::transaction(function () use ($character, $row, $fortress, $file, $host, $leeches, $area): ?string {
            $free = $leeches->totals($fortress)['storage'] - $host->used($fortress)['storage'];

            if (Qb::tenths($file->sizeQb) > Qb::tenths($free)) {
                return 'no_storage';
            }

            SystemContent::query()->create([
                'system_id' => $fortress->getKey(),
                'kind' => ContentKind::Data->value,
                'label' => $file->label,
                'size_qb' => $file->sizeQb,
                'encryption_skill_id' => $file->encryptionSkillId,
                'value_sealed' => $file->valueSealed,
                'value_broken' => $file->valueBroken,
                'area_id' => $area?->getKey(),
                'deposited_by' => $character->getKey(),
                // Where it came from and whether it is still sealed, so a Decryptor can read its grade and a withdrawal gives it back as it was.
                'meta' => ['extracted_file' => $file->toArray(), 'slug' => $slug],
            ]);

            FortressLog::write($fortress, $character, 'deposit', $file->label.($area === null ? '' : " -> {$area->name}"));
            $row->delete();

            return null;
        });
    }

    /** Keep a whole inventory row (a data item) in an area, carrying what the copy carried. */
    private function depositItem(Character $character, InventoryItem $row, System $fortress, FortressHost $host, Leeches $leeches, ?FortressArea $area): ?string
    {
        $item = $row->item;

        if ($item === null || ! $item->isData() || $row->isRunning() || $row->fortress_system_id !== null || $row->isListed()) {
            return 'not_a_file';
        }

        return DB::transaction(function () use ($character, $row, $fortress, $host, $leeches, $area, $item): ?string {
            $size = Qb::of(Storage::sizeOf($item) * (int) $row->quantity);
            $free = $leeches->totals($fortress)['storage'] - $host->used($fortress)['storage'];

            if (Qb::tenths($size) > Qb::tenths($free)) {
                return 'no_storage';
            }

            $meta = array_filter(['extracted_file' => $row->extracted_file, 'defence' => $row->defence]);

            SystemContent::query()->create([
                'system_id' => $fortress->getKey(),
                'kind' => ContentKind::Item->value,
                'item_id' => $item->getKey(),
                'quantity' => (int) $row->quantity,
                'area_id' => $area?->getKey(),
                'deposited_by' => $character->getKey(),
                'meta' => $meta === [] ? null : $meta,
            ]);

            FortressLog::write($fortress, $character, 'deposit', $item->displayName().' x'.$row->quantity.($area === null ? '' : " -> {$area->name}"));
            $row->delete();

            return null;
        });
    }

    public function withdraw(Character $character, SystemContent $content, System $fortress): ?string
    {
        $area = $content->area_id === null ? null : FortressArea::query()->find($content->area_id);

        if ((int) $content->system_id !== (int) $fortress->getKey() || ! FortressAccess::areaCan($character, $fortress, $area, 'withdraw')) {
            return 'not_yours';
        }

        $isItem = $content->kind === ContentKind::Item;
        $size = $isItem ? Qb::of(Storage::sizeOf($content->item) * (int) $content->quantity) : (float) $content->size_qb;

        if (! Storage::fits($character, $size)) {
            return 'no_room';
        }

        DB::transaction(function () use ($character, $content, $fortress, $isItem): void {
            if ($isItem) {
                $meta = (array) $content->meta;
                $character->inventory()->create(array_filter([
                    'item_id' => $content->item_id,
                    'parent_id' => null,
                    'quantity' => (int) $content->quantity,
                    'extracted_file' => $meta['extracted_file'] ?? null,
                    'defence' => $meta['defence'] ?? null,
                ], fn ($v, $k): bool => $v !== null || in_array($k, ['parent_id'], true), ARRAY_FILTER_USE_BOTH));
                FortressLog::write($fortress, $character, 'withdraw', ($content->item?->displayName() ?? '').' x'.$content->quantity);
            } else {
                FortressLog::write($fortress, $character, 'withdraw', (string) $content->label);
                $meta = (array) $content->meta;
                $kept = isset($meta['extracted_file']) ? Item::query()->where('slug', $meta['slug'] ?? ExtractedFile::SEALED_SLUG)->first() : null;

                if ($kept !== null) {
                    $character->inventory()->create(['item_id' => $kept->getKey(), 'parent_id' => null, 'quantity' => 1, 'extracted_file' => $meta['extracted_file']]);
                } else {
                    (new Extractor)->deliver($character, $content);
                }
            }

            $content->delete();
        });

        return null;
    }

    /** Total QB kept in the vault: files and items. */
    public static function stored(System $fortress): float
    {
        $files = (float) SystemContent::query()->where('system_id', $fortress->getKey())->ofKind(ContentKind::Data)->sum('size_qb');
        $items = SystemContent::query()->where('system_id', $fortress->getKey())->ofKind(ContentKind::Item)->with('item')->get()
            ->sum(fn (SystemContent $c): float => $c->item === null ? 0.0 : Storage::sizeOf($c->item) * (int) $c->quantity);

        return Qb::of($files + $items);
    }
}
