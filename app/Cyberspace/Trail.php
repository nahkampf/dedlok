<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Decks\DeckTotals;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Run;
use App\Stats\Resolver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A log of what was true when somebody left cyberspace, and why they did.
 *
 * Written to find out WHY a character was jacked out: every way out goes through `Navigator::jackOut()`, so one
 * line there with the app's own callers says which path it was, and a snapshot of the deck, the stash and the last
 * run says what was true at that moment. `RunStarter::end()` writes the same snapshot before and after it stops what
 * the run started, because the deck's answer can change across exactly that.
 *
 * IT ONLY READS. It never writes a row and never throws: a failure inside it is swallowed, since a log must not be
 * able to jack somebody out or break a turn.
 */
final class Trail
{
    public static function enabled(): bool
    {
        return (bool) Config::get('cyberspace.trail', false);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function note(string $event, Character $character, array $extra = []): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            Log::channel('cyberspace')->info($event, [
                'character' => ['id' => $character->getKey(), 'handle' => $character->handle],
                'at' => $character->isJackedIn() ? "{$character->grid_x},{$character->grid_y}" : null,
                'run_id' => $character->current_run_id,
                'hunt_id' => $character->current_hunt_id,
                'encounter_id' => $character->current_encounter_id,
                'fight_id' => $character->current_fight_id,
                'si' => $character->current_synaptic_integrity,
                'last_run' => self::lastRun($character),
                'deck' => self::deck($character),
                'running' => self::running($character),
                'callers' => self::callers(),
            ] + $extra);
        } catch (Throwable) {
            // A log must not be the thing that breaks the turn.
        }
    }

    /** @return array<string, mixed>|null */
    private static function lastRun(Character $character): ?array
    {
        $run = Run::query()->where('character_id', $character->getKey())->latest('id')->first();

        return $run === null ? null : [
            'id' => $run->id,
            'address' => $run->address,
            'purpose' => $run->purpose->value,
            'ending' => $run->ending,
            'ended_at' => $run->ended_at?->toIso8601String(),
            'detected' => (bool) $run->detected,
            'trace' => (int) $run->trace,
            'extracted' => $run->extracted,
            'turn' => (int) $run->turn,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function deck(Character $character): ?array
    {
        $deck = $character->equippedDeck;

        if ($deck === null) {
            return null;
        }

        $totals = DeckTotals::forCharacter($character, $deck, app(Resolver::class));

        return [
            'ready' => $totals->isReady(),
            'unmet' => array_map(static fn ($r): string => $r->value, $totals->unmet()),
            'qflops' => $totals->qflops,
            'quantabits' => $totals->quantabits,
            'draw' => $totals->draw,
            'supply' => $totals->supply,
            'stored' => $totals->stored,
            'storage' => $totals->storage,
        ];
    }

    /** @return list<string> */
    private static function running(Character $character): array
    {
        return InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->whereNotNull('running_on_deck_id')
            ->with('item:id,slug')
            ->get()
            ->map(static fn (InventoryItem $row): string => (string) $row->item?->slug)
            ->all();
    }

    /**
     * The app's own frames that led here, nearest first, so "who jacked this person out" is one line.
     *
     * @return list<string>
     */
    private static function callers(): array
    {
        $frames = [];

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $file = $frame['file'] ?? '';

            if (! str_contains($file, '/app/') || str_ends_with($file, 'Trail.php')) {
                continue;
            }

            $frames[] = basename($file).':'.($frame['line'] ?? 0).' '.($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
        }

        return array_slice($frames, 0, 8);
    }
}
