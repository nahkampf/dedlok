<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Decks\Hardware;
use App\Decks\HardwareEditor;
use App\Decks\SlotLayout;
use App\Decks\Variant;
use App\Market\Rarity;
use App\Models\Item;
use App\Models\Program;
use App\Models\Skill;
use App\Programs\ProgramKind;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'flavor' => null,
            'container' => fake()->randomElement(Item::CONTAINERS),
            'tier' => fake()->numberBetween(1, Item::MAX_TIER),
            // Not randomised, unlike tier. Rarity decides whether a restock
            // pass delivers anything, so a random one would make any test
            // that touches the market roll dice it never asked to roll.
            'rarity' => Rarity::Common,
            'stackable' => true,
            'use_op' => null,
            'use_skill_id' => null,
            'weight' => fake()->randomFloat(2, 0, 20),
            // Not randomised either, for rarity's reason: a data item's size
            // is what the deck's storage is asked about, so a random one
            // would make any test that grants a data item roll whether it
            // fits. A test about size says `->size()`.
            'size' => 0,
            'capacity' => 0,
            'accepts' => null,
            'hardware' => null,
            'base_cost' => fake()->numberBetween(10, 50_000),
            'on_market' => true,
            // Nothing the market stocks unless a test says so, matching the
            // column default: an item is listed before it is stocked.
            'seed_quantity' => 0,
        ];
    }

    public function physical(): static
    {
        return $this->state(['container' => Item::CONTAINER_PHYSICAL]);
    }

    public function data(): static
    {
        return $this->state(['container' => Item::CONTAINER_DATA]);
    }

    public function tier(int $tier): static
    {
        return $this->state(['tier' => $tier]);
    }

    public function rarity(Rarity $rarity): static
    {
        return $this->state(['rarity' => $rarity]);
    }

    /** Something the market carries, and how many a full shelf is. */
    public function restocks(int $seedQuantity): static
    {
        return $this->state(['seed_quantity' => $seedQuantity, 'on_market' => true]);
    }

    public function offMarket(): static
    {
        return $this->state(['on_market' => false]);
    }

    /**
     * A container: something other items go inside.
     *
     * Unstackable by default, because two of them are two objects the moment
     * either has anything on it — which is the whole reason nesting forced
     * per-instance rows in the first place.
     */
    public function holds(string $accepts, int $capacity = 64): static
    {
        return $this->state([
            'capacity' => $capacity,
            'accepts' => $accepts,
            'stackable' => false,
        ]);
    }

    /**
     * A cyberdeck, with the sockets $counts asks for — keyed the way the
     * editor keys them, so a test reads like the form does.
     *
     * The sockets are written through App\Decks\HardwareEditor rather than
     * inserted here, so a test is exercising the same writer the editor uses
     * rather than a second one that agrees with it today.
     *
     * @param  array<string, int>  $counts
     */
    public function cyberdeck(array $counts = ['cpu_socket_k' => 1, 'psu' => 1]): static
    {
        return $this
            ->state([
                'hardware' => Hardware::Deck,
                'container' => Item::CONTAINER_PHYSICAL,
                // Two decks are two objects the moment either has anything
                // fitted in it. Same reasoning as a container.
                'stackable' => false,
            ])
            ->afterCreating(function (Item $item) use ($counts): void {
                HardwareEditor::apply($item, Hardware::Deck, SlotLayout::fromCounts($counts));
            });
    }

    /** A module: what it is, which socket it wants, and what it is worth. */
    public function module(
        Hardware $kind,
        ?Variant $variant = null,
        int $measure = 100,
        int $draw = 10,
        int $thermalLoad = 0,
        ?int $warnAt = null,
        ?int $dieAt = null,
    ): static {
        return $this
            ->state([
                'hardware' => $kind,
                'container' => Item::CONTAINER_PHYSICAL,
                // A fitting points at an inventory ROW, and a row holding two
                // sticks cannot be pointed at. Same rule as a cyberdeck, one
                // step earlier — see App\Decks\ModuleFitter.
                'stackable' => false,
            ])
            ->afterCreating(function (Item $item) use ($kind, $variant, $measure, $draw, $thermalLoad, $warnAt, $dieAt): void {
                $columns = [
                    'variant' => $variant,
                    'power_draw' => $kind->draws() ? $draw : 0,
                    // A radiator makes none, whatever a caller asks for —
                    // chk_deck_modules_thermal.
                    'thermal_load' => $kind->heats() ? $thermalLoad : 0,
                    // Nullable together: null is a module with no known limit.
                    'thermal_warning' => $dieAt === null ? null : $warnAt,
                    'thermal_limit' => $dieAt,
                ];

                $column = $kind->measure();

                if ($column !== null) {
                    $columns[$column] = $measure;
                }

                HardwareEditor::apply($item, $kind, SlotLayout::empty(), $columns);
            });
    }

    public function size(int $size): static
    {
        return $this->state(['size' => $size]);
    }

    public function unstackable(): static
    {
        return $this->state(['stackable' => false]);
    }

    /** An imprint that teaches one skill. Always data: it is injected, not carried. */
    public function imprintFor(Skill $skill): static
    {
        return $this->state([
            'slug' => "imprint_{$skill->slug}",
            'container' => Item::CONTAINER_DATA,
            'use_op' => Item::USE_INJECT_SKILL,
            'use_skill_id' => $skill->id,
        ]);
    }

    /**
     * A program: an item with a `programs` row beside it.
     *
     * The software mirror of module(). Costs default to something small and
     * plausible so a test that only cares about modifiers need not invent
     * three numbers it has no opinion about.
     *
     * @param  list<array<string, mixed>>  $modifiers
     */
    public function program(
        ProgramKind $kind,
        int $qflops = 1,
        int $memory = 1,
        int $storage = 1,
        ?bool $background = null,
        array $modifiers = [],
    ): static {
        return $this
            ->state([
                'container' => Item::CONTAINER_DATA,
                'hardware' => null,
                'stackable' => true,
            ])
            ->afterCreating(function (Item $item) use ($kind, $qflops, $memory, $storage, $background, $modifiers): void {
                Program::create([
                    'item_id' => $item->id,
                    'kind' => $kind,
                    'qflops_cost' => $qflops,
                    'memory_cost' => $memory,
                    'storage_size' => $storage,
                    'background_process' => $background ?? $kind->runsInBackgroundByDefault(),
                    'modifiers' => $modifiers === [] ? null : $modifiers,
                ]);
            });
    }
}
