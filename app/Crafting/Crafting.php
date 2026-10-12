<?php

declare(strict_types=1);

namespace App\Crafting;

use App\Characters\EncounterPause;
use App\Cyberspace\ExtractedFile;
use App\Cyberspace\SystemType;
use App\Decks\DeckTotals;
use App\Decks\Storage;
use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\Craft;
use App\Models\Design;
use App\Models\DesignComponent;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Stats\Resolver;
use App\Stats\SkillStats;
use App\Support\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CODE CRAFTING (CRAFTING.md): components and a Design, over time, into a program. THE ONLY WRITER of `crafts`.
 *
 * - **Starting locks the build in.** The components are spent and a Design Copy is consumed in the same transaction that creates the
 *   craft, and the time it needs is worked out then, with the skills the character has then. Nothing is refunded: cancelling forfeits.
 * - **No queue, only slots.** `crafting_slots` (base 1) builds may run at once; starting one with every slot taken is refused.
 * - **The clock only runs while the equipped deck is ready.** Progress is `progress_seconds`, credited by `settle()`, which runs when somebody
 *   looks, before anything that changes the deck (`ProgramRunner::stopAll()`, which every deck change passes through) and from the
 *   `crafting:settle` sweep. It credits the time since the last settle if the deck is ready NOW, so settling before a change credits the
 *   state the change is about to end. Accurate to the sweep (a minute): a drug wearing off between sweeps is noticed at the next.
 * - **A finished build whose product does not fit waits, finished, holding its slot** (`finished_at`), and delivers when there is room.
 * - **A craft never fails.** Skills shorten the time (`crafting_seconds.<kind>`) and the quantities (`crafting_cost`).
 */
final class Crafting
{
    public function __construct(private readonly Notifier $notifier) {}

    public static function make(): self
    {
        return new self(new Notifier);
    }

    // ---- what a screen asks -----------------------------------------------

    public function slots(Character $character): int
    {
        return max(1, $character->stat('crafting_slots')->toInt());
    }

    /** @return Collection<int, Craft> */
    public function crafts(Character $character): Collection
    {
        return Craft::query()->where('character_id', $character->getKey())->orderBy('id')->get();
    }

    /** The designs a character holds that a craft could start from, best first. */
    public function designsHeld(Character $character): Collection
    {
        return InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->loose()
            ->whereHas('item.design')
            ->with(['item.design.components.component', 'item.design.product.program'])
            ->get()
            ->sortBy(fn (InventoryItem $row): string => (string) $row->item?->displayName())
            ->values();
    }

    /** Is the deck that does the working ready right now? */
    public function deckReady(Character $character): bool
    {
        $deck = $character->equippedDeck;

        return $deck !== null && DeckTotals::forCharacter($character, $deck, app(Resolver::class))->isReady();
    }

    public function plan(Character $character, InventoryItem $row): CraftPlan
    {
        $design = $row->item?->design;
        $held = InventoryItem::query()->whereKey($row->getKey())->where('character_id', $character->getKey())->loose()->exists();

        if ($design === null || ! $held) {
            return new CraftPlan($row, null, [], 0, CraftingRefusal::NotADesign);
        }

        $design->loadMissing('components.component', 'product.program');
        $program = $design->product?->program;

        if ($program === null) {
            return new CraftPlan($row, $design, [], 0, CraftingRefusal::ProductGone);
        }

        $seconds = app(Resolver::class)
            ->resolve(SkillStats::CRAFTING_PREFIX.$program->kind->value, (float) $design->craft_seconds, $character->modifiers())
            ->toInt();

        $percent = $character->stat('crafting_cost')->value;
        $lines = $design->components->map(fn (DesignComponent $line): CraftLine => $this->lineFor($character, $line, $percent))->all();

        return new CraftPlan(
            $row, $design, $lines, $seconds,
            $this->blockedBy($character, $design, $program->kind->value, $lines),
            $this->missingSkills($character, $design, $program->kind->value),
        );
    }

    // ---- what a press does ------------------------------------------------

    public function start(Character $character, InventoryItem $row, bool $confirmWaste = false): CraftingRefusal|Craft
    {
        return DB::transaction(function () use ($character, $row, $confirmWaste): CraftingRefusal|Craft {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $fresh = InventoryItem::query()->whereKey($row->getKey())->lockForUpdate()->first() ?? $row;

            $this->settle($locked);

            $plan = $this->plan($locked, $fresh);

            if ($plan->blocked !== null) {
                return $plan->blocked;
            }

            if ($plan->wasteful() && ! $confirmWaste) {
                return CraftingRefusal::WastesGrade;
            }

            foreach ($plan->lines as $line) {
                foreach ($line->picks as ['row' => $pick, 'take' => $take]) {
                    if ($take >= $pick->quantity) {
                        $pick->delete();
                    } else {
                        $pick->forceFill(['quantity' => $pick->quantity - $take])->save();
                    }
                }
            }

            if ($plan->design->single_use) {
                $fresh->consumeOne();
            }

            return Craft::query()->create([
                'character_id' => $locked->getKey(),
                'product_item_id' => $plan->design->product_item_id,
                'name' => (string) $plan->design->product?->displayName(),
                'required_seconds' => max(1, $plan->seconds),
                'progress_seconds' => 0,
                'settled_at' => CarbonImmutable::now(),
            ]);
        });
    }

    /** Give a build up. Its components are gone for good. */
    public function cancel(Character $character, Craft $craft): bool
    {
        if ($craft->character_id !== $character->getKey()) {
            return false;
        }

        return (bool) $craft->delete();
    }

    /**
     * Bring a character's builds up to date, and deliver the ones that are done.
     *
     * @return int how many were delivered
     */
    public function settle(Character $character): int
    {
        return DB::transaction(function () use ($character): int {
            $crafts = Craft::query()->where('character_id', $character->getKey())->orderBy('id')->lockForUpdate()->get();

            if ($crafts->isEmpty()) {
                return 0;
            }

            $now = CarbonImmutable::now();
            // The deck must run, and the clock must not be stopped by an encounter on the grid.
            $ready = $this->deckReady($character) && ! EncounterPause::isHeld($character);
            $delivered = 0;

            foreach ($crafts as $craft) {
                if (! $craft->isFinished()) {
                    $elapsed = max(0, $now->getTimestamp() - $craft->settled_at->getTimestamp());

                    if ($ready) {
                        // Whole seconds credited, the remainder carried by only moving the mark that far.
                        $craft->progress_seconds = min($craft->required_seconds, $craft->progress_seconds + $elapsed);
                        $craft->settled_at = $craft->settled_at->addSeconds($elapsed);

                        if ($craft->progress_seconds >= $craft->required_seconds) {
                            $craft->finished_at = $now;
                        }
                    } else {
                        // Time with the deck down is simply not worked.
                        $craft->settled_at = $now;
                    }
                }

                if ($craft->isFinished() && $this->deliver($character, $craft)) {
                    $delivered++;

                    continue;
                }

                $craft->save();
            }

            return $delivered;
        });
    }

    /** The sweep: every character with a build. */
    public function settleAll(): int
    {
        $delivered = 0;

        Character::query()
            ->whereIn('id', Craft::query()->select('character_id')->distinct())
            ->chunkById(200, function ($chunk) use (&$delivered): void {
                foreach ($chunk as $character) {
                    $delivered += $this->settle($character);
                }
            });

        return $delivered;
    }

    // ---- internals --------------------------------------------------------

    private function deliver(Character $character, Craft $craft): bool
    {
        $product = Item::query()->with('program')->find($craft->product_item_id);

        if ($product === null) {
            $craft->delete();

            return true;
        }

        if (! Storage::fits($character, Storage::sizeOf($product))) {
            return false;
        }

        ItemGranter::grant($character, $product, 1);
        $craft->delete();

        $this->notifier->send($character, Category::Cyberspace, 'crafted', ['item' => $craft->name]);

        return true;
    }

    /** @param  list<CraftLine>  $lines */
    private function blockedBy(Character $character, Design $design, string $kind, array $lines): ?CraftingRefusal
    {
        $kindSkill = config("crafting.gate.{$kind}");

        if ($kindSkill === null) {
            return CraftingRefusal::Uncraftable;
        }

        if ($this->missingSkills($character, $design, $kind) !== []) {
            return CraftingRefusal::NoSkill;
        }

        if (Craft::query()->where('character_id', $character->getKey())->count() >= $this->slots($character)) {
            return CraftingRefusal::NoFreeSlot;
        }

        if ($character->equippedDeck === null) {
            return CraftingRefusal::NoDeck;
        }

        if (! $this->deckReady($character)) {
            return CraftingRefusal::DeckNotReady;
        }

        foreach ($lines as $line) {
            if (! $line->met()) {
                return CraftingRefusal::MissingComponents;
            }
        }

        return null;
    }

    /**
     * The skills this build asks for that the character lacks, each as its title and the level wanted.
     *
     * @return list<array{title: string, level: int}>
     */
    private function missingSkills(Character $character, Design $design, string $kind): array
    {
        $gates = [
            [config('crafting.general_skill'), (int) config('crafting.general_level', 1)],
            [config("crafting.gate.{$kind}"), (int) config('crafting.gate_level', 1)],
            [$design->skill?->slug, $design->skill_level],
        ];

        $missing = [];

        foreach ($gates as [$slug, $level]) {
            if ($slug !== null && ! $this->hasLevel($character, (string) $slug, (int) $level)) {
                $missing[] = ['title' => SkillStats::skills()->get((string) $slug)?->title ?? (string) $slug, 'level' => (int) $level];
            }
        }

        return $missing;
    }

    /** The resolved level, so a skill modifier counts. A skill that is not in the game gates nothing. */
    private function hasLevel(Character $character, string $slug, int $level): bool
    {
        if (! SkillStats::skills()->has($slug)) {
            return true;
        }

        return $character->stat(SkillStats::skillId($slug))->value >= $level;
    }

    private function lineFor(Character $character, DesignComponent $line, float $percent): CraftLine
    {
        $need = max(1, (int) ceil(round($line->quantity * $percent / 100, 6)));
        $candidates = $this->candidates($character, $line);

        $have = $candidates->sum(fn (InventoryItem $row): int => $row->extracted_file === null ? $row->quantity : 1);
        $picks = [];
        $left = $need;

        foreach ($candidates as $row) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, $row->extracted_file === null ? $row->quantity : 1);
            $picks[] = ['row' => $row, 'take' => $take];
            $left -= $take;
        }

        $wasteful = false;

        if ($line->grade_min !== null) {
            foreach ($picks as ['row' => $row]) {
                $wasteful = $wasteful || (($row->extractedFile()?->grade ?? 0) > $line->grade_min);
            }
        }

        return new CraftLine($line, $line->component, $need, (int) $have, $picks, $wasteful, $this->describe($line));
    }

    /**
     * The loose copies that satisfy a line, LOWEST GRADE FIRST so a recipe never burns a better file than it has to.
     *
     * @return Collection<int, InventoryItem>
     */
    private function candidates(Character $character, DesignComponent $line): Collection
    {
        return InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->where('item_id', $line->component_item_id)
            ->loose()
            ->get()
            ->filter(fn (InventoryItem $row): bool => $this->accepts($line, $row->extractedFile()))
            ->sortBy(fn (InventoryItem $row): array => [$row->extractedFile()?->grade ?? 0, $row->getKey()])
            ->values();
    }

    private function accepts(DesignComponent $line, ?ExtractedFile $file): bool
    {
        $origin = $file?->origin;

        $checks = [
            [$line->grade_min, fn (int $min): bool => $file?->grade !== null && $file->grade >= $min],
            [$line->grade_max, fn (int $max): bool => $file?->grade !== null && $file->grade <= $max],
            [$line->os_slug, fn (string $slug): bool => $origin?->osSlug === $slug],
            [$line->owner_id, fn (int $id): bool => $origin?->ownerId === $id],
            [$line->system_type, fn (string $type): bool => $origin?->systemType === $type],
            [$line->ice_min, fn (int $min): bool => $origin?->iceLevel !== null && $origin->iceLevel >= $min],
            [$line->ids_min, fn (int $min): bool => $origin?->idsLevel !== null && $origin->idsLevel >= $min],
        ];

        foreach ($checks as [$wanted, $test]) {
            if ($wanted !== null && ! $test($wanted)) {
                return false;
            }
        }

        return true;
    }

    /** "Kernel Log (AuraOS)", "Memory Dump (grade 1 or higher)". */
    private function describe(DesignComponent $line): string
    {
        $parts = [];

        if ($line->grade_min !== null && $line->grade_max !== null) {
            $parts[] = Text::get('crafting.filter.grade_between', ['min' => $line->grade_min, 'max' => $line->grade_max]);
        } elseif ($line->grade_min !== null) {
            $parts[] = Text::get('crafting.filter.grade_min', ['grade' => $line->grade_min]);
        } elseif ($line->grade_max !== null) {
            $parts[] = Text::get('crafting.filter.grade_max', ['grade' => $line->grade_max]);
        }

        if ($line->os_slug !== null) {
            $parts[] = Skill::query()->where('slug', $line->os_slug)->value('title') ?? $line->os_slug;
        }

        if ($line->owner_id !== null) {
            $parts[] = Entity::query()->whereKey($line->owner_id)->value('name') ?? Text::get('crafting.filter.unknown_owner');
        }

        if ($line->system_type !== null) {
            $parts[] = SystemType::tryFrom($line->system_type)?->label() ?? $line->system_type;
        }

        if ($line->ice_min !== null) {
            $parts[] = Text::get('crafting.filter.ice_min', ['level' => $line->ice_min]);
        }

        if ($line->ids_min !== null) {
            $parts[] = Text::get('crafting.filter.ids_min', ['level' => $line->ids_min]);
        }

        $name = (string) $line->component?->displayName();

        return $parts === [] ? $name : $name.' ('.implode(', ', $parts).')';
    }
}
