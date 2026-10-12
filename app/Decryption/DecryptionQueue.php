<?php

declare(strict_types=1);

namespace App\Decryption;

use App\Characters\EncounterPause;
use App\Checks\CheckRoller;
use App\Cyberspace\ExtractedFile;
use App\Models\Character;
use App\Models\DecryptionEntry;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Program;
use App\Models\Skill;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Programs\ProgramKind;
use App\Stats\SkillStats;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Breaking stolen files, at leisure. The ONLY writer of `decryption_queue`, and the only thing that opens a file.
 *
 * ## The shape
 *
 * An ordered queue, as the training queue is, but worked by as many hands as there are Decryptors running. Each
 * running decryptor (an inventory row) works ONE file at a time and one file is worked by ONE decryptor, so two
 * Ghostkeys break two files at once. A free decryptor takes the first waiting file in the queue it can break
 * (`programs.decrypts`), and position is only that priority. A cycle (`decryption_cycle_seconds`) ends in ONE
 * `CheckRoller` roll of `check.<encryption slug>` against that encryption's difficulty (kind `decryption`, the die
 * injected), scaled by `decryption_chance.<slug>`. A pass opens the file; a fail costs the cycle and carries nothing,
 * so a bad matchup is slow rather than impossible. A file is never partly decrypted.
 *
 * ## It needs a Decryptor RUNNING on the equipped deck
 *
 * That one rule is the design's "standing claim on the deck": the program already spends compute and memory while it
 * runs, and its own modifiers reach the check (`RunningPrograms`), so a better decryptor is data on its row. It works
 * during a run too, as long as the deck has the compute and memory for it: nothing about a run stops a background
 * program.
 *
 * ## The invariant
 *
 * An entry's `cycle_started_at` is non-null exactly while its worker (`worker_id`) has run on it continuously since
 * then. Settling is LAZY and catches up (every elapsed whole cycle rolls, until one passes), and a sweep
 * (`decryption:settle`) keeps stored rows true between visits. Every path that stops a decryptor settles first and
 * then frees its file (`stopped()`, called from `ProgramRunner`), so a cycle is never credited across a gap. A
 * decryptor whose file opens takes the next waiting one from the moment the last opened.
 *
 * ## Which means the clock is only as honest as the last settle
 *
 * A decryptor started between sweeps picks up its first file at the next settle, not the moment it started: at most
 * a minute lost, in the player's disfavour.
 */
final class DecryptionQueue
{
    /**
     * @param  \Closure(int, int): int|null  $die  inclusive integer die for what a file becomes; null is `random_int`. The container
     *                                             binding `decryption.die` pins it in a test, the way `CheckRoller`'s die is pinned.
     */
    public function __construct(
        private readonly CheckRoller $roller,
        private readonly Notifier $notifier,
        private readonly ?\Closure $die = null,
    ) {}

    public static function make(): self
    {
        return new self(app(CheckRoller::class), new Notifier, app()->bound('decryption.die') ? app('decryption.die') : null);
    }

    // ---- what a screen asks ------------------------------------------------

    /** The file a row is, or null when it is not one. */
    public static function fileOf(InventoryItem $row): ?ExtractedFile
    {
        return $row->extractedFile();
    }

    public static function isOpen(InventoryItem $row): bool
    {
        return $row->item !== null && $row->extracted_file !== null && $row->item->slug !== ExtractedFile::SEALED_SLUG;
    }

    /** Seconds in a cycle: the resolved stat, so skills and gear can shorten it. */
    public static function cycleSeconds(Character $character): int
    {
        return max(1, $character->stat('decryption_cycle_seconds')->toInt());
    }

    /**
     * What each cycle's chance is multiplied by: one plus the resolved `decryption_chance.<slug>` as a percentage. THE
     * one place it is worked out, so the odds on the screen and the roll cannot disagree.
     */
    public static function chanceMultiplier(Character $character, Skill $encryption): float
    {
        return max(0.0, 1 + $character->stat(SkillStats::CHANCE_PREFIX.$encryption->slug)->value / 100);
    }

    /** May this character attempt this encryption? The resolved level of its skill against the gate. */
    public static function mayAttempt(Character $character, Skill $encryption): bool
    {
        $needed = (int) Config::get("cyberspace.decryption.gate.{$encryption->slug}", 1);

        return $character->stat("skill.{$encryption->slug}")->value >= $needed;
    }

    /** The difficulty of breaking a file's encryption. */
    public static function difficultyOf(Skill $encryption): float
    {
        return (float) Config::get("cyberspace.decryption.difficulty.{$encryption->slug}", 0);
    }

    /**
     * The odds of one cycle, and what that means in minutes — the number a warning and the roll share.
     *
     * @return array{chance: float, seconds: float, skill: Skill}|null null when the file has no encryption
     */
    public function odds(Character $character, InventoryItem $row): ?array
    {
        $file = self::fileOf($row);
        $skill = $file?->encryptionSkillId === null ? null : Skill::query()->find($file->encryptionSkillId);

        if ($skill === null) {
            return null;
        }

        $chance = $this->roller->chance($this->reading($character, $skill), self::difficultyOf($skill), 'decryption', self::chanceMultiplier($character, $skill));

        return ['chance' => $chance, 'seconds' => self::cycleSeconds($character) / $chance, 'skill' => $skill];
    }

    /**
     * Is a Decryptor running on the deck being carried that can break this encryption (any, when none is named)?
     * A decryptor limited to some encryptions (`programs.decrypts`) does not count for the others.
     */
    public static function decryptorRunning(Character $character, ?int $exceptRowId = null, ?Skill $encryption = null): bool
    {
        if ($character->equipped_deck_id === null) {
            return false;
        }

        return InventoryItem::query()
            ->with('item.program')
            ->where('character_id', $character->getKey())
            ->where('running_on_deck_id', $character->equipped_deck_id)
            ->when($exceptRowId !== null, static fn ($q) => $q->whereKeyNot($exceptRowId))
            ->whereHas('item.program', static fn ($q) => $q->where('kind', ProgramKind::Decryptor->value))
            ->get()
            ->contains(static fn (InventoryItem $row): bool => $encryption === null || $row->item->program->canBreak($encryption->slug));
    }

    /** Is anything running that can work on the queue? With no queue, any decryptor will do. */
    public static function canWork(Character $character): bool
    {
        $files = DecryptionEntry::query()->with('file')->where('character_id', $character->getKey())->orderBy('position')->get();

        if ($files->isEmpty()) {
            return self::decryptorRunning($character);
        }

        foreach ($files as $entry) {
            $file = $entry->file === null ? null : self::fileOf($entry->file);
            $skill = $file?->encryptionSkillId === null ? null : Skill::query()->find($file->encryptionSkillId);

            if (self::decryptorRunning($character, null, $skill)) {
                return true;
            }
        }

        return false;
    }

    // ---- what a player does ------------------------------------------------

    public function enqueue(Character $character, InventoryItem $row): DecryptionEntry|DecryptionRefusal
    {
        return DB::transaction(function () use ($character, $row): DecryptionEntry|DecryptionRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $this->settleLocked($locked);

            $row = InventoryItem::query()->with('item')->where('character_id', $locked->getKey())->loose()->whereKey($row->getKey())->first();
            $file = $row === null ? null : self::fileOf($row);

            if ($row === null || $file === null) {
                return DecryptionRefusal::NotAFile;
            }

            if (self::isOpen($row)) {
                return DecryptionRefusal::AlreadyOpen;
            }

            $skill = $file->encryptionSkillId === null ? null : Skill::query()->find($file->encryptionSkillId);

            if ($skill === null) {
                return DecryptionRefusal::Unbreakable;
            }

            if (! self::mayAttempt($locked, $skill)) {
                return DecryptionRefusal::NoSkill;
            }

            if (DecryptionEntry::query()->where('inventory_item_id', $row->getKey())->exists()) {
                return DecryptionRefusal::AlreadyQueued;
            }

            $count = DecryptionEntry::query()->where('character_id', $locked->getKey())->count();

            if ($count >= (int) Config::get('cyberspace.decryption.queue_max', 10)) {
                return DecryptionRefusal::QueueFull;
            }

            $entry = DecryptionEntry::query()->create([
                'character_id' => $locked->getKey(),
                'inventory_item_id' => $row->getKey(),
                'position' => $count + 1,
            ]);

            // A free decryptor takes it at once.
            $this->settleLocked($locked);

            return $entry->fresh() ?? $entry;
        });
    }

    /**
     * Take a file out of the queue. The one being worked on cannot be called off while jacked in: you may always add
     * work and never strand yourself halfway out of a state.
     */
    public function remove(Character $character, DecryptionEntry $entry): true|DecryptionRefusal
    {
        return DB::transaction(function () use ($character, $entry): true|DecryptionRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $this->settleLocked($locked);

            $entry = DecryptionEntry::query()->where('character_id', $locked->getKey())->whereKey($entry->getKey())->first();

            if ($entry === null) {
                return DecryptionRefusal::NotQueued;
            }

            // A file being worked on cannot be called off from in there: you may always add work and never strand yourself.
            if ($entry->worker_id !== null && $locked->isJackedIn()) {
                return DecryptionRefusal::LockedIn;
            }

            $entry->delete();
            $this->renumber($locked);

            return true;
        });
    }

    /**
     * Put the queue in a new order: every entry's id, once, in the order wanted. Position is only priority, so files
     * already being worked on keep their decryptor and their cycle; the order decides who a free decryptor takes next.
     *
     * @param  list<int>  $ids
     */
    public function reorder(Character $character, array $ids): true|DecryptionRefusal
    {
        return DB::transaction(function () use ($character, $ids): true|DecryptionRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $this->settleLocked($locked);

            $entries = DecryptionEntry::query()->where('character_id', $locked->getKey())->lockForUpdate()->get()->keyBy('id');

            $wanted = array_map('intval', $ids);
            $sorted = $wanted;
            sort($sorted);
            $held = $entries->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all();

            if ($sorted !== $held) {
                return DecryptionRefusal::BadOrder;
            }

            // (character_id, position) is unique: park every row out of the way first, then settle each into its place.
            foreach ($entries as $entry) {
                $entry->forceFill(['position' => $entry->position + 100])->save();
            }

            foreach ($wanted as $index => $id) {
                $entries[$id]->forceFill(['position' => $index + 1])->save();
            }

            return true;
        });
    }

    // ---- the clock ---------------------------------------------------------

    /** Bring a character's queue up to date. @return int files opened */
    public function settle(Character $character, ?CarbonInterface $now = null): int
    {
        return DB::transaction(function () use ($character, $now): int {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();

            return $this->settleLocked($locked, $now);
        });
    }

    /**
     * A decryptor is about to stop. Settle up to this moment while it still counts as running, then free the file it
     * was working and close that cycle. Called by `ProgramRunner`, inside its own transaction.
     */
    public function stopped(Character $character, int $stoppingRowId): void
    {
        $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
        $this->settleLocked($locked);

        DecryptionEntry::query()
            ->where('character_id', $locked->getKey())
            ->where('worker_id', $stoppingRowId)
            ->update(['worker_id' => null, 'cycle_started_at' => null]);
    }

    /**
     * The running decryptors on the deck being carried, by inventory row id.
     *
     * @return array<int, Program>
     */
    private function workers(Character $character): array
    {
        if ($character->equipped_deck_id === null) {
            return [];
        }

        $workers = [];

        foreach (InventoryItem::query()
            ->with('item.program')
            ->where('character_id', $character->getKey())
            ->where('running_on_deck_id', $character->equipped_deck_id)
            ->whereHas('item.program', static fn ($q) => $q->where('kind', ProgramKind::Decryptor->value))
            ->orderBy('id')
            ->get() as $row) {
            $workers[$row->getKey()] = $row->item->program;
        }

        return $workers;
    }

    /** @return int files opened */
    private function settleLocked(Character $character, ?CarbonInterface $now = null): int
    {
        // The clock stops while an encounter is open on the grid; the cycle's start moves on when it closes.
        if (EncounterPause::isHeld($character)) {
            return 0;
        }

        $now = CarbonImmutable::instance($now ?? now());
        $cycle = self::cycleSeconds($character);
        $workers = $this->workers($character);
        $opened = 0;
        // Where the last file opened: a decryptor freed by it takes the next file from that moment, not from now.
        $carry = null;

        while (true) {
            $entries = DecryptionEntry::query()->where('character_id', $character->getKey())->orderBy('position')->lockForUpdate()->get();

            /** @var array<int, array{row: InventoryItem, file: ExtractedFile, skill: Skill}> $valid */
            $valid = [];
            $dropped = false;

            foreach ($entries as $entry) {
                $row = InventoryItem::query()->with('item')->where('character_id', $character->getKey())->loose()->whereKey($entry->inventory_item_id)->first();
                $file = $row === null ? null : self::fileOf($row);
                $skill = $file?->encryptionSkillId === null ? null : Skill::query()->find($file->encryptionSkillId);

                // Sold, taken into a container, already open, or the skill gone: skipped rather than allowed to break the queue.
                if ($row === null || self::isOpen($row) || $skill === null || ! self::mayAttempt($character, $skill)) {
                    $entry->delete();
                    $dropped = true;

                    continue;
                }

                $valid[$entry->getKey()] = ['row' => $row, 'file' => $file, 'skill' => $skill];
            }

            if ($dropped) {
                $this->renumber($character);

                continue;
            }

            // A worker that stopped (or can no longer break its file) frees the entry and loses the cycle.
            foreach ($entries as $entry) {
                $held = $entry->worker_id !== null
                    && isset($workers[$entry->worker_id])
                    && $workers[$entry->worker_id]->canBreak($valid[$entry->getKey()]['skill']->slug);

                if (! $held && ($entry->worker_id !== null || $entry->cycle_started_at !== null)) {
                    $entry->forceFill(['worker_id' => null, 'cycle_started_at' => null])->save();
                }
            }

            // Each free decryptor takes the first waiting file it can break.
            $busy = $entries->pluck('worker_id')->filter()->all();

            foreach ($workers as $workerId => $program) {
                if (in_array($workerId, $busy, true)) {
                    continue;
                }

                $next = $entries->first(static fn (DecryptionEntry $e): bool => $e->worker_id === null && $program->canBreak($valid[$e->getKey()]['skill']->slug));

                if ($next !== null) {
                    $next->forceFill(['worker_id' => $workerId, 'cycle_started_at' => $carry ?? $now])->save();
                    $busy[] = $workerId;
                }
            }

            $changed = false;

            foreach ($entries as $entry) {
                if ($entry->worker_id === null || $entry->cycle_started_at === null) {
                    continue;
                }

                $cycles = intdiv(max(0, (int) $entry->cycle_started_at->diffInSeconds($now, false)), $cycle);

                if ($cycles < 1) {
                    continue;
                }

                ['row' => $row, 'file' => $file, 'skill' => $skill] = $valid[$entry->getKey()];
                $value = $this->reading($character, $skill);
                $difficulty = self::difficultyOf($skill);
                $multiplier = self::chanceMultiplier($character, $skill);

                for ($i = 1; $i <= $cycles; $i++) {
                    if ($this->roller->roll($value, $difficulty, 'decryption', $multiplier)->passed) {
                        $this->open($character, $row, $file);
                        $carry = $entry->cycle_started_at->addSeconds($i * $cycle);
                        $entry->delete();
                        $this->renumber($character);
                        $opened++;
                        $changed = true;

                        break 2;
                    }
                }

                $entry->forceFill(['cycle_started_at' => $entry->cycle_started_at->addSeconds($cycles * $cycle)])->save();
            }

            if (! $changed) {
                return $opened;
            }
        }
    }

    /**
     * What a file becomes: a weighted roll over the components that can come out of a system of its grade
     * (`cyberspace.decryption.components`, every column summing to 100). A file with no recorded grade is read as grade 1.
     * A component whose item has not been authored is skipped, and nothing at all falls back to the Memory Dump.
     */
    private function outcomeFor(ExtractedFile $file): ?Item
    {
        $grade = max(1, min(9, $file->grade ?? 1));
        $weights = [];

        foreach ((array) config('cyberspace.decryption.components', []) as $slug => $component) {
            $weight = (int) ($component['weights'][$grade] ?? 0);

            if ($weight > 0) {
                $weights[$slug] = $weight;
            }
        }

        $items = Item::query()->whereIn('slug', array_keys($weights))->get()->keyBy('slug');
        $weights = array_intersect_key($weights, $items->all());

        if ($weights !== []) {
            $pick = ($this->die ?? random_int(...))(1, array_sum($weights));

            foreach ($weights as $slug => $weight) {
                $pick -= $weight;

                if ($pick <= 0) {
                    return $items[$slug];
                }
            }
        }

        return Item::query()->where('slug', ExtractedFile::DECRYPTED_SLUG)->first();
    }

    private function open(Character $character, InventoryItem $row, ExtractedFile $file): void
    {
        $outcome = $this->outcomeFor($file);

        if ($outcome === null) {
            return;
        }

        // In place: the same row, so its size, values and grade carry over untouched.
        $row->forceFill(['item_id' => $outcome->getKey()])->save();

        $this->notifier->send($character, Category::Cyberspace, 'decrypted', [
            'label' => $file->label,
            'item' => $outcome->displayName(),
            'value' => number_format($file->valueBroken),
        ]);
    }

    /** Close the gap a departure left, ascending (position is unique, so each row moves into a slot already vacated). */
    private function renumber(Character $character): void
    {
        $position = 1;

        foreach (DecryptionEntry::query()->where('character_id', $character->getKey())->orderBy('position')->get() as $entry) {
            if ($entry->position !== $position) {
                $entry->forceFill(['position' => $position])->save();
            }

            $position++;
        }
    }

    private function reading(Character $character, Skill $skill): float
    {
        return $character->stat(SkillStats::checkId($skill->slug))->value;
    }
}
