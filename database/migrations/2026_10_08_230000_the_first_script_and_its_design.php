<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first Script and its recipe (CRAFTING.md), placeholders for the author to rename and tune in the item editor:
 * DUMMY_TEMPORARY_PROGRAM, ten minutes, +10% stealth, and the Design Original and Design Copy that build it. Nothing stocks or sells any
 * of them; they are found, made or authored into the world.
 */
return new class extends Migration
{
    private const SCRIPT = 'dummy-temporary-program';

    public function up(): void
    {
        $now = now();

        $item = fn (string $slug, string $name, string $flavor, bool $stackable): int => DB::table('items')->where('slug', $slug)->value('id')
            ?? DB::table('items')->insertGetId([
                'slug' => $slug, 'name' => $name, 'flavor' => $flavor, 'container' => 'data', 'tier' => 1, 'rarity' => 'common',
                'base_cost' => 0, 'on_market' => false, 'seed_quantity' => 0, 'stackable' => $stackable,
                'created_at' => $now, 'updated_at' => $now,
            ]);

        $script = $item(self::SCRIPT, 'DUMMY_TEMPORARY_PROGRAM', 'PLACEHOLDER. A few minutes of being harder to notice, and then nothing.', true);

        DB::table('programs')->updateOrInsert(['item_id' => $script], [
            'kind' => 'script', 'qflops_cost' => 0.4, 'memory_cost' => 0.4, 'storage_size' => 0.4, 'lifetime_seconds' => 600,
            'background_process' => true, 'multithreaded' => false,
            'modifiers' => json_encode([['op' => 'percentage_of_base', 'amount' => 10, 'target' => ['id' => 'stealth']]]),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ([['original', 'Design Original', false], ['copy', 'Design Copy', true]] as [$kind, $label, $singleUse]) {
            $design = $item(self::SCRIPT.'-design-'.$kind, "DUMMY_TEMPORARY_PROGRAM ({$label})", 'PLACEHOLDER. A recipe.', $singleUse);

            DB::table('designs')->updateOrInsert(['item_id' => $design], [
                'product_item_id' => $script, 'craft_seconds' => 600, 'single_use' => $singleUse, 'skill_id' => null, 'skill_level' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $designId = DB::table('designs')->where('item_id', $design)->value('id');
            DB::table('design_components')->where('design_id', $designId)->delete();

            $position = 0;

            foreach ([
                ['memory-dump', 3, ['grade_min' => 1]],
                ['kernel-log', 1, ['os_slug' => 'auroos']],
                ['temporary-file', 2, []],
                ['cache-file', 1, []],
            ] as [$component, $quantity, $filters]) {
                $componentId = DB::table('items')->where('slug', $component)->value('id');

                if ($componentId === null) {
                    continue;
                }

                DB::table('design_components')->insert([
                    'design_id' => $designId, 'position' => ++$position, 'component_item_id' => $componentId, 'quantity' => $quantity,
                    'grade_min' => null, 'grade_max' => null, 'os_slug' => null, 'owner_id' => null, 'system_type' => null, 'ice_min' => null, 'ids_min' => null,
                    ...$filters,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('items')->where('slug', 'like', self::SCRIPT.'%')->delete();
    }
};
