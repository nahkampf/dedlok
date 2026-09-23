<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\ExtractedFile;
use App\Support\Text;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What a character is carrying: one row per (character, item), with a quantity.
 *
 * Which inventory a row appears in is not stored here. It comes from the item
 * definition's `container`, because it is a fact about the item and letting a
 * row disagree with its definition would make the two inventories a lie.
 *
 * WHERE a row sits is stored here, and is a different question: `parent_id`
 * names the row this one is inside, and is null for anything loose. A memory
 * card is a physical row holding data rows — the item says what kind of thing
 * it is, the row says where it ended up.
 */
#[Fillable(['character_id', 'item_id', 'parent_id', 'quantity', 'running_on_deck_id', 'started_at', 'installed_at', 'extracted_file'])]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'installed_at' => 'immutable_datetime',
            'quantity' => 'integer',
            'started_at' => 'immutable_datetime',
            'extracted_file' => 'array',
        ];
    }

    /**
     * What this row USED TO BE, before it left a machine as a generic dump.
     *
     * Non-null only on a row of the "Encrypted Data Dump" item —
     * App\Cyberspace\Extractor is the only writer, and
     * App\Cyberspace\ExtractedFile is the shape.
     */
    public function extractedFile(): ?ExtractedFile
    {
        return ExtractedFile::fromArray($this->extracted_file);
    }

    /**
     * The spec line a real file earns, in the same voice a module's numbers or
     * a program's costs already speak — see `Item::hardwareSummary()` and
     * `Item::programSummary()`. Lives on the ROW rather than the item, because
     * every "Encrypted Data Dump" is the same catalogue entry and a different
     * file.
     */
    public function extractedFileSummary(): ?string
    {
        $file = $this->extractedFile();

        if ($file === null) {
            return null;
        }

        $parts = [
            Text::get('inventory.files.size', ['qb' => $file->sizeQb]),
            $file->encryptionSkillId !== null
                ? Text::get('inventory.files.encrypted_with', ['skill' => $this->encryptionSkillName($file)])
                : Text::get('inventory.files.unencrypted'),
            Text::get('inventory.files.sealed_value', ['value' => $file->valueSealed]),
        ];

        return implode(Text::get('items.summary.separator'), $parts);
    }

    private function encryptionSkillName(ExtractedFile $file): string
    {
        return Skill::query()->find($file->encryptionSkillId)?->title
            ?? Text::get('inventory.files.unknown_encryption');
    }

    /**
     * Keep the stacking key in step with the row.
     *
     * Derived on every save rather than assigned by callers, because a caller
     * that forgets it would silently create a second pile of the same item.
     * Unstackable rows get null, which a unique index does not police — that
     * is how two memory cards stay two rows.
     */
    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            $row->stack_key = $row->stackKey();
        });
    }

    private function stackKey(): ?string
    {
        $item = $this->relationLoaded('item') ? $this->item : Item::find($this->item_id);

        if ($item === null || ! $item->stackable) {
            return null;
        }

        /*
         * A RUNNING row is a thing rather than a quantity, so it never joins a
         * pile — two running icebreakers are two processes, and a row holding
         * two of them cannot be pointed at or stopped independently.
         *
         * This is why running lives on the row and not in a join table: this
         * key is derived from the row alone on every save, so a flag anywhere
         * else would be invisible here and the two copies would silently merge
         * into one. Same failure the cyberdeck stack_key rewrite existed to
         * kill, one subsystem later.
         */
        if ($this->running_on_deck_id !== null) {
            return null;
        }

        // Which pile this belongs to: same item, same owner, same container.
        // Two stacks of the same item in two different cards are two piles.
        return "{$this->character_id}:{$this->item_id}:".($this->parent_id ?? 0);
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The row this one is inside, if any.
     *
     * @return BelongsTo<InventoryItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * What is inside this row. Empty for everything that is not a container.
     *
     * @return HasMany<InventoryItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * The socket this row is installed in, if it is a module in a deck.
     *
     * A module is never "inside" a deck the way a book is inside a shard —
     * see App\Decks\ModuleFitter. `parent_id` stays null for a fitted module;
     * what takes it out of the player's hands is this relation.
     *
     * @return HasOne<DeckFitting, $this>
     */
    public function fitting(): HasOne
    {
        return $this->hasOne(DeckFitting::class, 'module_id');
    }

    /**
     * The deck this row is running on, if it is a program that is running.
     *
     * A third way of being spoken for, alongside sitting in a container and
     * being fitted into a socket — all of them decided in scopeLoose(), so no
     * listing has to learn about any of them.
     *
     * @return BelongsTo<InventoryItem, $this>
     */
    public function runningOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'running_on_deck_id');
    }

    /**
     * What is running on this row, if it is a deck.
     *
     * @return HasMany<InventoryItem, $this>
     */
    public function processes(): HasMany
    {
        return $this->hasMany(self::class, 'running_on_deck_id');
    }

    public function isRunning(): bool
    {
        return $this->running_on_deck_id !== null;
    }

    /**
     * Is this the deck its owner is carrying?
     *
     * NOT a way of being spoken for — see scopeLoose(). An equipped deck lists
     * with everything else; this is only how its card says so.
     */
    public function isEquipped(): bool
    {
        return $this->equippedBy()->exists();
    }

    /**
     * What is installed in this row, if it is a deck.
     *
     * @return HasMany<DeckFitting, $this>
     */
    public function fittings(): HasMany
    {
        return $this->hasMany(DeckFitting::class, 'deck_id');
    }

    /** Is this row installed in something? */
    public function isFitted(): bool
    {
        return $this->fitting()->exists();
    }

    /**
     * The character carrying this row as their equipped deck, if any.
     *
     * A hasOne through `characters.equipped_deck_id`. One column holds one
     * value, so at most one character can be pointing at a given row — and
     * only its owner ever can, which App\Decks\DeckEquipper enforces.
     *
     * @return HasOne<Character, $this>
     */
    public function equippedBy(): HasOne
    {
        return $this->hasOne(Character::class, 'equipped_deck_id');
    }

    /**
     * How much of this container is spoken for.
     *
     * `size` is per object, so a stack of three 8-unit books occupies 24. This
     * is the first thing to read `size` at all: it has been stored and
     * unenforced since the table was created.
     */
    public function usedCapacity(): int
    {
        return $this->children
            ->sum(fn (self $child) => (int) $child->item->size * $child->quantity);
    }

    public function remainingCapacity(): int
    {
        return max(0, (int) $this->item->capacity - $this->usedCapacity());
    }

    /**
     * Take one away, and delete the row rather than leave a zero.
     *
     * A quantity of 0 is not a thing a player owns, and the database refuses
     * it outright.
     */
    public function consumeOne(): void
    {
        if ($this->quantity <= 1) {
            $this->delete();

            return;
        }

        $this->decrement('quantity');
    }

    /**
     * Only rows the player actually has to hand.
     *
     * Every listing that means "the player's inventory" wants this. Without it
     * an imprint written to a card shows up twice: once on the card and once
     * loose on the deck it was never on.
     *
     * Three ways of not being to hand, and they are different things:
     *
     * - INSIDE something — a parent_id. Containment, which is about bulk.
     * - FITTED into a deck. A module in a socket is not cargo, it is part of
     *   the machine.
     * - RUNNING on one. A process is a thing doing something, and stopping it
     *   is what puts it back.
     *
     * BEING EQUIPPED IS NOT ONE OF THEM, and it used to be. A carried deck is
     * still a thing you have — it is on you, not elsewhere — so it lists with
     * everything else and wears its own tag. Taking it out of the grid meant
     * the stash needed a panel whose only job was to be the one route back to
     * it, which is a box built to solve a problem the exclusion created.
     * (Equipped is not "jacked in" either; that is a session in cyberspace, and
     * a deck stays equipped right through one.)
     *
     * All three belong here rather than at the call sites, so that a fourth
     * way to be spoken for is one edit and not an audit of every screen — and
     * so that removing one is too.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLoose(Builder $query): void
    {
        $query
            ->whereNull('parent_id')
            ->whereNull('running_on_deck_id')
            // Installed in a body. Elsewhere in exactly the sense the other
            // three are: it is yours, and it is not in your hands.
            ->whereNull('installed_at')
            ->whereDoesntHave('fitting');
    }

    /** @param  Builder<self>  $query */
    public function scopeRunning(Builder $query): void
    {
        $query->whereNotNull('running_on_deck_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeInContainer(Builder $query, string $container): void
    {
        $query->whereHas('item', fn (Builder $item) => $item->where('container', $container));
    }
}
