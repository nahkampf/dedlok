<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\ExtractedFile;
use App\Cyberspace\ScanFact;
use App\Cyberspace\ScanFile;
use App\Cyberspace\SystemType;
use App\Support\Qb;
use App\Support\Text;
use Carbon\Carbon;
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
#[Fillable(['character_id', 'item_id', 'parent_id', 'quantity', 'running_on_deck_id', 'started_at', 'installed_at', 'belt_slot', 'extracted_file', 'scan_file', 'loaded_ammo_id', 'loaded_rounds'])]
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
            'scan_file' => 'array',
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

        $sealed = $this->item?->slug === ExtractedFile::SEALED_SLUG;
        $origin = $file->origin;

        $parts = [
            Text::get('inventory.files.size', ['qb' => $file->sizeQb]),
            ...($file->grade === null ? [] : [Text::get('inventory.files.headline.grade', ['grade' => $file->grade])]),
            ...($origin?->systemType === null ? [] : [Text::get('inventory.files.from', ['type' => SystemType::from($origin->systemType)->label()])]),
            ...($origin?->ownerName === null ? [] : [Text::get('inventory.files.owned_by', ['owner' => $origin->ownerName])]),
            ...($origin?->osTitle === null ? [] : [Text::get('inventory.files.running', ['os' => $origin->osTitle])]),
            ...($origin?->idsLevel === null ? [] : [Text::get('inventory.files.guarded', ['ids' => $origin->idsLevel, 'ice' => $origin->iceLevel ?? 0])]),
            $sealed
                ? ($file->encryptionSkillId !== null
                    ? Text::get('inventory.files.encrypted_with', ['skill' => $this->encryptionSkillName($file)])
                    : Text::get('inventory.files.unencrypted'))
                : Text::get('inventory.files.unencrypted'),
            Text::get($sealed ? 'inventory.files.sealed_value' : 'inventory.files.broken_value', ['value' => $sealed ? $file->valueSealed : $file->valueBroken]),
        ];

        return implode(Text::get('items.summary.separator'), $parts);
    }

    /**
     * The item's name, and for a component the one thing about its origin it is named by: "Kernel Log (AuraOS)".
     * Two components of one item and different origins are different things to a recipe, so they read as different things.
     */
    public function label(): string
    {
        $name = (string) $this->item?->displayName();
        $file = $this->extractedFile();

        if ($file === null || $this->item === null) {
            return $name;
        }

        $origin = $file->origin;
        $headline = match (config("cyberspace.decryption.components.{$this->item->slug}.headline")) {
            'grade' => $file->grade === null ? null : Text::get('inventory.files.headline.grade', ['grade' => $file->grade]),
            'os' => $origin?->osTitle,
            'owner' => $origin?->ownerName,
            'system_type' => $origin?->systemType === null ? null : SystemType::from($origin->systemType)->label(),
            'encryption' => $origin?->encryptionTitle,
            'security' => $origin?->idsLevel === null ? null : Text::get('inventory.files.headline.security', ['ids' => $origin->idsLevel, 'ice' => $origin->iceLevel ?? 0]),
            default => null,
        };

        return $headline === null ? $name : "{$name} ({$headline})";
    }

    private function encryptionSkillName(ExtractedFile $file): string
    {
        return Skill::query()->find($file->encryptionSkillId)?->title
            ?? Text::get('inventory.files.unknown_encryption');
    }

    /**
     * A decrypted file: a component (or a Memory Dump), not a sealed dump. Components of one kind and origin are DRAWN as one
     * slot in the stash (see `groupKey()`), though each stays its own row with its own file.
     */
    public function isComponent(): bool
    {
        return $this->extracted_file !== null && $this->item !== null && $this->item->slug !== ExtractedFile::SEALED_SLUG;
    }

    /** The glyph a slot shows: a file's own (sealed dump, memory dump, component), otherwise its item's. */
    public function glyph(): string
    {
        if ($this->extracted_file === null || $this->item === null) {
            return $this->item->glyph();
        }

        return match (true) {
            $this->item->slug === ExtractedFile::SEALED_SLUG => "\u{25A7}",
            $this->item->slug === ExtractedFile::DECRYPTED_SLUG => "\u{29C8}",
            default => "\u{2B22}",
        };
    }

    /**
     * The colour class a file wears instead of its tier: scan files, sealed dumps and components are each one colour
     * (`.f-scan`, `.f-sealed`, `.f-component`); anything else keeps the item's own tier.
     */
    public function tierClass(): string
    {
        return match (true) {
            $this->scan_file !== null => 'f-scan',
            $this->extracted_file !== null && ! $this->isComponent() => 'f-sealed',
            $this->isComponent() => 'f-component',
            default => $this->item->tierClass(),
        };
    }

    /** What the stash groups a row under: one key for rows that read the same ("Kernel Log (AuraOS)"), the row's own id for anything else. */
    public function groupKey(): string
    {
        return $this->isComponent() ? 'c:'.$this->item_id.':'.$this->label() : 'r:'.$this->getKey();
    }

    /** Where this file waits to be decrypted, if it does. Asked only of file rows. */
    public function decryptionEntry(): HasOne
    {
        return $this->hasOne(DecryptionEntry::class, 'inventory_item_id');
    }

    /**
     * What a buyer starts from, before its percentage: a file's own value (the broken one once it is a Memory Dump),
     * otherwise the item's `base_cost`. THE one place a row's stock price is worked out.
     */
    public function stockPrice(): int
    {
        $file = $this->extractedFile();

        return $file !== null
            ? $file->stockPrice($this->item->slug !== ExtractedFile::SEALED_SLUG)
            : (int) $this->item->base_cost;
    }

    /**
     * The read this row used to be, before it became something sellable.
     *
     * Non-null only on a row of the "Scan File" item —
     * App\Cyberspace\ScanExporter is the only writer, and
     * App\Cyberspace\ScanFile is the shape.
     */
    public function scanFile(): ?ScanFile
    {
        return ScanFile::fromArray($this->scan_file);
    }

    /**
     * What a file says about itself on its card.
     *
     * The machine, then the two facts anybody buying one is deciding on, then
     * the clock — because a file's whole value is that it is still true. A
     * stale one says so where the grade would be, since nothing else about it
     * matters once it is.
     */
    public function scanFileSummary(): ?string
    {
        $file = $this->scanFile();

        if ($file === null) {
            return null;
        }

        $scan = $file->toScan();

        $parts = [
            $file->displayName(),
            ScanFact::Grade->readFrom($scan) ?? Text::get('cyberspace.reading.unread'),
            $this->scanFileClock($file),
        ];

        return implode(Text::get('items.summary.separator'), $parts);
    }

    /**
     * How long it is still worth anything.
     *
     * The same sentence the scan screen uses for a live file — one wording for
     * one fact, whether the file is still a read or already a possession. A
     * file with no clock on it at all is treated as stale: every real one
     * carries its 48 hours out of `system_scans`, so the absence is corruption
     * rather than a file that lasts for ever.
     */
    private function scanFileClock(ScanFile $file): string
    {
        if ($file->expiresAt === null || $file->hasExpired()) {
            return Text::get('inventory.scans.stale');
        }

        return Text::get('cyberspace.scan.expires', [
            'when' => Carbon::instance($file->expiresAt)->diffForHumans(),
        ]);
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

        // ON THE BELT is the same kind of place: the belted pile is one slot's contents, and must not merge into
        // the loose pile of the same item (BeltEquipper merges it back when it comes off).
        if ($this->belt_slot !== null) {
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
     * A module is never "inside" a deck the way an imprint is inside a shard —
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
     * The listing this row is on the market under, if it is.
     *
     * The FIFTH way of being spoken for, and the only one that is a promise to
     * somebody else rather than a fact about where the thing is — which is why
     * it belongs in scopeLoose() beside the other four rather than being
     * checked wherever a screen happens to remember.
     *
     * @return HasOne<MarketOffer, $this>
     */
    public function offer(): HasOne
    {
        return $this->hasOne(MarketOffer::class, 'inventory_item_id');
    }

    public function isListed(): bool
    {
        return $this->offer()->exists();
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
     * The slots this row is worn in, if it is armour being worn.
     *
     * @return HasMany<WornSlot, $this>
     */
    public function wornSlots(): HasMany
    {
        return $this->hasMany(WornSlot::class, 'inventory_item_id');
    }

    /** Is this the weapon its owner is holding, in either hand? Like the equipped deck, it still lists. */
    public function isWielded(): bool
    {
        return Character::query()
            ->where(fn ($q) => $q->where('equipped_ranged_id', $this->getKey())->orWhere('equipped_melee_id', $this->getKey()))
            ->exists();
    }

    /**
     * Rounds in the magazine. A NULL ammunition pointer is an empty one whatever the count says —
     * the pointer is nulled when its item is deleted and no CHECK can keep the two together.
     */
    public function rounds(): int
    {
        return $this->loaded_ammo_id === null ? 0 : (int) $this->loaded_rounds;
    }

    /** @return BelongsTo<Item, $this> the ammunition item loaded in this weapon, if any */
    public function loadedAmmo(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'loaded_ammo_id');
    }

    /**
     * On the body, in the hands, or with rounds in it: worn armour, a wielded weapon, a loaded
     * magazine. Not for sale, and not for putting away — each would take it somewhere its state
     * does not follow. Unequip it, or unload it, first.
     */
    public function isOnPerson(): bool
    {
        return $this->rounds() > 0 || $this->isWorn() || $this->isWielded() || $this->isBelted();
    }

    /** Is this on the belt? Like worn armour, a belted row still lists with everything else. */
    public function isBelted(): bool
    {
        return $this->belt_slot !== null;
    }

    /** Is this armour being worn? Like an equipped deck, worn armour still lists with everything else. */
    public function isWorn(): bool
    {
        return $this->wornSlots()->exists();
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
     * `size` is per object, so a stack of three 8-unit imprints occupies 24. This
     * is the first thing to read `size` at all: it has been stored and
     * unenforced since the table was created.
     */
    public function usedCapacity(): float
    {
        return Qb::sum($this->children
            ->map(fn (self $child) => (float) $child->item->size * $child->quantity)
            ->all());
    }

    public function remainingCapacity(): float
    {
        return max(0.0, Qb::of((float) $this->item->capacity - $this->usedCapacity()));
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
            ->whereDoesntHave('fitting')
            // LISTED ON THE MARKET. The fifth, and the promise the offer makes
            // to whoever is about to buy it: a listed module cannot be fitted,
            // a listed program cannot be run, a listed file cannot be read in.
            // The offer row is the fact — see App\Market\OfferBook — so there
            // is no column to keep in step with it.
            ->whereDoesntHave('offer');
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
