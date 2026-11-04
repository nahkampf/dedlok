<?php

declare(strict_types=1);

namespace App\Characters;

use App\Models\Character;
use App\Models\CharacterEncounter;
use App\Models\DecryptionEntry;
use App\Models\Dose;
use App\Models\InventoryItem;
use App\Models\SystemScan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * THE CLOCK STOPS while a character is jacked in and an encounter is open (docs/ENCOUNTERS_GRID.md), the way it does in a
 * fight, so an encounter cannot be waited in and a long one cannot cost somebody their scripts.
 *
 * ONE MECHANISM, three parts:
 *
 * - the sweeps SKIP a held character (`isHeld()`), and a clock that is READ rather than swept (a Script's end, a
 *   dose's lapse) is read as at the moment the encounter opened (`openedAt()`);
 * - clocks that are STORED as a moment (`expires_at`, `started_at`, `pass_ready_at`, a decryption cycle) are moved
 *   forward by the encounter's length when it closes (`release()`), so the pause is real afterwards too;
 * - crafting stores progress as credited seconds and needs neither: while held, no seconds are credited.
 *
 * THE WORLD DOES NOT PAUSE: a lockdown still ends and a machine still relocates. Only the player's own clocks do.
 * A City encounter pauses nothing (the player is not on the grid, and the City's own clocks wait for them already).
 */
final class EncounterPause
{
    /** When the encounter this character is in opened, if they are jacked in and in one. */
    public static function openedAt(Character $character): ?CarbonImmutable
    {
        if ($character->current_encounter_id === null || ! $character->isJackedIn()) {
            return null;
        }

        $at = CharacterEncounter::query()->whereKey($character->current_encounter_id)->value('started_at');

        return $at === null ? null : CarbonImmutable::parse($at);
    }

    /** Is this character's own clock stopped right now? Read fresh: a sweep holds a stale copy. */
    public static function isHeld(Character $character): bool
    {
        return Character::query()
            ->whereKey($character->getKey())
            ->whereNotNull('current_encounter_id')
            ->whereNotNull('grid_x')
            ->exists();
    }

    /**
     * Every held character and when their encounter opened.
     *
     * @return Collection<int, CarbonImmutable> character id => moment
     */
    public static function held(): Collection
    {
        return Character::query()
            ->join('character_encounters', 'character_encounters.id', '=', 'characters.current_encounter_id')
            ->whereNotNull('characters.grid_x')
            ->pluck('character_encounters.started_at', 'characters.id')
            ->map(static fn ($at): CarbonImmutable => CarbonImmutable::parse($at));
    }

    /**
     * An encounter is closing: move every stored clock of this character forward by how long it was open.
     *
     * Called by `EncounterRunner` on the locked character, in its own transaction, before the pointer is cleared.
     */
    public static function release(Character $locked, CarbonImmutable $openedAt): void
    {
        $took = max(0, CarbonImmutable::now()->getTimestamp() - $openedAt->getTimestamp());

        if ($took === 0 || ! $locked->isJackedIn()) {
            return;
        }

        $id = $locked->getKey();

        // An ordinary dose still working when it opened keeps the time it had left.
        Dose::query()->where('character_id', $id)->whereNull('turns_left')->where('expires_at', '>', $openedAt)
            ->get()->each(static fn (Dose $dose) => $dose->forceFill(['expires_at' => $dose->expires_at->addSeconds($took)])->save());

        // A running Script's life starts later by the same amount.
        InventoryItem::query()->where('character_id', $id)->whereNotNull('running_on_deck_id')->whereNotNull('started_at')
            ->whereHas('item.program', static fn ($q) => $q->whereNotNull('lifetime_seconds'))
            ->get()->each(static fn (InventoryItem $row) => $row->forceFill(['started_at' => CarbonImmutable::parse($row->started_at)->addSeconds($took)])->save());

        // A read that had not matured when it opened matures later.
        SystemScan::query()->where('character_id', $id)->whereNotNull('pass_ready_at')->where('pass_ready_at', '>', $openedAt)
            ->get()->each(static fn (SystemScan $scan) => $scan->forceFill([
                'pass_started_at' => $scan->pass_started_at?->addSeconds($took),
                'pass_ready_at' => $scan->pass_ready_at->addSeconds($took),
            ])->save());

        // A decryption cycle that was running starts its clock again from where it was.
        DecryptionEntry::query()->where('character_id', $id)->whereNotNull('cycle_started_at')
            ->get()->each(static fn (DecryptionEntry $entry) => $entry->forceFill([
                'cycle_started_at' => CarbonImmutable::parse($entry->cycle_started_at)->addSeconds($took),
            ])->save());
    }
}
