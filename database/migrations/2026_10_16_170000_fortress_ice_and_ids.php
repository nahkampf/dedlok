<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fortress ICE Crafting and Fortress IDS Crafting (the skills that gate making them), and the two products with their
 * Design Original and Design Copy: Bastion ICE (`fortress_ice`) and Watchdog IDS (`fortress_ids`). Both skills are off the
 * market. A skill or item whose slug already exists is left exactly as it is, so one authored on the live site first is never
 * overwritten, and a component that is not in the game is left out of the recipe. The defence's own numbers (attack,
 * integrity, detection) are `cyberspace.fortress.defence`, scaled by the maker's quality.
 */
return new class extends Migration
{
    /** @return list<array<string, mixed>> */
    private function definitions(): array
    {
        return [
            [
                'key' => 'ice', 'skill' => 'fortress_ice_crafting', 'title' => 'Fortress ICE Crafting',
                'description' => 'The assembling and programming of ICE for a Data Fortress, software that fights back against whoever is inside. Better makers turn out harder ICE.',
                'slug' => 'bastion-ice', 'name' => 'Bastion ICE', 'kind' => 'fortress_ice', 'demand' => [6.0, 6.0, 6.0], 'seconds' => 16 * 3600,
                'flavor' => 'Counter-intrusion software for a data fortress, written to hold a core against a runner who has already got in. It is heavy to run and it decays without upkeep.',
                'recipe' => [['memory-dump', 4], ['security-profile', 2], ['stack-trace', 2], ['cipher-fragment', 2], ['error-log', 1]],
            ],
            [
                'key' => 'ids', 'skill' => 'fortress_ids_crafting', 'title' => 'Fortress IDS Crafting',
                'description' => 'The assembling and programming of intrusion detection for a Data Fortress, the software that notices a runner and raises the alarm. Better makers turn out sharper detection.',
                'slug' => 'watchdog-ids', 'name' => 'Watchdog IDS', 'kind' => 'fortress_ids', 'demand' => [3.0, 3.0, 1.5], 'seconds' => 10 * 3600,
                'flavor' => 'Intrusion detection for a data fortress: it watches the logs and the configs and tells the owner somebody is in. It is cheap to run, and the first thing to go in a shortage.',
                'recipe' => [['memory-dump', 3], ['error-log', 2], ['config-file', 2], ['garbage-collection', 1], ['security-profile', 1]],
            ],
        ];
    }

    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows, and a page of admin list would otherwise start on ours.
        if (app()->runningUnitTests()) {
            return;
        }

        $now = now();

        foreach ($this->definitions() as $d) {
            if (! Skill::query()->where('slug', $d['skill'])->exists()) {
                $skill = Skill::query()->create([
                    'slug' => $d['skill'], 'title' => $d['title'], 'description' => $d['description'], 'category' => 'Systems', 'rank' => 8,
                    'primary_attribute' => 'intellect', 'secondary_attribute' => 'focus', 'base_cost' => 500, 'on_market' => false,
                    'modifiers' => array_merge(...array_map(fn (int $level): array => [
                        ['op' => 'percentage_of_base', 'level' => $level, 'amount' => -3, 'target' => ['id' => 'crafting_seconds.'.$d['kind']]],
                        ['op' => 'add', 'level' => $level, 'amount' => 5, 'target' => ['id' => 'defence_quality.'.$d['key']]],
                    ], range(1, 5))),
                ]);

                Imprint::ensureFor($skill);

                foreach (['cyberdecks', 'code_crafting'] as $required) {
                    $requiredId = DB::table('skills')->where('slug', $required)->value('id');

                    if ($requiredId !== null) {
                        DB::table('skill_prerequisites')->insert(['skill_id' => $skill->id, 'required_skill_id' => $requiredId, 'required_level' => 5]);
                    }
                }
            }

            if (DB::table('items')->where('slug', $d['slug'])->exists()) {
                continue;
            }

            $item = fn (string $slug, string $name, string $flavor, bool $stackable): int => DB::table('items')->insertGetId([
                'slug' => $slug, 'name' => $name, 'flavor' => $flavor, 'container' => 'data', 'tier' => 1, 'rarity' => 'rare',
                'base_cost' => 0, 'on_market' => false, 'seed_quantity' => 0, 'stackable' => $stackable,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $product = $item($d['slug'], $d['name'], $d['flavor'], false);

            [$compute, $memory, $storage] = $d['demand'];

            DB::table('programs')->insert([
                'item_id' => $product, 'kind' => $d['kind'], 'qflops_cost' => $compute, 'memory_cost' => $memory, 'storage_size' => $storage,
                'background_process' => false, 'multithreaded' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach ([['original', 'Design', false], ['copy', 'Design Copy', true]] as [$which, $label, $singleUse]) {
                $design = $item($d['slug'].'-design-'.$which, "{$d['name']} ({$label})", ($singleUse ? 'A single-use' : 'A multi-use')." template for compiling {$d['name']}.", $singleUse);

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
            DB::table('items')->whereIn('slug', [$d['slug'], $d['slug'].'-design-original', $d['slug'].'-design-copy', 'imprint_'.$d['skill']])->delete();
            DB::table('skills')->where('slug', $d['skill'])->delete();
        }
    }
};
