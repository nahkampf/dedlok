<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Wormhole (`tunnel`, Secure Tunnelling), a Data Fortress's utility program, with a Design Original and Design Copy. Made with
 * Utility Crafting, rare, off the market. The craft time and recipe are the Nomad's. An item whose slug already exists is left exactly as it is, and a component that is not in
 * the game is left out of the recipe.
 */
return new class extends Migration
{
    private const SLUG = 'wormhole';

    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows.
        if (app()->runningUnitTests() || DB::table('items')->where('slug', self::SLUG)->exists()) {
            return;
        }

        $now = now();

        $item = fn (string $slug, string $name, bool $stackable, ?string $flavor = null): int => DB::table('items')->insertGetId([
            'slug' => $slug, 'name' => $name, 'flavor' => $flavor, 'container' => 'data', 'tier' => 1, 'rarity' => 'rare',
            'base_cost' => 0, 'on_market' => false, 'seed_quantity' => 0, 'stackable' => $stackable,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $product = $item(self::SLUG, 'Wormhole', true, 'Establishes a point-to-point secure tunnel to the main grid, effectively bypassing the Gateway into this subnet.');

        DB::table('programs')->insert([
            'item_id' => $product, 'kind' => 'tunnel', 'qflops_cost' => 1.0, 'memory_cost' => 1.0, 'storage_size' => 1.0,
            'background_process' => false, 'multithreaded' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ([['original', 'Design', false], ['copy', 'Design Copy', true]] as [$which, $label, $singleUse]) {
            $design = $item(self::SLUG.'-design-'.$which, "Wormhole ({$label})", $singleUse, ($singleUse ? 'A single-use' : 'A multi-use').' template for compiling Wormhole.');

            $designId = DB::table('designs')->insertGetId([
                'item_id' => $design, 'product_item_id' => $product, 'craft_seconds' => 14 * 3600, 'single_use' => $singleUse,
                'skill_id' => null, 'skill_level' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $position = 0;

            foreach ([['memory-dump', 3], ['kernel-log', 2], ['stack-trace', 2], ['config-file', 2], ['garbage-collection', 1]] as [$component, $quantity]) {
                $componentId = DB::table('items')->where('slug', $component)->value('id');

                if ($componentId === null) {
                    continue;
                }

                DB::table('design_components')->insert([
                    'design_id' => $designId, 'position' => ++$position, 'component_item_id' => $componentId, 'quantity' => $quantity,
                    'grade_min' => null, 'grade_max' => null, 'os_slug' => null, 'owner_id' => null, 'system_type' => null, 'ice_min' => null, 'ids_min' => null,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('items')->whereIn('slug', [self::SLUG, self::SLUG.'-design-original', self::SLUG.'-design-copy'])->delete();
    }
};
