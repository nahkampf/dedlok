<?php

declare(strict_types=1);

namespace App\Models;

use App\Combat\WeaponClass;
use App\Decks\BoardLayout;
use App\Decks\Hardware;
use App\Decks\SlotLayout;
use App\Implants\BodyRegion;
use App\Market\Rarity;
use App\Programs\ProgramKind;
use App\Support\Qb;
use App\Support\Text;
use Carbon\CarbonInterval;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * An item definition.
 *
 * In the database rather than config, for the reasons the migration gives.
 * The row carries the whole definition — mechanical values and the words both.
 * It did not always: the words were looked up by slug from lang/en/items.php
 * until translation was removed, and a row that only held half of itself was
 * the price of being translatable.
 */
#[Fillable([
    'slug', 'name', 'flavor', 'container', 'tier', 'rarity', 'stackable', 'use_op', 'use_skill_id',
    'weight', 'size', 'capacity', 'accepts', 'hardware', 'board_layout', 'onboard_storage', 'base_cost', 'on_market', 'seed_quantity',
    'implant_region', 'modifiers',
])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    public const CONTAINER_PHYSICAL = 'physical';

    public const CONTAINER_DATA = 'data';

    /** @var list<string> */
    public const CONTAINERS = [self::CONTAINER_PHYSICAL, self::CONTAINER_DATA];

    /** Teaching a skill. The only use there is so far. */
    public const USE_INJECT_SKILL = 'inject_skill';

    /** @var list<string> */
    public const USE_OPS = [self::USE_INJECT_SKILL];

    /** Where every item's picture lives, one subdirectory per type below it. */
    public const IMAGE_ROOT = 'items';

    /**
     * The one image type that is not an enum case: an imprint is data on the
     * card and an imprint in the filing cabinet, and every imprint looks the
     * same.
     */
    public const IMAGE_IMPRINT = 'imprint';

    /**
     * Two tiers, and the scale is deliberately short.
     *
     * It was five, named Standard through Anomalous, which drew a
     * distinction no mechanic has ever asked about. Widen it when
     * something reads it for more than a colour.
     */
    public const MAX_TIER = 2;

    protected function casts(): array
    {
        return [
            'implant_region' => BodyRegion::class,
            'modifiers' => 'array',
            'tier' => 'integer',
            'rarity' => Rarity::class,
            'stackable' => 'boolean',
            'weight' => 'decimal:2',
            'size' => 'float',
            'capacity' => 'float',
            'hardware' => Hardware::class,
            'board_layout' => 'array',
            'onboard_storage' => 'float',
            'base_cost' => 'integer',
            'on_market' => 'boolean',
            'seed_quantity' => 'integer',
        ];
    }

    /**
     * For inject_skill items: what the imprint teaches.
     *
     * @return BelongsTo<Skill, $this>
     */
    public function useSkill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'use_skill_id');
    }

    /** @return HasMany<InventoryItem, $this> */
    public function inventoryEntries(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /**
     * The sockets this deck presents. Empty for everything that is not one.
     *
     * Ordered here rather than at every call site: a deck's sockets are a
     * list a player reads, and id order after an edit is not that list.
     */
    /**
     * Where in a body this goes, or null if it is not an implant.
     *
     * The ONLY place the region is written down, so a fitted row can never
     * disagree with its definition about where it went — the lesson
     * `deck_modules` learned by carrying the numbers and not the word.
     */
    public function implantRegion(): ?BodyRegion
    {
        return $this->implant_region;
    }

    public function isImplant(): bool
    {
        return $this->implant_region !== null;
    }

    /** @return HasMany<DeckSlot, $this> */
    public function deckSlots(): HasMany
    {
        return $this->hasMany(DeckSlot::class)->orderBy('position');
    }

    /** What this module is worth. Null for everything that is not one. */
    /**
     * The software half, if this item is a program.
     *
     * Its presence is what MAKES an item a program — there is no column on
     * this table saying so, the same way `deck_modules` rather than a word is
     * what makes an item a module.
     *
     * @return HasOne<Program, $this>
     */
    public function program(): HasOne
    {
        return $this->hasOne(Program::class);
    }

    /**
     * The recipe this item is, if it is a design (a Design Copy or a Design Original).
     *
     * @return HasOne<Design, $this>
     */
    public function design(): HasOne
    {
        return $this->hasOne(Design::class);
    }

    public function isDesign(): bool
    {
        return $this->design !== null;
    }

    public function isProgram(): bool
    {
        return $this->program()->exists();
    }

    /**
     * The drug half of this item, if it is one.
     *
     * Its presence is what MAKES an item a drug, the same way a `programs` row
     * makes one software. What a dose DOES stays on `modifiers` here, like an
     * implant's — a thing with a row carries its modifiers on that row.
     *
     * @return HasOne<Drug, $this>
     */
    public function drug(): HasOne
    {
        return $this->hasOne(Drug::class);
    }

    public function isDrug(): bool
    {
        return $this->drug()->exists();
    }

    /**
     * The consumable half of this item, if it has one: what using a copy does, once. An item is a consumable
     * because it has this row.
     *
     * @return HasOne<Consumable, $this>
     */
    public function consumable(): HasOne
    {
        return $this->hasOne(Consumable::class);
    }

    public function isConsumable(): bool
    {
        return $this->consumable()->exists();
    }

    /**
     * The armour half of this item, if it has one. An item is armour because it has this row.
     *
     * @return HasOne<Armour, $this>
     */
    public function armour(): HasOne
    {
        return $this->hasOne(Armour::class);
    }

    public function isArmour(): bool
    {
        return $this->armour()->exists();
    }

    /**
     * The weapon half of this item, if it has one.
     *
     * @return HasOne<Weapon, $this>
     */
    public function weapon(): HasOne
    {
        return $this->hasOne(Weapon::class);
    }

    public function isWeapon(): bool
    {
        return $this->weapon()->exists();
    }

    /**
     * The ammunition half of this item, if it has one.
     *
     * @return HasOne<Ammunition, $this>
     */
    public function ammunition(): HasOne
    {
        return $this->hasOne(Ammunition::class);
    }

    public function isAmmunition(): bool
    {
        return $this->ammunition()->exists();
    }

    /** @return HasOne<DeckModule, $this> */
    public function deckModule(): HasOne
    {
        return $this->hasOne(DeckModule::class);
    }

    /**
     * What the market is holding of this item. Absent means none.
     *
     * @return HasOne<MarketStock, $this>
     */
    public function marketStock(): HasOne
    {
        return $this->hasOne(MarketStock::class);
    }

    /** @return HasMany<MarketPurchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(MarketPurchase::class);
    }

    /** How many are on the shelf right now. A missing stock row is zero. */
    public function stockOnHand(): int
    {
        return (int) ($this->marketStock?->quantity ?? 0);
    }

    public function isUsable(): bool
    {
        return $this->use_op !== null;
    }

    public function isImprint(): bool
    {
        return $this->use_op === self::USE_INJECT_SKILL;
    }

    public function isCyberdeck(): bool
    {
        return $this->hardware === Hardware::Deck;
    }

    /**
     * QB this deck holds before any storage module — its own figure, or the
     * `decks.onboard_storage` fallback. Nothing that is not a deck holds any.
     */
    public function onboardStorage(): float
    {
        if (! $this->isCyberdeck()) {
            return 0;
        }

        return $this->onboard_storage ?? Qb::of(config('decks.onboard_storage', 0));
    }

    /** A module of some kind — a CPU, a stick of RAM, a power supply. */
    public function isModule(): bool
    {
        return $this->hardware?->isModule() ?? false;
    }

    /**
     * This deck's sockets, counted by type.
     *
     * The layout is the deck's specification; the rows are how it is stored.
     * A non-deck has an empty one rather than none, so a caller that asks
     * gets an answer it can use instead of a null to defend against.
     */
    public function slotLayout(): SlotLayout
    {
        return SlotLayout::fromTypes(
            $this->deckSlots->map(fn (DeckSlot $slot) => $slot->type())
        );
    }

    /**
     * This deck's sockets, arranged on its board.
     *
     * The stored coordinates are an override on top of an automatic ring, so
     * a deck nobody has arranged still draws — and a deck arranged before it
     * grew a NIC puts the NIC somewhere sensible rather than at the origin.
     * A non-deck has an empty board rather than none, like slotLayout().
     */
    public function board(): BoardLayout
    {
        return BoardLayout::for($this->slotLayout(), $this->board_layout);
    }

    /**
     * The one-line spec: a deck's sockets, or a module's numbers.
     *
     * Null for anything that is not deck hardware, which is most of the
     * catalogue — so a caller can render it or not without asking what kind
     * of thing it is holding.
     */
    public function hardwareSummary(): ?string
    {
        if ($this->hardware === null) {
            return null;
        }

        if ($this->hardware->isDeck()) {
            $layout = $this->slotLayout();

            return $layout->isEmpty() ? null : $layout->summary();
        }

        return $this->deckModule?->summary($this->hardware);
    }

    public function isData(): bool
    {
        return $this->container === self::CONTAINER_DATA;
    }

    /**
     * Does this item hold other items?
     *
     * A memory card does; a rifle does not. Being a container is the
     * exception, so this is a capacity above zero rather than a flag: the
     * number was going to have to exist anyway, and a second boolean agreeing
     * with it would only be a thing to get wrong.
     */
    public function isContainer(): bool
    {
        return $this->capacity > 0;
    }

    /**
     * May $item go inside this one?
     *
     * `accepts` is compared against the other item's `container` — the kind of
     * thing it is. That is the whole reason `container` survived the arrival
     * of nesting: an imprint is data whether it is loose on a deck or written
     * to a card in someone's coat pocket.
     */
    public function canHold(Item $item): bool
    {
        return $this->isContainer() && $this->accepts === $item->container;
    }

    /**
     * The name a player sees.
     *
     * An imprint has none of its own: there is exactly one imprint per skill, so
     * storing a name per imprint would be a second copy of the skill's title
     * waiting to disagree with it. The slug is the last resort — visible and
     * wrong beats blank and mysterious.
     */
    public function displayName(): string
    {
        if ($this->isImprint()) {
            return Text::get('items.imprint.name', [
                'skill' => $this->useSkill?->title ?? $this->slug,
            ]);
        }

        return $this->name ?? $this->slug;
    }

    public function displayFlavor(): ?string
    {
        if ($this->isImprint()) {
            return Text::get('items.imprint.flavor');
        }

        return $this->flavor;
    }

    public function rarityName(): string
    {
        return $this->rarity->label();
    }

    /**
     * What kind of thing this is, in the words the card uses.
     *
     * Deck hardware answers with the piece it is: a stick of RAM is RAM first
     * and a physical object second, and which of the two inventories it lives
     * in has already been answered by the tab it was found under. Everything
     * else falls back to that inventory, which is the only kind it has.
     */
    /**
     * What this item IS, in one word.
     *
     * Deck hardware says which piece it is, software says what it is for, and
     * everything else falls back to the inventory it lives in. A program that
     * called itself "Data" would be telling the player the one thing they can
     * already see from where it is sitting.
     */
    public function kindName(): string
    {
        return $this->hardware?->label()
            ?? $this->program?->kind->label()
            ?? Text::get($this->isImprint() ? 'items.kinds.imprint' : "items.kinds.{$this->container}");
    }

    /**
     * The line under a listing's title: the kind, and for a module its variant
     * ("RAM · Opto-electrical", "CPU · Socket K"). Separate from kindName()
     * because that one groups the shelf and must not split a group by variant.
     */
    public function kindLine(): string
    {
        $variant = $this->hardware?->isDeck() ? null : $this->deckModule?->variant?->label();

        return $variant === null
            ? $this->kindName()
            : $this->kindName().Text::get('items.summary.separator').$variant;
    }

    /**
     * The market's top accordion: Ammunition, Weapons, Drugs and Implants take
     * their gear out of the catch-all "Physical"; everything else is its kind.
     */
    public function marketCategory(): string
    {
        $key = match (true) {
            $this->ammunition !== null => 'ammunition',
            $this->weapon !== null => 'weapons',
            $this->drug !== null => 'drugs',
            $this->isImplant() => 'implants',
            default => null,
        };

        return $key === null ? $this->kindName() : Text::get("market.categories.{$key}");
    }

    /**
     * The sub-accordion inside a category: a module's variant, an ammunition's
     * calibre, a weapon's class. NULL is a category with nothing to split by.
     */
    public function marketSection(): ?string
    {
        return $this->deckModule?->variant?->label()
            ?? $this->ammunition?->calibre
            ?? $this->weapon?->class?->label();
    }

    /**
     * Which folder under public/images this item's picture is found in.
     *
     * One subdirectory per TYPE of item — `items/cpu`, `items/icebreaker`,
     * `items/physical` — because one folder holding every item in the game is
     * a folder nobody can find a file in. The vocabulary is the one the card
     * already speaks (see kindName()), with two differences, both because this
     * is a filing system rather than a sentence:
     *
     *  - an imprint files itself, though it CALLS itself data: there is one
     *    imprint per skill and they all look alike, so one picture in one folder
     *    covers every imprint in the game;
     *  - a container's kind is the inventory it lives in, which is the same
     *    fallback kindName() ends on.
     *
     * `App\Support\Image` walks the default back up, so a type with no
     * picture of its own falls to `items/_default.jpg` rather than to nothing.
     */
    public function imageKind(): string
    {
        return self::IMAGE_ROOT.'/'.$this->imageType();
    }

    /** The subdirectory alone: `cpu`, `daemon`, `imprint`, `physical`. */
    public function imageType(): string
    {
        if ($this->hardware !== null) {
            $variant = $this->hardware->isDeck() ? null : $this->deckModule?->variant;

            return $this->hardware->value.($variant === null ? '' : '/'.$variant->value);
        }

        if ($this->program !== null) {
            return $this->program->kind->value;
        }

        if ($this->isImprint()) {
            return self::IMAGE_IMPRINT;
        }

        // The market's categories: ammunition by calibre, weapons by class.
        if ($this->container === self::CONTAINER_PHYSICAL) {
            if ($this->ammunition !== null) {
                $calibre = Str::slug((string) $this->ammunition->calibre);

                return 'ammunition'.($calibre === '' ? '' : '/'.$calibre);
            }

            if ($this->weapon !== null) {
                return 'weapons/'.$this->weapon->class->value;
            }

            if ($this->drug !== null) {
                return 'drugs';
            }

            if ($this->isImplant()) {
                return 'implants';
            }
        }

        return $this->container;
    }

    /**
     * Every type there is, which is every subdirectory there should be.
     *
     * Composed from the three enums rather than written out, so a new kind of
     * hardware or program arrives here on its own — a folder that has to be
     * remembered is a folder that is missing on the day it is needed.
     *
     * @return list<string>
     */
    public static function imageTypes(): array
    {
        $variants = [];

        foreach (Hardware::cases() as $hardware) {
            foreach ($hardware->variants() as $variant) {
                $variants[] = $hardware->value.'/'.$variant->value;
            }
        }

        return [
            ...array_column(Hardware::cases(), 'value'),
            ...$variants,
            ...ProgramKind::values(),
            self::IMAGE_IMPRINT,
            ...self::CONTAINERS,
            // What the market calls them; ammunition's calibre folders are made as they are met.
            'ammunition', 'drugs', 'implants', 'weapons',
            ...array_map(static fn (WeaponClass $class): string => 'weapons/'.$class->value, WeaponClass::cases()),
        ];
    }

    /**
     * What a program costs, as a spec line.
     *
     * Reads like a module's, and for the same reason: the numbers, never the
     * word. Which kind of program it is has already been said beside the tier.
     *
     * Two of the three are spent only while it runs and the third is paid by
     * owning it, so the line says which is which rather than leaving three
     * bare quantabit figures to be told apart by position.
     */
    public function programSummary(): ?string
    {
        $program = $this->program;

        if ($program === null) {
            return null;
        }

        $parts = [
            Text::get('programs.costs.compute', ['value' => Qb::of($program->qflops_cost)]),
            Text::get('programs.costs.memory', ['value' => Qb::of($program->memory_cost)]),
            Text::get('programs.costs.storage', ['value' => Qb::of($program->storage_size)]),
        ];

        // An icebreaker is run-only by nature; saying so on every one is noise.
        if ($program->background_process || $program->kind !== ProgramKind::Icebreaker) {
            $parts[] = Text::get($program->background_process ? 'programs.background' : 'programs.run_only');
        }

        if ($program->isTemporary()) {
            $parts[] = Text::get('programs.costs.lifetime', ['time' => CarbonInterval::seconds($program->lifetime_seconds)->cascade()->forHumans(['short' => true, 'parts' => 2])]);
        }

        return implode(Text::get('items.summary.separator'), $parts);
    }

    /**
     * What a program IS and what stands in the way of running it, beside the
     * cost line: the encryptions a Decryptor can break, and the
     * one-at-a-time rules `ProgramRunner::refusalFor()` enforces by kind.
     * There is no skill requirement to run software, so none is listed.
     *
     * @return list<string>
     */
    public function programRequirements(): array
    {
        $program = $this->program;

        if ($program === null) {
            return [];
        }

        $lines = [];

        if ($program->kind === ProgramKind::Decryptor) {
            $names = $program->decrypts === null || $program->decrypts === []
                ? Text::get('programs.spec.any_encryption')
                : Skill::query()->whereIn('slug', $program->decrypts)->orderBy('title')->pluck('title')->implode(', ');
            $lines[] = Text::get('programs.spec.breaks', ['names' => $names]);
        }

        // An icebreaker's one-at-a-time rules are its nature, so it lists none.
        if ($program->kind === ProgramKind::Icebreaker) {
            return $lines;
        }

        $lines[] = Text::get($program->multithreaded ? 'programs.spec.multithreaded' : 'programs.spec.single_instance');

        if ($program->kind === ProgramKind::Utility) {
            foreach (['locates', 'walks', 'jumps'] as $ability) {
                $program->{$ability} && $lines[] = Text::get("programs.spec.{$ability}");
            }

            ($program->locates || $program->walks) && $lines[] = Text::get('programs.spec.one_locator');
            $program->jumps && $lines[] = Text::get('programs.spec.one_jumper');
        }

        return $lines;
    }

    public function tierName(): string
    {
        return Text::get("items.tiers.{$this->tier}");
    }

    /**
     * The UI kit's tier class: t1 or t2. Tier, not rarity — see App\Market\Rarity.
     *
     * The kit's accent ramp still runs to .t5; the game only reaches the
     * first two steps of it.
     */
    public function tierClass(): string
    {
        return 't'.$this->tier;
    }

    /**
     * The glyph a slot shows. View mapping, like tierClass() beside it.
     *
     * Every one of these is from the set the kit's gallery already proves
     * renders in the font stack — U+2317, the obvious choice for a card,
     * does not. Adding a kind means proving its glyph there first.
     */
    public function glyph(): string
    {
        $kind = $this->hardware?->value ?? $this->program?->kind->value;

        return match (true) {
            $this->isImprint() => "\u{25C8}",
            $kind !== null && isset(self::GLYPHS[$kind]) => self::GLYPHS[$kind],
            $this->isImplant() => "\u{2624}",
            $this->drug !== null => "\u{2731}",
            $this->consumable !== null => "\u{236C}",
            $this->armour !== null => "\u{26CA}",
            $this->weapon !== null => "\u{2715}",
            $this->ammunition !== null => "\u{205E}",
            $this->isContainer() => "\u{25A2}",
            default => "\u{25A4}",
        };
    }

    /**
     * Hardware kind and program kind to glyph. Chosen from the shortlist page, 2026-10-10; each is from the set the
     * gallery proves renders. A kind with no entry (a scan file's plain item) falls through to the stash default.
     */
    public const GLYPHS = [
        'deck' => "\u{2394}",
        'cpu' => "\u{2B23}",
        'npu' => "\u{232C}",
        'ram' => "\u{2338}",
        'nic' => "\u{21C4}",
        'psu' => "\u{2301}",
        'thermal' => "\u{224B}",
        'storage' => "\u{26C3}",
        'expansion' => "\u{229E}",
        'icebreaker' => "\u{25ED}",
        'decryptor' => "\u{26BF}",
        'evasion' => "\u{25CC}",
        'analyser' => "\u{2315}",
        'daemon' => "\u{23E3}",
        'script' => "\u{21AF}",
        'utility' => "\u{27A4}",
    ];

    /** @param  Builder<self>  $query */
    public function scopeInContainer(Builder $query, string $container): void
    {
        $query->where('container', $container);
    }

    /** @param  Builder<self>  $query */
    public function scopeOnMarket(Builder $query): void
    {
        $query->where('on_market', true);
    }

    /**
     * Items the market restocks: sold there, and with a shelf size set.
     *
     * `on_market` and `seed_quantity` answer different questions — the first
     * is whether the thing is sold at all, the second how many of it a full
     * shelf is — and a restock needs both to be true. An item with a shelf
     * size of zero is not a mistake: it is how something is listed without
     * ever being in stock.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRestockable(Builder $query): void
    {
        // An imprint is never on the SHELF: skills are sold by shops (see `city:stock-skills`), found as loot, or
        // paid out by a loyalty store. Players may still sell them to one another. `on_market` on an imprint still
        // decides loot and what a shop lists.
        $query->onMarket()->whereNull('use_skill_id')->where('seed_quantity', '>', 0);
    }
}
