<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Bazaar (`market`) and the Nomad (`relocation`), a Data Fortress's utility programs, each with a Design Original and Design
 * Copy. Both are made with Utility Crafting, rare, off the market. An item whose slug already exists is left exactly as it is, and
 * a component that is not in the game is left out of the recipe.
 */
return new class extends Migration
{
    /** @return list<array<string, mixed>> */
    private function definitions(): array
    {
        return [
            [
                'slug' => 'bazaar', 'name' => 'Bazaar', 'kind' => 'market', 'demand' => [3.0, 3.0, 1.5], 'seconds' => 10 * 3600,
                'flavor' => 'Runs a public-facing storefront, data delivery and payment processing, allowing a Data Fortress to act as a trading hub.',
                'recipe' => [['config-file', 3], ['memory-dump', 2], ['cache-file', 2], ['security-profile', 2], ['error-log', 1]],
            ],
            [
                'slug' => 'nomad', 'name' => 'Nomad', 'kind' => 'relocation', 'demand' => [3.0, 4.0, 2.0], 'seconds' => 14 * 3600,
                'flavor' => 'Relocates the entire node to a new position on the cyberspace grid at a set interval, in order to make the Data Fortress more secure.',
                'recipe' => [['memory-dump', 3], ['kernel-log', 2], ['stack-trace', 2], ['config-file', 2], ['garbage-collection', 1]],
            ],
        ];
    }

    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows.
        if (app()->runningUnitTests()) {
            return;
        }

        $now = now();

        foreach ($this->definitions() as $d) {
            if (DB::table('items')->where('slug', $d['slug'])->exists()) {
                continue;
            }

            $item = fn (string $slug, string $name, string $flavor, bool $stackable): int => DB::table('items')->insertGetId([
                'slug' => $slug, 'name' => $name, 'flavor' => $flavor, 'container' => 'data', 'tier' => 1, 'rarity' => 'rare',
                'base_cost' => 0, 'on_market' => false, 'seed_quantity' => 0, 'stackable' => $stackable,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $product = $item($d['slug'], $d['name'], $d['flavor'], true);

            [$compute, $memory, $storage] = $d['demand'];

            DB::table('programs')->insert([
                'item_id' => $product, 'kind' => $d['kind'], 'qflops_cost' => $compute, 'memory_cost' => $memory, 'storage_size' => $storage,
                'background_process' => false, 'multithreaded' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach ([['original', 'Design', false], ['copy', 'Design Copy', true]] as [$which, $label, $singleUse]) {
                $design = $item($d['slug'].'-design-'.$which, "{$d['name']} ({$label})", ($singleUse ? 'A single-use' : 'A multi-use')." template for compiling a {$d['name']}.", $singleUse);

                $designId = DB::table('designs')->insertGetId([
                    'item_id' => $design, 'product_item_id' => $product, 'craft_seconds' => $d['seconds'], 'single_use' => $singleUse,
                    'skill_id' => null, 'skill_level' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);

                $position = 0;

                foreach ($d['recipe'] as [$component, $quantity]) {
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
    }

    public function down(): void
    {
        foreach ($this->definitions() as $d) {
            DB::table('items')->whereIn('slug', [$d['slug'], $d['slug'].'-design-original', $d['slug'].'-design-copy'])->delete();
        }
    }
};
