<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What five entities' exchange desks buy of the components a decrypted data dump turns into: half of each file's own
 * value, at every desk grade, with no limit per window. Entities are found by name and items by slug; a pair that is
 * already listed, or whose entity or item is missing, is left alone, so a listing made in the editor first is never
 * overwritten. Bought files vanish, as everything an exchange buys does.
 */
return new class extends Migration
{
    private const PERCENT = 50;

    /** @var array<string, list<string>> entity name => item slugs */
    private const BUYS = [
        'Bureau of Network Integrity' => ['config-file', 'software-manifest'],
        'Directorate of Revenue Enforcement' => ['customer-record'],
        'Lockjaw' => ['security-profile', 'experimental-data'],
        'Joint Signals Command' => [
            'cipher-fragment', 'config-file', 'backup', 'customer-record', 'software-manifest', 'security-profile',
            'research-data', 'design-document', 'experimental-data', 'field-test-data', 'parameterized-dataset', 'vectorized-dataset',
        ],
        'Golden Path' => ['customer-record', 'project-file', 'research-data'],
    ];

    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows (and calls `seed()` itself).
        if (app()->runningUnitTests()) {
            return;
        }

        $this->seed();
    }

    public function seed(): void
    {
        $now = now();

        foreach (self::BUYS as $name => $slugs) {
            $entity = DB::table('entities')->where('name', $name)->value('id');

            if ($entity === null) {
                continue;
            }

            foreach ($slugs as $slug) {
                $item = DB::table('items')->where('slug', $slug)->value('id');

                if ($item === null || DB::table('entity_buys')->where('entity_id', $entity)->where('item_id', $item)->exists()) {
                    continue;
                }

                DB::table('entity_buys')->insert([
                    'entity_id' => $entity, 'item_id' => $item, 'min_grade' => 1, 'max_grade' => 5,
                    'percent' => self::PERCENT, 'max_quantity' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::BUYS as $name => $slugs) {
            $entity = DB::table('entities')->where('name', $name)->value('id');

            if ($entity === null) {
                continue;
            }

            DB::table('entity_buys')->where('entity_id', $entity)->where('percent', self::PERCENT)
                ->whereIn('item_id', DB::table('items')->whereIn('slug', $slugs)->select('id'))->delete();
        }
    }
};
