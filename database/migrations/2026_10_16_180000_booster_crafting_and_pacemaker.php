<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Booster Crafting (the skill that gates making Boosters, off the market) and the first Booster, the Pacemaker, with its Design
 * Original and Design Copy. A skill or item whose slug already exists is left exactly as it is, and a component that is not in the
 * game is left out of the recipe. The boost (25 per cent off every running producer's cycle) is on the program; each copy is
 * stamped with it scaled by the maker's `defence_quality.booster` when it is crafted.
 */
return new class extends Migration
{
    private const SKILL = 'booster_crafting';

    private const SLUG = 'pacemaker';

    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows, and a page of admin list would otherwise start on ours.
        if (app()->runningUnitTests()) {
            return;
        }

        $now = now();

        if (! Skill::query()->where('slug', self::SKILL)->exists()) {
            $skill = Skill::query()->create([
                'slug' => self::SKILL, 'title' => 'Booster Crafting',
                'description' => 'The assembling and programming of Boosters for a Data Fortress, the helpers that make its producers finish their cycles sooner. Better makers turn out stronger Boosters.',
                'category' => 'Systems', 'rank' => 8, 'primary_attribute' => 'intellect', 'secondary_attribute' => 'focus', 'base_cost' => 500, 'on_market' => false,
                'modifiers' => array_merge(...array_map(fn (int $level): array => [
                    ['op' => 'percentage_of_base', 'level' => $level, 'amount' => -3, 'target' => ['id' => 'crafting_seconds.booster']],
                    ['op' => 'add', 'level' => $level, 'amount' => 2, 'target' => ['id' => 'defence_quality.booster']],
                ], range(1, 5))),
            ]);

            Imprint::ensureFor($skill);

            foreach ([['code_crafting', 5], ['node_administration', 3]] as [$required, $level]) {
                $requiredId = DB::table('skills')->where('slug', $required)->value('id');

                if ($requiredId !== null) {
                    DB::table('skill_prerequisites')->insert(['skill_id' => $skill->id, 'required_skill_id' => $requiredId, 'required_level' => $level]);
                }
            }
        }

        if (DB::table('items')->where('slug', self::SLUG)->exists()) {
            return;
        }

        $item = fn (string $slug, string $name, string $flavor, bool $stackable): int => DB::table('items')->insertGetId([
            'slug' => $slug, 'name' => $name, 'flavor' => $flavor, 'container' => 'data', 'tier' => 1, 'rarity' => 'rare',
            'base_cost' => 0, 'on_market' => false, 'seed_quantity' => 0, 'stackable' => $stackable,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $product = $item(self::SLUG, 'Pacemaker', 'The Pacemaker is a daemon that sits between producers and the kernel, optimizing work flows, doing pre-calculations and serving as a lookup cache, which drastically cuts down processing time for production cycles.', false);

        DB::table('programs')->insert([
            'item_id' => $product, 'kind' => 'booster', 'qflops_cost' => 3.0, 'memory_cost' => 3.0, 'storage_size' => 1.5,
            'background_process' => false, 'multithreaded' => false, 'produces' => json_encode(['boost' => 25]),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ([['original', 'Design', false], ['copy', 'Design Copy', true]] as [$which, $label, $singleUse]) {
            $design = $item(self::SLUG.'-design-'.$which, "Pacemaker ({$label})", ($singleUse ? 'A single-use' : 'A multi-use').' template for compiling a Pacemaker.', $singleUse);

            $designId = DB::table('designs')->insertGetId([
                'item_id' => $design, 'product_item_id' => $product, 'craft_seconds' => 10 * 3600, 'single_use' => $singleUse,
                'skill_id' => null, 'skill_level' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $position = 0;

            foreach ([['temporary-file', 3], ['cache-file', 3], ['memory-dump', 2], ['config-file', 1], ['garbage-collection', 1]] as [$component, $quantity]) {
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
        DB::table('items')->whereIn('slug', [self::SLUG, self::SLUG.'-design-original', self::SLUG.'-design-copy', 'imprint_'.self::SKILL])->delete();
        DB::table('skills')->where('slug', self::SKILL)->delete();
    }
};
