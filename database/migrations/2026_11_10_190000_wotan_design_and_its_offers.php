<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wotan's Design and Design Copy, and who sells them.
 *
 * The recipe leans on four components nothing else used (Backup, Customer Record, Project File, Research Data), beside
 * the staples: it fingerprints systems, so it is built from what systems keep about themselves. Eight hours, no skill.
 *
 * The Design Copy (single use) is on the Bureau of Network Integrity's shelf: 150 loyalty, from standing 3, any desk grade.
 * The Design (multi-use) is on Lockjaw's: 450 loyalty, from standing 10, desks of grade 2 and up.
 *
 * Skipped when Wotan is not in the catalogue. An item whose slug already exists is left exactly as it is.
 */
return new class extends Migration
{
    private const PRODUCT = 'wotan';

    /** @var list<array{string, int}> */
    private const RECIPE = [
        ['memory-dump', 3],
        ['kernel-log', 2],
        ['cache-file', 2],
        ['backup', 1],
        ['customer-record', 1],
        ['project-file', 1],
        ['research-data', 1],
    ];

    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows.
        if (app()->runningUnitTests()) {
            return;
        }

        $product = DB::table('items')->where('slug', self::PRODUCT)->first(['id', 'name', 'rarity']);

        if ($product === null) {
            return;
        }

        $now = now();

        foreach ([['original', 'Design', false], ['copy', 'Design Copy', true]] as [$which, $label, $singleUse]) {
            $slug = self::PRODUCT.'-design-'.$which;

            if (DB::table('items')->where('slug', $slug)->exists()) {
                continue;
            }

            $item = DB::table('items')->insertGetId([
                'slug' => $slug, 'name' => "{$product->name} ({$label})",
                'flavor' => ($singleUse ? 'A single-use' : 'A multi-use')." template for compiling {$product->name}.",
                'container' => 'data', 'tier' => 1, 'rarity' => $product->rarity, 'base_cost' => 0, 'on_market' => false, 'seed_quantity' => 0,
                'stackable' => $singleUse, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $design = DB::table('designs')->insertGetId([
                'item_id' => $item, 'product_item_id' => $product->id, 'craft_seconds' => 8 * 3600, 'single_use' => $singleUse,
                'skill_id' => null, 'skill_level' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $position = 0;

            foreach (self::RECIPE as [$component, $quantity]) {
                $componentId = DB::table('items')->where('slug', $component)->value('id');

                if ($componentId === null) {
                    continue;
                }

                DB::table('design_components')->insert([
                    'design_id' => $design, 'position' => ++$position, 'component_item_id' => $componentId, 'quantity' => $quantity,
                    'grade_min' => null, 'grade_max' => null, 'os_slug' => null, 'owner_id' => null, 'system_type' => null, 'ice_min' => null, 'ids_min' => null,
                ]);
            }
        }

        foreach ([
            ['Bureau of Network Integrity', 'copy', 1, 150, 3],
            ['Lockjaw', 'original', 2, 450, 10],
        ] as [$entityName, $which, $minGrade, $price, $standing]) {
            $entity = DB::table('entities')->where('name', $entityName)->value('id');
            $design = DB::table('items')->where('slug', self::PRODUCT.'-design-'.$which)->value('id');

            if ($entity === null || $design === null || DB::table('entity_offers')->where('entity_id', $entity)->where('item_id', $design)->exists()) {
                continue;
            }

            DB::table('entity_offers')->insert([
                'entity_id' => $entity, 'item_id' => $design, 'min_grade' => $minGrade, 'max_grade' => 5,
                'loyalty_price' => $price, 'min_standing' => $standing, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $designs = DB::table('items')->whereIn('slug', [self::PRODUCT.'-design-original', self::PRODUCT.'-design-copy'])->pluck('id');

        DB::table('entity_offers')->whereIn('item_id', $designs)->delete();
        DB::table('items')->whereIn('id', $designs)->delete();
    }
};
