<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Cyberspace\Party\PartyShare;
use App\Decks\DeckTotals;
use App\Decks\Storage;
use App\Models\Character;
use App\Models\Run;
use App\Models\System;
use App\Models\SystemContent;
use App\Stats\Resolver;
use App\Support\Qb;
use App\Support\Text;
use Illuminate\Support\Collection;

/**
 * Raiding a Data Fortress's vault once the core is touched: files are pulled AT RANDOM, by themselves, a tick every five
 * seconds, until the raider's storage is full or nothing is left. Each tick moves the deck's bandwidth of the current
 * file. Lazy like everything else: whoever looks (the run screen) catches up the ticks that have passed.
 */
final class FortressRaid
{
    public const TICK_SECONDS = 5;

    /** Never catch up more than an hour of ticks in one look. */
    private const MAX_TICKS = 720;

    /** @param  \Closure(int, int): int  $roll */
    public function __construct(private readonly \Closure $roll) {}

    public static function make(): self
    {
        return new self(static fn (int $min, int $max): int => random_int($min, $max));
    }

    /**
     * Start pulling. Returns the log lines and whether anything is there to pull.
     *
     * @return list<array{text: string, tone: string}>
     */
    public function begin(Run $run, System $fortress, ?Character $raider = null): array
    {
        if ($run->pull_at !== null) {
            return [['text' => Text::get('cyberspace.run.log.raid_already'), 'tone' => '']];
        }

        if (Fortresses::isFrozen($fortress)) {
            return [['text' => Text::get('cyberspace.run.log.kernel_frozen'), 'tone' => 'warn']];
        }

        // Raid the wallet: a share of what the fortress holds, as the core is touched.
        $take = $run->extracted ? 0 : (int) floor((int) $fortress->wallet * (int) config('cyberspace.fortress.wallet_raid_percent', 25) / 100);

        if ($take > 0) {
            $fortress->forceFill(['wallet' => (int) $fortress->wallet - $take])->save();
            if ($raider !== null) {
                (new Extractor)->payCreds($raider, $take);
            }
            FortressLog::write($fortress, $raider, 'raided', "wallet {$take}");
        }

        if ($this->raidable($fortress)->isEmpty()) {
            return [['text' => Text::get('cyberspace.run.log.raid_empty'), 'tone' => 'warn']];
        }

        $run->forceFill(['pull_at' => now(), 'pull_content_id' => null, 'extracting_qb' => null]);

        // The core is touched. With ICE up, the second alarm rang when it fell; with none to fall it rings now.
        if ($fortress->fortress_ice === null) {
            Fortresses::alarm($fortress, 'fortress_core');
        }

        return [['text' => Text::get('cyberspace.run.log.raid_begins'), 'tone' => 'ok']];
    }

    /**
     * Process every tick that has come due.
     *
     * @return array{lines: list<array{text: string, tone: string}>, took: bool}
     */
    public function catchUp(Character $character, Run $run, ?Resolver $resolver = null): array
    {
        $lines = [];
        $took = false;

        if ($run->pull_at === null || ! $run->isLive()) {
            return ['lines' => $lines, 'took' => $took];
        }

        $fortress = System::query()->where('address', $run->address)->first();
        $bandwidth = $this->bandwidth($character, $resolver ?? app(Resolver::class));
        $elapsed = (int) floor($run->pull_at->diffInSeconds(now(), false));
        $due = min(self::MAX_TICKS, intdiv(max(0, $elapsed), self::TICK_SECONDS));

        for ($i = 0; $i < $due && $run->pull_at !== null; $i++) {
            $run->pull_at = $run->pull_at->copy()->addSeconds(self::TICK_SECONDS);

            if ($fortress === null) {
                $run->pull_at = null;
                $lines[] = ['text' => Text::get('cyberspace.run.log.transfer_gone'), 'tone' => ''];
                break;
            }

            $content = $run->pull_content_id === null
                ? $this->pick($fortress)
                : SystemContent::query()->find($run->pull_content_id);

            if ($content === null) {
                $run->forceFill(['pull_at' => null, 'pull_content_id' => null, 'extracting_qb' => null]);
                $lines[] = ['text' => Text::get('cyberspace.run.log.raid_done'), 'tone' => 'ok'];
                break;
            }

            if ($run->pull_content_id === null) {
                $run->pull_content_id = $content->getKey();
                $run->extracting_qb = $this->sizeOf($content);
            }

            $run->extracting_qb = Qb::of((float) $run->extracting_qb - $bandwidth);

            if ($run->extracting_qb > 0) {
                continue;
            }

            $recipient = $this->recipient($character, $content);

            if ($recipient === null) {
                $run->forceFill(['pull_at' => null, 'pull_content_id' => null, 'extracting_qb' => null]);
                $lines[] = ['text' => Text::get('cyberspace.run.log.no_room'), 'tone' => 'warn'];
                break;
            }

            $name = $this->handOver($recipient, $content);
            PartyShare::for($character)?->record($recipient->getKey(), 'file', (string) $name);
            $content->delete();
            $run->forceFill(['pull_content_id' => null, 'extracting_qb' => null]);
            $took = true;
            $lines[] = ['text' => Text::get('cyberspace.run.log.raid_took', ['file' => (string) $name]), 'tone' => 'ok'];
        }

        return ['lines' => $lines, 'took' => $took];
    }

    /**
     * The ids of what a raid may take: never what is locked in (a Subnet Certificate).
     *
     * @return Collection<int, int>
     */
    private function raidable(System $fortress): Collection
    {
        return SystemContent::query()->where('system_id', $fortress->getKey())->whereIn('kind', [ContentKind::Data->value, ContentKind::Item->value])->get()
            ->reject(fn (SystemContent $c): bool => ($c->meta['locked'] ?? false) === true)->pluck('id')->values();
    }

    private function pick(System $fortress): ?SystemContent
    {
        $ids = $this->raidable($fortress);

        return $ids->isEmpty() ? null : SystemContent::query()->find($ids[($this->roll)(0, $ids->count() - 1)]);
    }

    /** Who gets this file: by the party's extraction mode, or the raider alone. Null when it fits nobody who should have it. */
    private function recipient(Character $raider, SystemContent $content): ?Character
    {
        $size = $this->sizeOf($content);
        $share = PartyShare::for($raider);

        if ($share !== null) {
            $n = $share->runners->count();
            $mode = $share->extraction();
            $draw = ($this->roll)(1, 1_000_000) / 1_000_000;
            $acc = 0.0;

            foreach ($share->runners->values() as $i => $runner) {
                $acc += $i === 0 ? $mode->ownerShare($n) : $mode->memberShare($n);

                if ($draw <= $acc + 1e-12) {
                    if (Storage::fits($runner, $size)) {
                        return $runner;
                    }

                    break;
                }
            }
        }

        return Storage::fits($raider, $size) ? $raider : null;
    }

    private function bandwidth(Character $character, Resolver $resolver): int
    {
        $deck = $character->equippedDeck;

        return $deck === null ? 1 : max(1, DeckTotals::forCharacter($character, $deck, $resolver)->bandwidth);
    }

    /** QB a vault row weighs: a file's own size, or an item's size times its quantity. */
    private function sizeOf(SystemContent $content): float
    {
        return $content->kind === ContentKind::Item
            ? Qb::of(Storage::sizeOf($content->item) * (int) $content->quantity)
            : (float) $content->size_qb;
    }

    private function handOver(Character $to, SystemContent $content): ?string
    {
        if ($content->kind !== ContentKind::Item) {
            return (new Extractor)->deliver($to, $content);
        }

        $meta = (array) $content->meta;
        $row = $to->inventory()->create(array_filter([
            'item_id' => $content->item_id,
            'parent_id' => null,
            'quantity' => (int) $content->quantity,
            'extracted_file' => $meta['extracted_file'] ?? null,
            'defence' => $meta['defence'] ?? null,
        ], fn ($v, $k): bool => $v !== null || $k === 'parent_id', ARRAY_FILTER_USE_BOTH));

        return $row->load('item')->label();
    }
}
