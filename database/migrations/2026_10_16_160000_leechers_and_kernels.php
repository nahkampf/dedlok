<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The nine Leechers (three resources, three sizes) and the three Kernels, with the Design Original and Design Copy that
 * build each. Nothing is on the market: they are made, found or traded. A Leecher takes 1 / 3 / 8 of its resource; a
 * Kernel's bonuses are its modifiers against the kernel_* stats, resolved when it founds a fortress. An item whose slug
 * already exists is left exactly as it is, so a copy authored on the live site first is never overwritten, and a
 * component that is not in the game is left out of the recipe.
 */
return new class extends Migration
{
    /** @return list<array<string, mixed>> */
    private function products(): array
    {
        $leech = [
            'small' => [1, 6, 'rare', [['memory-dump', 3], ['cache-file', 2], ['temporary-file', 1], ['cipher-fragment', 2], ['kernel-log', 1]]],
            'medium' => [3, 9, 'ultra_rare', [['memory-dump', 4], ['cache-file', 3], ['temporary-file', 1], ['cipher-fragment', 2], ['software-manifest', 1], ['kernel-log', 2]]],
            'large' => [8, 14, 'ultra_rare', [['memory-dump', 5], ['cache-file', 3], ['temporary-file', 2], ['cipher-fragment', 2], ['software-manifest', 1], ['kernel-log', 2], ['experimental-data', 1]]],
        ];

        $names = [
            'qflops_cost' => ['Tick', 'Parasite', 'Vampiir', 'compute'],
            'memory_cost' => ['Diverter', 'Swipe', 'Stripminer', 'RAM'],
            'storage_size' => ['Diskbleed', 'Thief', 'Poacher', 'storage'],
        ];

        $out = [];

        foreach ($names as $column => [$small, $medium, $large, $what]) {
            foreach (['small' => $small, 'medium' => $medium, 'large' => $large] as $size => $name) {
                [$amount, $hours, $rarity, $recipe] = $leech[$size];

                $out[] = [
                    'slug' => 'leecher-'.strtolower($name), 'name' => $name, 'rarity' => $rarity, 'kind' => 'leecher',
                    'program' => [$column => $amount], 'seconds' => $hours * 3600, 'recipe' => $recipe, 'skill' => null,
                    'flavor' => "A {$size} Leecher. Hang it on a system's core and it quietly siphons {$amount} of that system's {$what} for your fortress, for as long as the host stays up and nobody notices.",
                ];
            }
        }

        $kernel = [
            ['microkernel', 'Microkernel', 'rare', 12, null, [['memory-dump', 2], ['kernel-log', 1], ['cipher-fragment', 1], ['cache-file', 2], ['error-log', 1], ['stack-trace', 1], ['garbage-collection', 1], ['config-file', 1], ['software-manifest', 1], ['security-profile', 1]],
                'The smallest core that will found a data fortress. Spent by running it once on an empty square. It adds nothing to the fortress beyond the fortress itself.'],
            ['standard-kernel', 'Standard Kernel', 'ultra_rare', 18,
                [['op' => 'percentage_of_base', 'amount' => 3, 'target' => ['id' => 'kernel_integrity']], ['op' => 'add', 'amount' => 2, 'target' => ['id' => 'kernel_resistance']]],
                [['memory-dump', 3], ['kernel-log', 2], ['cipher-fragment', 1], ['cache-file', 2], ['error-log', 1], ['stack-trace', 2], ['garbage-collection', 1], ['config-file', 1], ['software-manifest', 1], ['security-profile', 1]],
                'A sturdier core for a data fortress. Spent by running it once on an empty square. The fortress it founds takes a little more punishment and shrugs off a little of every burn.'],
            ['expanded-kernel', 'Expanded Kernel', 'ultra_rare', 24,
                [['op' => 'percentage_of_base', 'amount' => 8, 'target' => ['id' => 'kernel_integrity']], ['op' => 'add', 'amount' => 6, 'target' => ['id' => 'kernel_resistance']], ['op' => 'percentage_of_base', 'amount' => 3, 'target' => ['id' => 'kernel_demand']]],
                [['memory-dump', 3], ['kernel-log', 3], ['cipher-fragment', 2], ['cache-file', 2], ['error-log', 2], ['stack-trace', 2], ['garbage-collection', 1], ['config-file', 1], ['software-manifest', 1], ['security-profile', 2]],
                'The heaviest core a data fortress can be founded on. Spent by running it once on an empty square. It is much harder to burn down, and it wants a little more of everything just to stay online.'],
        ];

        foreach ($kernel as [$slug, $name, $rarity, $hours, $modifiers, $recipe, $flavor]) {
            $out[] = [
                'slug' => $slug, 'name' => $name, 'rarity' => $rarity, 'kind' => 'kernel', 'program' => [],
                'seconds' => $hours * 3600, 'recipe' => $recipe, 'modifiers' => $modifiers, 'flavor' => $flavor,
                'skill' => ['node_administration', 3],
            ];
        }

        return $out;
    }

    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows, and a page of admin list would otherwise start on ours.
        if (app()->runningUnitTests()) {
            return;
        }

        $now = now();

        $item = fn (string $slug, string $name, string $flavor, string $rarity, bool $stackable, ?array $modifiers = null): ?int => DB::table('items')->where('slug', $slug)->exists()
            ? null
            : DB::table('items')->insertGetId([
                'slug' => $slug, 'name' => $name, 'flavor' => $flavor, 'container' => 'data', 'tier' => 1, 'rarity' => $rarity,
                'base_cost' => 0, 'on_market' => false, 'seed_quantity' => 0, 'stackable' => $stackable,
                'modifiers' => $modifiers === null ? null : json_encode($modifiers),
                'created_at' => $now, 'updated_at' => $now,
            ]);

        foreach ($this->products() as $p) {
            $product = $item($p['slug'], $p['name'], $p['flavor'], $p['rarity'], true, $p['modifiers'] ?? null);

            if ($product === null) {
                continue;
            }

            DB::table('programs')->insert([
                'item_id' => $product, 'kind' => $p['kind'], 'qflops_cost' => 0, 'memory_cost' => 0, 'storage_size' => 0,
                ...$p['program'], 'background_process' => false, 'multithreaded' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $skillId = $p['skill'] === null ? null : DB::table('skills')->where('slug', $p['skill'][0])->value('id');

            foreach ([['original', 'Design', false], ['copy', 'Design Copy', true]] as [$which, $label, $singleUse]) {
                $design = $item($p['slug'].'-design-'.$which, "{$p['name']} ({$label})", ($singleUse ? 'A single-use' : 'A multi-use')." template for compiling {$p['name']}.", $p['rarity'], $singleUse);

                if ($design === null) {
                    continue;
                }

                $designId = DB::table('designs')->insertGetId([
                    'item_id' => $design, 'product_item_id' => $product, 'craft_seconds' => $p['seconds'], 'single_use' => $singleUse,
                    'skill_id' => $skillId, 'skill_level' => $skillId === null ? 1 : $p['skill'][1],
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                $position = 0;

                foreach ($p['recipe'] as [$component, $quantity]) {
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
        DB::table('items')->whereIn('slug', collect($this->products())->pluck('slug')->flatMap(
            fn (string $s): array => [$s, $s.'-design-original', $s.'-design-copy'],
        )->all())->delete();
    }
};
