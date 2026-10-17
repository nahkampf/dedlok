<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use App\Programs\ProgramKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first Fortress Network Tap and Transactional Wiretap, each with a Design Original and Design Copy, the skills that gate making them
 * (Network Tap Crafting, Wiretap Crafting: rank 8, off the market, behind Code Crafting 5 and Node Administration 3, each level 3 per cent off
 * crafting time and 5 per cent off the cycle), and Transactional Skimming (+3 per cent Wiretap yield a level). Anything whose slug exists is left alone.
 */
return new class extends Migration
{
    /** @return list<array<string, mixed>> */
    private function definitions(): array
    {
        return [
            [
                'skill' => 'network_tap_crafting', 'title' => 'Network Tap Crafting', 'kind' => 'fortress_network_tap', 'stat' => 'production_cycle_minutes.tap',
                'description' => 'The assembling and programming of network taps for a Data Fortress, which siphon Encrypted Data Dumps off the nodes around it. Every level shortens the building of it and speeds up the taps you install.',
                'slug' => 'spigot', 'name' => 'Spigot', 'produces' => ['cycle_minutes' => 120, 'range' => 1, 'strength' => 30], 'demand' => [3.0, 3.0, 1.5], 'seconds' => 12 * 3600,
                'flavor' => 'A Daemon that runs low-level intercepts of local node traffic, capturing automated file transfers. This usually yields Encrypted Data Dumps.',
                'recipe' => [['cipher-fragment', 3], ['config-file', 3], ['memory-dump', 2], ['error-log', 2], ['kernel-log', 1]],
            ],
            [
                'skill' => 'wiretap_crafting', 'title' => 'Wiretap Crafting', 'kind' => 'fortress_wiretap', 'stat' => 'production_cycle_minutes.wiretap',
                'description' => 'The assembling and programming of transactional wiretaps for a Data Fortress, which skim creds off the nodes around it. Every level shortens the building of it and speeds up the wiretaps you install.',
                'slug' => 'tithe', 'name' => 'Tithe', 'produces' => ['cycle_minutes' => 120, 'range' => 1, 'strength' => 28], 'demand' => [3.0, 4.0, 2.0], 'seconds' => 14 * 3600,
                'flavor' => 'A Daemon that runs low-level intercepts of financial transaction data on the local node net, skimming a few cents here and there in a random pattern. This can yield quite large amounts of credits in a high-density, high-tier area.',
                'recipe' => [['cipher-fragment', 3], ['memory-dump', 3], ['config-file', 2], ['security-profile', 1], ['cache-file', 1]],
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

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `programs` DROP CHECK `chk_programs_kind`');
            DB::statement('ALTER TABLE `programs` ADD CONSTRAINT `chk_programs_kind` CHECK (`kind` IN ('.implode(',', array_map(static fn (string $k): string => "'{$k}'", ProgramKind::values())).'))');
        }

        $this->skimming();

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

    private function skimming(): void
    {
        if (Skill::query()->where('slug', 'transactional_skimming')->exists()) {
            return;
        }

        $skill = Skill::query()->create([
            'slug' => 'transactional_skimming', 'title' => 'Transactional Skimming',
            'description' => 'Knowing which transactions to listen to and which cuts nobody will miss. Every level raises the credits your Transactional Wiretaps skim.',
            'category' => 'Systems', 'rank' => 8, 'primary_attribute' => 'intellect', 'secondary_attribute' => 'focus', 'base_cost' => 500, 'on_market' => false,
            'modifiers' => array_map(fn (int $level): array => ['op' => 'add', 'level' => $level, 'amount' => 3, 'target' => ['id' => 'wiretap_yield']], range(1, 5)),
        ]);
        Imprint::ensureFor($skill);
        $requiredId = DB::table('skills')->where('slug', 'node_administration')->value('id');

        if ($requiredId !== null) {
            DB::table('skill_prerequisites')->insert(['skill_id' => $skill->id, 'required_skill_id' => $requiredId, 'required_level' => 3]);
        }
    }

    public function down(): void
    {
        foreach ($this->definitions() as $d) {
            DB::table('items')->whereIn('slug', [$d['slug'], $d['slug'].'-design-original', $d['slug'].'-design-copy', 'imprint_'.$d['skill']])->delete();
            DB::table('skills')->where('slug', $d['skill'])->delete();
        }

        DB::table('items')->where('slug', 'imprint_transactional_skimming')->delete();
        DB::table('skills')->where('slug', 'transactional_skimming')->delete();
    }
};
