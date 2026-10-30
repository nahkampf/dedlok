<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SecRing Ablater and CoreMod Isolator: two craftable Utility programs that make an operating system's core easier to open
 * while they run, each with its Design Original and Design Copy. No gate skill: a Utility is built with Code Crafting alone
 * (`crafting.ungated`), and the design asks for Code Crafting 5.
 *
 * The Ablater eases AuraOS and Prism-V by 10%, the Isolator IRONCLAD and Lattice by 12%: a percentage of the difficulty
 * (`os_difficulty.<slug>`), never rounded. A component that is not in the game is left out of the recipe, and an item whose slug
 * already exists is left as it is.
 */
return new class extends Migration
{
    private const CRAFT_SECONDS = 16 * 3600;

    /** @return list<array<string, mixed>> */
    private function catalogue(): array
    {
        return [
            [
                'slug' => 'secring_ablater', 'name' => 'SecRing Ablater',
                'flavor' => 'A utility that utilizes a large dataset of vulnerabilities, weaknesses, common misconfigurations etc for Security Ring-type Core protections like those found in AuraOS and Prism-V. Utilizing this an intrusion run can become significantly easier to pull off.',
                'qflops' => 3.0, 'memory' => 6.0, 'storage' => 6.0,
                'eases' => ['auroos' => -10, 'prismv' => -10],
                // 4 AuraOS and 4 Prism-V by quantity.
                'recipe' => [['kernel-log', 2, 'auroos'], ['kernel-log', 2, 'prismv'], ['config-file', 1, 'auroos'], ['config-file', 1, 'prismv'], ['stack-trace', 1, 'auroos'], ['stack-trace', 1, 'prismv']],
            ],
            [
                'slug' => 'coremod_isolator', 'name' => 'CoreMod Isolator',
                'flavor' => 'A utility that employs a limited machine learning model trained on various attack vectors and common vulnerabilities in the core protection systems of OSes that employ a modularized core, such as IRONCLAD and Lattice. Using this can significantly decrease the difficulty of attempting a run against such systems.',
                'qflops' => 6.0, 'memory' => 9.0, 'storage' => 16.0,
                'eases' => ['ironclad' => -12, 'lattice' => -12],
                // 6 IRONCLAD and 2 Lattice by quantity.
                'recipe' => [['kernel-log', 3, 'ironclad'], ['error-log', 2, 'ironclad'], ['stack-trace', 1, 'ironclad'], ['kernel-log', 2, 'lattice']],
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

        $item = fn (string $slug, string $name, string $flavor, bool $stackable): int => DB::table('items')->insertGetId([
            'slug' => $slug, 'name' => $name, 'flavor' => $flavor, 'container' => 'data', 'tier' => 1, 'rarity' => 'rare',
            'base_cost' => 0, 'on_market' => false, 'seed_quantity' => 0, 'stackable' => $stackable,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ($this->catalogue() as $def) {
            if (DB::table('items')->where('slug', $def['slug'])->exists()) {
                continue;
            }

            $product = $item($def['slug'], $def['name'], $def['flavor'], true);

            DB::table('programs')->insert([
                'item_id' => $product, 'kind' => 'utility', 'qflops_cost' => $def['qflops'], 'memory_cost' => $def['memory'], 'storage_size' => $def['storage'],
                'background_process' => true, 'multithreaded' => false,
                'modifiers' => json_encode(array_map(
                    static fn (string $os, int $amount): array => ['op' => 'percentage_of_base', 'amount' => $amount, 'target' => ['id' => 'os_difficulty.'.$os]],
                    array_keys($def['eases']),
                    array_values($def['eases']),
                )),
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $codeCrafting = DB::table('skills')->where('slug', 'code_crafting')->value('id');

            foreach ([['original', 'Design', false], ['copy', 'Design Copy', true]] as [$which, $label, $singleUse]) {
                $design = $item(
                    $def['slug'].'-design-'.$which,
                    "{$def['name']} ({$label})",
                    ($singleUse ? 'A single-use' : 'A multi-use')." template for compiling {$def['name']}.",
                    $singleUse,
                );

                $designId = DB::table('designs')->insertGetId([
                    'item_id' => $design, 'product_item_id' => $product, 'craft_seconds' => self::CRAFT_SECONDS, 'single_use' => $singleUse,
                    'skill_id' => $codeCrafting, 'skill_level' => 5, 'created_at' => $now, 'updated_at' => $now,
                ]);

                $position = 0;

                foreach ($def['recipe'] as [$component, $quantity, $os]) {
                    $componentId = DB::table('items')->where('slug', $component)->value('id');

                    if ($componentId === null) {
                        continue;
                    }

                    DB::table('design_components')->insert([
                        'design_id' => $designId, 'position' => ++$position, 'component_item_id' => $componentId, 'quantity' => $quantity,
                        'grade_min' => null, 'grade_max' => null, 'os_slug' => $os, 'owner_id' => null, 'system_type' => null, 'ice_min' => null, 'ids_min' => null,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $slugs = [];

        foreach ($this->catalogue() as $def) {
            array_push($slugs, $def['slug'], $def['slug'].'-design-original', $def['slug'].'-design-copy');
        }

        DB::table('items')->whereIn('slug', $slugs)->delete();
    }
};
