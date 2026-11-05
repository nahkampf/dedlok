<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nineteen products' recipes gain lines for components no recipe used (Backup, Customer Record, Research Data, Field Test Data,
 * Parameterized Dataset, Vectorized Dataset), on the Design and the Design Copy alike, appended after the existing lines with no
 * grade, OS or owner filter. A recipe that already has a line for the component is left as it is.
 */
return new class extends Migration
{
    /** @var array<string, list<array{string, int}>> product slug => [component slug, quantity] */
    private const ADDED = [
        'bastion-ice' => [['field-test-data', 1]],
        'bazaar' => [['customer-record', 1]],
        'beurling' => [['parameterized-dataset', 1]],
        'coremod_isolator' => [['research-data', 1], ['field-test-data', 1]],
        'leecher-diskbleed' => [['backup', 1]],
        'leecher-diverter' => [['customer-record', 1]],
        'expanded-kernel' => [['parameterized-dataset', 1], ['vectorized-dataset', 1]],
        'nomad' => [['research-data', 1], ['field-test-data', 1]],
        'pacemaker' => [['research-data', 1], ['field-test-data', 1], ['parameterized-dataset', 1]],
        'leecher-parasite' => [['research-data', 1], ['field-test-data', 1], ['parameterized-dataset', 1]],
        'leecher-poacher' => [['research-data', 1], ['field-test-data', 1], ['parameterized-dataset', 1], ['vectorized-dataset', 1]],
        'secring_ablater' => [['research-data', 1]],
        'leecher-swipe' => [['customer-record', 1]],
        'leecher-thief' => [['customer-record', 1]],
        'leecher-tick' => [['customer-record', 1]],
        'tithe' => [['research-data', 1], ['customer-record', 1], ['parameterized-dataset', 1]],
        'leecher-vampiir' => [['customer-record', 2], ['research-data', 1]],
        'veil' => [['research-data', 1], ['field-test-data', 1]],
        'wormhole' => [['research-data', 1], ['field-test-data', 1], ['parameterized-dataset', 1], ['vectorized-dataset', 1]],
    ];

    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows.
        if (app()->runningUnitTests()) {
            return;
        }

        foreach (self::ADDED as $product => $lines) {
            foreach ($this->designsOf($product) as $design) {
                foreach ($lines as [$component, $quantity]) {
                    $componentId = DB::table('items')->where('slug', $component)->value('id');

                    if ($componentId === null || DB::table('design_components')->where('design_id', $design)->where('component_item_id', $componentId)->exists()) {
                        continue;
                    }

                    DB::table('design_components')->insert([
                        'design_id' => $design, 'position' => (int) DB::table('design_components')->where('design_id', $design)->max('position') + 1,
                        'component_item_id' => $componentId, 'quantity' => $quantity,
                        'grade_min' => null, 'grade_max' => null, 'os_slug' => null, 'owner_id' => null, 'system_type' => null, 'ice_min' => null, 'ids_min' => null,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::ADDED as $product => $lines) {
            $components = DB::table('items')->whereIn('slug', array_column($lines, 0))->pluck('id');

            DB::table('design_components')->whereIn('design_id', $this->designsOf($product))->whereIn('component_item_id', $components)->delete();
        }
    }

    /** @return list<int> */
    private function designsOf(string $product): array
    {
        return DB::table('designs')
            ->whereIn('product_item_id', DB::table('items')->where('slug', $product)->select('id'))
            ->pluck('id')
            ->all();
    }
};
