<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lockjaw's shelf gains a Design Copy (75 loyalty) and a Design (300) for the Nomad, the Wormhole and every Leecher. All of them are
 * kept by desks of grade 1 and up and ask 2 standing, except the large Leechers (Poacher, Stripminer, Vampiir): desks of grade 4 and
 * up, standing 4. An offer whose design is not in the catalogue is skipped.
 */
return new class extends Migration
{
    private const LARGE = ['leecher-poacher', 'leecher-stripminer', 'leecher-vampiir'];

    /** @return list<string> */
    private function products(): array
    {
        return ['nomad', 'wormhole', 'leecher-diskbleed', 'leecher-diverter', 'leecher-tick', 'leecher-parasite', 'leecher-swipe', 'leecher-thief', ...self::LARGE];
    }

    public function up(): void
    {
        $entity = DB::table('entities')->where('name', 'Lockjaw')->value('id');

        if ($entity === null) {
            return;
        }

        $now = now();

        foreach ($this->products() as $product) {
            $large = in_array($product, self::LARGE, true);

            foreach (['copy' => 75, 'original' => 300] as $which => $price) {
                $item = DB::table('items')->where('slug', "{$product}-design-{$which}")->value('id');

                if ($item === null) {
                    continue;
                }

                DB::table('entity_offers')->insertOrIgnore([
                    'entity_id' => $entity, 'item_id' => $item, 'min_grade' => $large ? 4 : 1, 'max_grade' => 5,
                    'loyalty_price' => $price, 'min_standing' => $large ? 4 : 2, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $entity = DB::table('entities')->where('name', 'Lockjaw')->value('id');
        $slugs = collect($this->products())->flatMap(fn (string $p): array => ["{$p}-design-copy", "{$p}-design-original"])->all();

        DB::table('entity_offers')->where('entity_id', $entity)->whereIn('item_id', DB::table('items')->whereIn('slug', $slugs)->select('id'))->delete();
    }
};
