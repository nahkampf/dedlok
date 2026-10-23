<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Checks\CheckRoller;
use App\Crafting\Crafting;
use App\Decks\Storage;
use App\Decryption\DecryptionQueue;
use App\Models\Character;
use App\Models\Design;
use App\Models\FortressArea;
use App\Models\FortressAssembly;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemContent;
use App\Notifications\Notifier;
use App\Programs\ProgramKind;
use App\Stats\Resolver;
use App\Support\Qb;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What a Data Fortress's running Decryptors and Assemblers make (CYBERSPACE.md, *Production*).
 *
 * **A Decryptor** takes the oldest Encrypted Data Dump in its type's input area and, one cycle later, turns it into a component in
 * the output area: deterministic, no roll, and the component is picked exactly as a player's decryption picks one
 * (`DecryptionQueue::outcomeFor()`, by the file's grade) and keeps the file's origin. The cycle is the item's own minutes as the
 * stat `production_cycle_minutes.decryptor` resolves them for whoever installed it, shortened by every running Booster.
 *
 * **An Assembler** is assigned one Design and a number of runs (`fortress_assemblies`). A run takes the Design's craft time less the
 * item's own reduction, then the stat `production_cycle_minutes.assembler`, then the Boosters; the Design's whole recipe is spent
 * from the input area when it starts, and the product lands in the output area (waiting, finished, without room).
 *
 * Both are worked from minutes: each row keeps its own progress in `inventory_items.defence`, and nothing is worked while a
 * producer is stopped (a short fortress stops them first). A producer with nothing to work on waits and banks no time.
 */
final class FortressProduction
{
    /** An upper bound on steps per producer per settle, so a long absence is not a loop without end. */
    private const STEPS = 200;

    /** @param  \Closure(int, int): int  $roll  the die for what a Dump becomes */
    public function __construct(private readonly \Closure $roll) {}

    public static function make(): self
    {
        return new self(static fn (int $min, int $max): int => random_int($min, $max));
    }

    /** Set what an Assembler makes: a Design (null to clear it) and a number of runs (0 for ever). Cancels a run in progress. */
    public function assign(Character $by, System $fortress, InventoryItem $row, ?int $designItemId, int $runs): ?string
    {
        if (! FortressAccess::can($by, $fortress, 'host')) {
            return 'not_yours';
        }

        if ((int) $row->fortress_system_id !== (int) $fortress->getKey() || $row->item?->program?->kind !== ProgramKind::FortressAssembler) {
            return 'not_yours';
        }

        if ($designItemId !== null && Design::query()->where('item_id', $designItemId)->doesntExist()) {
            return 'no_design';
        }

        // Anything in progress is forfeited: the components were spent when the run started.
        FortressAssembly::query()->updateOrCreate(['inventory_item_id' => $row->getKey()], [
            'design_item_id' => $designItemId, 'runs' => max(0, min(100000, $runs)), 'runs_done' => 0,
            'started' => false, 'work_minutes' => 0, 'progress_minutes' => 0,
        ]);
        FortressLog::write($fortress, $by, 'produce', 'assembler set');

        return null;
    }

    /**
     * A producer is being taken down: what it was holding goes back into storage (a Dump into its input area, a finished
     * component into its output area), and an Assembler's assignment goes with it.
     */
    public function release(System $fortress, InventoryItem $row): void
    {
        if (! in_array($row->item?->program?->kind, [ProgramKind::FortressDecryptor, ProgramKind::FortressAssembler], true)) {
            return;
        }

        FortressAssembly::query()->where('inventory_item_id', $row->getKey())->delete();
        $state = (array) $row->defence;

        if (isset($state['job']) && ($file = ExtractedFile::fromArray($state['job'])) !== null) {
            SystemContent::query()->create([
                'system_id' => $fortress->getKey(), 'kind' => ContentKind::Data->value, 'label' => $file->label, 'size_qb' => $file->sizeQb,
                'encryption_skill_id' => $file->encryptionSkillId, 'value_sealed' => $file->valueSealed, 'value_broken' => $file->valueBroken,
                'area_id' => $fortress->decryptor_in_area_id, 'meta' => ['extracted_file' => $file->toArray(), 'slug' => ExtractedFile::SEALED_SLUG],
            ]);
        }

        if (isset($state['ready'])) {
            $this->putComponent($fortress, $state['ready']);
        }

        $row->forceFill(['defence' => null])->save();
    }

    /** The owner or an administrator picks the input and output storage areas of one type (`decryptor` or `assembler`). */
    public function setAreas(Character $by, System $fortress, string $type, ?int $inArea, ?int $outArea): ?string
    {
        if (! FortressAccess::can($by, $fortress, 'host') || ! in_array($type, ['decryptor', 'assembler'], true)) {
            return 'not_yours';
        }

        foreach ([$inArea, $outArea] as $areaId) {
            if ($areaId !== null && ! FortressArea::query()->where('system_id', $fortress->getKey())->whereKey($areaId)->exists()) {
                return 'not_yours';
            }
        }

        $fortress->forceFill(["{$type}_in_area_id" => $inArea, "{$type}_out_area_id" => $outArea])->save();
        FortressLog::write($fortress, $by, 'settings', "{$type} areas {$inArea} -> {$outArea}");

        return null;
    }

    /** Work every producer for the time since the last settle. Safe to call as often as you like. */
    public function settle(System $fortress): void
    {
        if (! $fortress->type->isFortress() || $fortress->abandoned_at !== null || Fortresses::isFrozen($fortress)) {
            $fortress->forceFill(['production_at' => now()])->save();

            return;
        }

        $since = $fortress->production_at ?? now();
        $minutes = min(24 * 7 * 60.0, max(0.0, $since->diffInSeconds(now(), false) / 60));
        $fortress->forceFill(['production_at' => now()])->save();

        if ($minutes <= 0) {
            return;
        }

        DB::transaction(function () use ($fortress, $minutes): void {
            $running = InventoryItem::query()->where('fortress_system_id', $fortress->getKey())->where('fortress_running', true)
                ->with('item.program')->orderBy('id')->get();

            $boost = $running->filter(fn ($r): bool => $r->item?->program?->kind === ProgramKind::Booster)
                ->sum(fn ($r): float => (float) ($r->defence['boost'] ?? $r->item->program->produces['boost'] ?? 0));

            foreach ($running as $row) {
                match ($row->item?->program?->kind) {
                    ProgramKind::FortressDecryptor => $this->decrypt($fortress, $row, $minutes, $boost),
                    ProgramKind::FortressAssembler => $this->assemble($fortress, $row, $minutes, $boost),
                    ProgramKind::FortressNetworkTap, ProgramKind::FortressWiretap => $this->tap($fortress, $row, $minutes, $boost),
                    default => null,
                };
            }
        });
    }

    /** Minutes in one cycle of this Decryptor: its own, as its installer's stat resolves them, quickened by the Boosters. */
    public function cycleMinutes(InventoryItem $row, float $boostPercent = 0.0): float
    {
        return $this->minutes($row, 'decryptor', (float) ($row->item?->program?->produces['cycle_minutes'] ?? 60), $boostPercent);
    }

    /** Minutes one run of this Assembler takes for a Design: its craft time, less the item's reduction, then the stat and Boosters. */
    public function runMinutes(InventoryItem $row, Design $design, float $boostPercent = 0.0): float
    {
        $reduction = min(100.0, max(0.0, (float) ($row->item?->program?->produces['reduction'] ?? 0)));
        $base = max(1.0, (float) $design->craft_seconds / 60 * (1 - $reduction / 100));

        return $this->minutes($row, 'assembler', $base, $boostPercent);
    }

    private function minutes(InventoryItem $row, string $type, float $base, float $boostPercent): float
    {
        $installer = $row->fortress_installed_by === null ? null : Character::query()->find($row->fortress_installed_by);
        $minutes = $installer === null
            ? $base
            : (float) app(Resolver::class)->resolve("production_cycle_minutes.{$type}", $base, $installer->modifiers())->value;

        return max(1.0, $minutes / (1 + max(0.0, $boostPercent) / 100));
    }

    // ---- Taps --------------------------------------------------------------

    /**
     * A Network Tap or a Transactional Wiretap: each whole cycle it rolls its strength against the grade of every other node in
     * the square around the fortress (other fortresses are left alone). A Tap's success steals an Encrypted Data Dump into the
     * Decryptors' input area (lost if there is no room); a Wiretap's skims creds into the fortress wallet. A tap never idles.
     */
    private function tap(System $fortress, InventoryItem $row, float $time, float $boost): void
    {
        $kind = $row->item->program->kind;
        $produces = (array) $row->item->program->produces;
        $cycle = $this->minutes($row, $kind === ProgramKind::FortressWiretap ? 'wiretap' : 'tap', (float) ($produces['cycle_minutes'] ?? 60), $boost);
        $state = (array) $row->defence;
        $cycles = min(self::STEPS, (int) floor(($time + (float) ($state['carry_m'] ?? 0) + 1e-9) / $cycle));
        $state['carry_m'] = $cycles >= self::STEPS ? 0 : round(max(0.0, $time + (float) ($state['carry_m'] ?? 0) - $cycles * $cycle), 3);
        $row->forceFill(['defence' => $state])->save();

        if ($cycles < 1) {
            return;
        }

        $nodes = System::query()->within(new Position($fortress->x, $fortress->y, (int) ($fortress->grid_id ?? 1)), max(0, (int) ($produces['range'] ?? 0)))
            ->whereKeyNot($fortress->getKey())->where('type', '!=', SystemType::Fortress->value)->get();
        $strength = (float) ($produces['strength'] ?? 0);
        $roller = app(CheckRoller::class);
        $difficulty = (array) config('cyberspace.scan.difficulty', []);
        $installer = $row->fortress_installed_by === null ? null : Character::query()->find($row->fortress_installed_by);
        $yield = $installer === null ? 0.0 : (float) app(Resolver::class)->resolve('wiretap_yield', 0.0, $installer->modifiers())->value;
        $paid = 0;

        for ($i = 0; $i < $cycles; $i++) {
            foreach ($nodes as $node) {
                if (! $roller->roll($strength, (float) ($difficulty[$node->grade] ?? 0), 'tap')->passed) {
                    continue;
                }

                if ($kind === ProgramKind::FortressWiretap) {
                    [$low, $high] = (array) config("cyberspace.fortress.wiretap_pay.{$node->grade}", [0, 0]);
                    $paid += (int) round(($this->roll)((int) $low, (int) $high) * (1 + max(0.0, $yield) / 100));
                } else {
                    $this->stealDump($fortress, $node);
                }
            }
        }

        if ($paid > 0) {
            $fortress->forceFill(['wallet' => (int) $fortress->wallet + $paid])->save();
            FortressLog::write($fortress, null, 'produced', "wiretap {$paid}");
        }
    }

    private function stealDump(System $fortress, System $node): void
    {
        $made = NodeStocker::fromConfig($this->roll)->jobFile($node);
        $skill = $made['encryption_skill_id'] === null ? null : Skill::query()->find($made['encryption_skill_id']);
        $file = new ExtractedFile(
            label: (string) $made['label'], sizeQb: Qb::of($made['size_qb']), encryptionSkillId: $made['encryption_skill_id'],
            valueSealed: (int) $made['value_sealed'], valueBroken: (int) $made['value_broken'], grade: (int) $node->grade,
            origin: FileOrigin::fromSystem($node, $skill),
        );

        $free = (new Leeches)->totals($fortress)['storage'] - (new FortressHost)->used($fortress)['storage'];

        if (Qb::tenths($file->sizeQb) > Qb::tenths($free)) {
            return; // no room: the dump is lost
        }

        SystemContent::query()->create([
            'system_id' => $fortress->getKey(), 'kind' => ContentKind::Data->value, 'label' => $file->label, 'size_qb' => $file->sizeQb,
            'encryption_skill_id' => $file->encryptionSkillId, 'value_sealed' => $file->valueSealed, 'value_broken' => $file->valueBroken,
            'area_id' => $fortress->decryptor_in_area_id, 'meta' => ['extracted_file' => $file->toArray(), 'slug' => ExtractedFile::SEALED_SLUG],
        ]);
    }

    // ---- Decryptors --------------------------------------------------------

    private function decrypt(System $fortress, InventoryItem $row, float $time, float $boost): void
    {
        $state = (array) $row->defence;
        $cycle = $this->cycleMinutes($row, $boost);

        for ($step = 0; $step < self::STEPS; $step++) {
            // A finished component that had nowhere to go is delivered first.
            if (isset($state['ready'])) {
                if (! $this->putComponent($fortress, $state['ready'])) {
                    break;
                }

                unset($state['ready']);
            }

            if (! isset($state['job'])) {
                $file = $this->takeDump($fortress);

                if ($file === null) {
                    $state['carry_m'] = 0;

                    break;
                }

                $state['job'] = $file->toArray();
                $state['carry_m'] = 0;
            }

            $need = $cycle - (float) ($state['carry_m'] ?? 0);

            if ($time + 1e-9 < $need) {
                $state['carry_m'] = round((float) ($state['carry_m'] ?? 0) + $time, 3);

                break;
            }

            $time -= $need;
            $state['carry_m'] = 0;
            $file = ExtractedFile::fromArray($state['job']);
            unset($state['job']);
            $item = $file === null ? null : $this->decryption()->outcomeFor($file);

            if ($item === null || $file === null) {
                continue;
            }

            $state['ready'] = ['item_id' => $item->getKey(), 'file' => $file->toArray()];
        }

        $row->forceFill(['defence' => $state === [] ? null : $state])->save();
    }

    /** The oldest sealed Dump in the Decryptors' input area, taken out of storage (the Decryptor holds it while it works). */
    private function takeDump(System $fortress): ?ExtractedFile
    {
        $rows = $this->inArea($fortress, 'decryptor')->where('kind', ContentKind::Data->value)->orderBy('id')->get();

        foreach ($rows as $content) {
            $meta = (array) $content->meta;

            // A file already broken open is not a Dump; a legacy row (no meta) is a sealed one of unknown grade.
            if (($meta['slug'] ?? ExtractedFile::SEALED_SLUG) !== ExtractedFile::SEALED_SLUG) {
                continue;
            }

            $file = ExtractedFile::fromArray($meta['extracted_file'] ?? null) ?? new ExtractedFile(
                label: (string) $content->label, sizeQb: Qb::of($content->size_qb), encryptionSkillId: $content->encryption_skill_id,
                valueSealed: (int) $content->value_sealed, valueBroken: (int) $content->value_broken,
            );
            $content->delete();

            return $file;
        }

        return null;
    }

    /** @param  array{item_id: int, file: array<string, mixed>}  $ready */
    private function putComponent(System $fortress, array $ready): bool
    {
        $item = Item::query()->find($ready['item_id']);

        if ($item === null) {
            return true; // the component was retired: the job is simply done
        }

        if (! $this->fits($fortress, $item)) {
            return false;
        }

        SystemContent::query()->create([
            'system_id' => $fortress->getKey(), 'kind' => ContentKind::Item->value, 'item_id' => $item->getKey(), 'quantity' => 1,
            'area_id' => $fortress->decryptor_out_area_id, 'meta' => ['extracted_file' => $ready['file']],
        ]);
        FortressLog::write($fortress, null, 'produced', $item->displayName());

        return true;
    }

    // ---- Assemblers --------------------------------------------------------

    private function assemble(System $fortress, InventoryItem $row, float $time, float $boost): void
    {
        $job = FortressAssembly::query()->where('inventory_item_id', $row->getKey())->first();

        if ($job === null || $job->design_item_id === null) {
            return;
        }

        $design = Design::query()->where('item_id', $job->design_item_id)->with('components')->first();

        if ($design === null) {
            return;
        }

        for ($step = 0; $step < self::STEPS; $step++) {
            if (! $job->started) {
                if ($job->runs > 0 && $job->runs_done >= $job->runs) {
                    break;
                }

                // Not enough components or Copies: it waits, and banks no time.
                if (! $this->startRun($fortress, $design)) {
                    break;
                }

                $job->started = true;
                $job->work_minutes = round($this->runMinutes($row, $design, $boost), 2);
                $job->progress_minutes = 0;
            }

            $need = (float) $job->work_minutes - (float) $job->progress_minutes;

            if ($time + 1e-9 < $need) {
                $job->progress_minutes = round((float) $job->progress_minutes + $time, 2);

                break;
            }

            $time -= max(0.0, $need);
            $job->progress_minutes = $job->work_minutes;

            // No room: the product waits, finished, and the Assembler with it.
            if (! $this->putProduct($fortress, $row, $design)) {
                break;
            }

            $job->started = false;
            $job->progress_minutes = 0;
            $job->runs_done++;
        }

        $job->save();
    }

    /** Spend the Design's components (and a Copy) from the input area, or report there are not enough. Only components that match a line's filters count, the lowest grade first. */
    private function startRun(System $fortress, Design $design): bool
    {
        $content = fn (int $itemId) => $this->inArea($fortress, 'assembler')->where('kind', ContentKind::Item->value)->where('item_id', $itemId)->orderBy('id')->get();
        $crafting = Crafting::make();

        // The Design itself has to be in the input area; a Copy is spent by the run, an Original is not.
        if ($content((int) $design->item_id)->sum('quantity') < 1) {
            return false;
        }

        $spend = [];

        foreach ($design->components as $line) {
            $matching = $content((int) $line->component_item_id)
                ->filter(fn (SystemContent $c): bool => $crafting->accepts($line, ExtractedFile::fromArray(((array) $c->meta)['extracted_file'] ?? null)))
                ->sortBy(fn (SystemContent $c): array => [ExtractedFile::fromArray(((array) $c->meta)['extracted_file'] ?? null)?->grade ?? 0, $c->getKey()]);

            if ($matching->sum('quantity') < (int) $line->quantity) {
                return false;
            }

            $spend[] = [$matching, (int) $line->quantity];
        }

        foreach ($spend as [$rows, $left]) {
            foreach ($rows as $row) {
                $take = min($left, (int) $row->quantity);
                $take >= (int) $row->quantity ? $row->delete() : $row->forceFill(['quantity' => $row->quantity - $take])->save();
                $left -= $take;

                if ($left <= 0) {
                    break;
                }
            }
        }

        if ($design->single_use) {
            $copy = $content((int) $design->item_id)->first();

            if ($copy !== null) {
                (int) $copy->quantity > 1 ? $copy->forceFill(['quantity' => $copy->quantity - 1])->save() : $copy->delete();
            }
        }

        return true;
    }

    /** Put the made program in the output area, or say there is no room. */
    private function putProduct(System $fortress, InventoryItem $producer, Design $design): bool
    {
        $item = $design->product;

        if ($item === null) {
            return true; // nothing to make: the run is simply done
        }

        if (! $this->fits($fortress, $item)) {
            return false;
        }

        $meta = null;

        if ($item->program !== null && in_array($item->program->kind, [ProgramKind::FortressIce, ProgramKind::FortressIds, ProgramKind::Cloak, ProgramKind::Booster], true)) {
            $installer = $producer->fortress_installed_by === null ? null : Character::query()->find($producer->fortress_installed_by);
            $meta = ['defence' => FortressDefence::numbersFor($installer, $item)];
        }

        SystemContent::query()->create([
            'system_id' => $fortress->getKey(), 'kind' => ContentKind::Item->value, 'item_id' => $item->getKey(), 'quantity' => 1,
            'area_id' => $fortress->assembler_out_area_id, 'deposited_by' => $producer->fortress_installed_by, 'meta' => $meta,
        ]);
        FortressLog::write($fortress, null, 'produced', $item->displayName());

        return true;
    }

    // ---- shared ------------------------------------------------------------

    /** The fortress contents in a type's input area (NULL being the owner's storage). @return Builder<SystemContent> */
    private function inArea(System $fortress, string $type): Builder
    {
        $area = $fortress->{"{$type}_in_area_id"};
        $query = SystemContent::query()->where('system_id', $fortress->getKey());

        return $area === null ? $query->whereNull('area_id') : $query->where('area_id', $area);
    }

    private function fits(System $fortress, Item $item): bool
    {
        $free = (new Leeches)->totals($fortress)['storage'] - (new FortressHost)->used($fortress)['storage'];

        return Qb::tenths((float) Storage::sizeOf($item)) <= Qb::tenths($free);
    }

    private function decryption(): DecryptionQueue
    {
        return new DecryptionQueue(app(CheckRoller::class), new Notifier, $this->roll);
    }
}
