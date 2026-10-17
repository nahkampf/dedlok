<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first Fortress Decryptor (Beurling) and Fortress Assembler (gasm-2.6.1), each with a Design Original and Design Copy, and
 * the skills that gate making them: Fortress Decryptor Crafting (not the older Decryptor Crafting, which gates the player's Decryptors) and Assembler Crafting (off the market, behind Code Crafting 5 and Node
 * Administration 3). Each level takes 3 per cent off its kind's crafting time and 5 per cent off its own cycle stat. A skill or item
 * whose slug exists is left exactly as it is, and a component that is not in the game is left out of the recipe.
 */
return new class extends Migration
{
    /** @return list<array<string, mixed>> */
    private function definitions(): array
    {
        return [
            [
                'skill' => 'fortress_decryptor_crafting', 'title' => 'Fortress Decryptor Crafting', 'kind' => 'fortress_decryptor', 'stat' => 'production_cycle_minutes.decryptor',
                'description' => 'The assembling and programming of decryption software for a Data Fortress, which works through the Encrypted Data Dumps it is fed one after another. Every level shortens the building of it and speeds up the decryptors you install.',
                'slug' => 'beurling', 'name' => 'Beurling', 'produces' => ['cycle_minutes' => 120], 'demand' => [3.0, 3.0, 1.5], 'seconds' => 12 * 3600,
                'flavor' => 'Named after historic swedish mathematician Arne Beurling, this decryptor is the standard tool for many data fortresses that need decryption pipelines. It is fairly fast and reliable, at a relatively low compute cost.',
                'recipe' => [['cipher-fragment', 3], ['memory-dump', 3], ['config-file', 2], ['error-log', 1], ['kernel-log', 1]],
            ],
            [
                'skill' => 'assembler_crafting', 'title' => 'Assembler Crafting', 'kind' => 'fortress_assembler', 'stat' => 'production_cycle_minutes.assembler',
                'description' => 'The assembling and programming of compile pipelines for a Data Fortress, which builds programs from Designs and the components it holds. Every level shortens the building of it and speeds up the assemblers you install.',
                'slug' => 'gasm-2-6-1', 'name' => 'gasm-2.6.1', 'produces' => ['reduction' => 0], 'demand' => [4.0, 4.0, 2.0], 'seconds' => 14 * 3600,
                'flavor' => 'The General Assembler is a mainstay of compile pipelines everywhere. While not the most optimized, it is the most generalized and can handle almost any assembly and compile task across a wide range of platforms and systems.',
                'recipe' => [['stack-trace', 3], ['memory-dump', 3], ['config-file', 2], ['cache-file', 1], ['security-profile', 1]],
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
            if (! Skill::query()->where('slug', $d['skill'])->exists()) {
                $skill = Skill::query()->create([
                    'slug' => $d['skill'], 'title' => $d['title'], 'description' => $d['description'], 'category' => 'Systems', 'rank' => 8,
                    'primary_attribute' => 'intellect', 'secondary_attribute' => 'focus', 'base_cost' => 500, 'on_market' => false,
                    'modifiers' => array_merge(...array_map(fn (int $level): array => [
                        ['op' => 'percentage_of_base', 'level' => $level, 'amount' => -3, 'target' => ['id' => 'crafting_seconds.'.$d['kind']]],
                        ['op' => 'percentage_of_base', 'level' => $level, 'amount' => -5, 'target' => ['id' => $d['stat']]],
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
                'background_process' => false, 'multithreaded' => false, 'produces' => json_encode($d['produces']),
                'created_at' => $now, 'updated_at' => $now,
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
