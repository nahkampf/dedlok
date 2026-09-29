<?php

declare(strict_types=1);

use App\Decks\Hardware;
use App\Decks\HardwareEditor;
use App\Decks\SlotLayout;
use App\Decks\Variant;
use App\Models\Item;
use Illuminate\Database\Migrations\Migration;

/**
 * Two expansion cards, authored on localhost 2026-09-29, carried to
 * production — the first content ever to fill an expansion socket.
 *
 * Both are `icebreaker_damage` cards, distinct in variant and tier: BlackBox
 * II is XGate, common ground and the cheaper of the two; Pandora SC-652 is
 * Pei, a tier up in every sense. Upserted by slug, so the laptop that made
 * them is left unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->card(
            slug: 'blackbox_ii',
            name: 'BlackBox II',
            flavor: 'A commercially available "genie in a box" - a self contained, highly specialized chipset and '
                .'machine learning protocols designed for penetration testing systems. Can of course also be used '
                .'by blackhats to gain an extra edge on ICE.',
            tier: 1,
            rarity: 'uncommon',
            baseCost: 6000,
            seedQuantity: 2,
            variant: Variant::XGate,
            powerDraw: 6,
            thermalLoad: 3,
            modifiers: [
                ['op' => 'percentage_of_base', 'amount' => 6, 'target' => ['id' => 'icebreaker_damage']],
            ],
        );

        $this->card(
            slug: 'pandora_sc652',
            name: 'Pandora SC-652',
            flavor: 'A feedback expansion system that handles real-time threat analysis, fuzzing of attack '
                .'vectors, incremental exploit construction etc. Essentially a sidekick to any icebreaker, '
                .'increasing its potential.',
            tier: 2,
            rarity: 'rare',
            baseCost: 9000,
            seedQuantity: 1,
            variant: Variant::Pei,
            powerDraw: 9,
            thermalLoad: 7,
            modifiers: [
                ['op' => 'percentage_of_base', 'amount' => 9, 'target' => ['id' => 'icebreaker_damage']],
            ],
        );
    }

    public function down(): void
    {
        Item::query()->whereIn('slug', ['blackbox_ii', 'pandora_sc652'])->delete();
    }

    /** @param  list<array<string, mixed>>  $modifiers */
    private function card(
        string $slug,
        string $name,
        string $flavor,
        int $tier,
        string $rarity,
        int $baseCost,
        int $seedQuantity,
        Variant $variant,
        int $powerDraw,
        int $thermalLoad,
        array $modifiers,
    ): void {
        $item = Item::query()->updateOrCreate(['slug' => $slug], [
            'name' => $name,
            'flavor' => $flavor,
            'container' => 'data',
            'tier' => $tier,
            'rarity' => $rarity,
            'stackable' => true,
            'hardware' => Hardware::Expansion,
            'weight' => 1.0,
            'size' => 1,
            'base_cost' => $baseCost,
            'on_market' => true,
            'seed_quantity' => $seedQuantity,
            'modifiers' => $modifiers,
        ]);

        HardwareEditor::apply($item, Hardware::Expansion, SlotLayout::empty(), [
            'variant' => $variant->value,
            'power_draw' => $powerDraw,
            'thermal_load' => $thermalLoad,
        ]);
    }
};
