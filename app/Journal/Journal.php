<?php

declare(strict_types=1);

namespace App\Journal;

use App\Models\Character;
use App\Models\JournalEntry;
use App\Models\JournalNote;
use App\Models\Mission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a character's journal.
 *
 * A note is a heading with a slug; a line is a row under one. Both are written by an encounter's outcomes and are
 * IDEMPOTENT by slug: an outcome that applies twice (a screen reached again) adds nothing the second time, so a loop
 * cannot fill a journal. Completing sets a flag and deletes nothing.
 */
final class Journal
{
    /**
     * Create the note, a line under it, or both.
     *
     * The note is made only if it does not exist and a heading was given; a line goes under the note as it stands, so an
     * outcome may add a line to an existing note by naming its slug alone. A slug already in use is left as it is.
     *
     * @return array{note: bool, entry: bool} what was actually written
     */
    public function write(Character $character, string $noteSlug, ?string $heading, ?string $entrySlug, ?string $entryText): array
    {
        return DB::transaction(function () use ($character, $noteSlug, $heading, $entrySlug, $entryText): array {
            $note = JournalNote::query()->where('character_id', $character->getKey())->where('slug', $noteSlug)->first();
            $madeNote = false;

            if ($note === null && $heading !== null && $heading !== '') {
                $note = JournalNote::query()->create(['character_id' => $character->getKey(), 'slug' => $noteSlug, 'title' => $heading]);
                $madeNote = true;
            }

            $madeEntry = false;

            if ($note !== null && $entrySlug !== null && $entrySlug !== '' && $entryText !== null && $entryText !== ''
                && ! JournalEntry::query()->where('character_id', $character->getKey())->where('slug', $entrySlug)->exists()) {
                JournalEntry::query()->create([
                    'character_id' => $character->getKey(), 'note_id' => $note->getKey(), 'slug' => $entrySlug, 'body' => $entryText,
                ]);
                $madeEntry = true;
            }

            return ['note' => $madeNote, 'entry' => $madeEntry];
        });
    }

    /**
     * Mark a note or a line done, by slug. A note that is done moves to the archive; a line that is done is struck through.
     *
     * @return int how many rows were changed (0 when the slug names nothing, or it was already done)
     */
    public function complete(Character $character, string $slug): int
    {
        $changed = JournalNote::query()->where('character_id', $character->getKey())->where('slug', $slug)->where('completed', false)->update(['completed' => true]);

        return $changed + JournalEntry::query()->where('character_id', $character->getKey())->where('slug', $slug)->where('completed', false)->update(['completed' => true]);
    }

    /**
     * How many journal lines and accepted jobs are newer than the last time the screen was opened: the rail's badge.
     * A finished line or job is not news.
     */
    public function unseenCount(Character $character): int
    {
        $since = $character->tasks_seen_at;

        $lines = JournalEntry::query()
            ->where('character_id', $character->getKey())
            ->where('completed', false)
            ->when($since, fn ($q) => $q->where('created_at', '>', $since))
            ->count();

        $jobs = Mission::query()
            ->where('character_id', $character->getKey())
            ->accepted()
            ->when($since, fn ($q) => $q->where('accepted_at', '>', $since))
            ->count();

        return $lines + $jobs;
    }

    /** Everything is read: the screen has been opened. The only writer of `tasks_seen_at`. */
    public function markSeen(Character $character): void
    {
        $character->forceFill(['tasks_seen_at' => now()])->saveQuietly();
    }

    /**
     * What the screen lists: the notes not yet completed, newest first, each with its lines newest first.
     *
     * @return Collection<int, JournalNote>
     */
    public function visible(Character $character): Collection
    {
        return JournalNote::query()
            ->where('character_id', $character->getKey())
            ->where('completed', false)
            ->with('entries')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The finished notes, newest first, each with its lines: the "Archived entries" under the journal.
     *
     * @return Collection<int, JournalNote>
     */
    public function archived(Character $character): Collection
    {
        return JournalNote::query()
            ->where('character_id', $character->getKey())
            ->where('completed', true)
            ->with('entries')
            ->orderByDesc('id')
            ->get();
    }
}
