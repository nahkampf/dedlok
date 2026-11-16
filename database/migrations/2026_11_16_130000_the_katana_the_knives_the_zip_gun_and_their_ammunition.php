<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ten items made in the development database that production lacks, with the weapon or ammunition row each carries,
 * and where they are stocked: the Katana, the Switchblade and the Tebbit Knife (blades), the Liquid Knuckles spray and its
 * LK Canister, the Zip Gun and its .22 LR, the Udar-9 and RD-S22P sub machine guns and the Kuroda Lifeframe-300 vest. An item whose slug already exists is left alone, and so is a shop listing that
 * is already there, so this is safe to run on a database that got any of it by hand. A shop whose location is missing is
 * skipped. No schema changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $data = [
            'items' => [
                0 => [
                    'slug' => 'katana',
                    'name' => 'Katana',
                    'flavor' => '"The sight of the bare katana inspires everyone to a practically Nipponese level of politeness."',
                    'tier' => 1,
                    'rarity' => 'rare',
                    'stackable' => 0,
                    'weight' => '1.50',
                    'size' => '2.0',
                    'base_cost' => 850,
                    'on_market' => 0,
                    'seed_quantity' => 3,
                    'weapon' => [
                        'class' => 'blade',
                        'damage' => 14,
                        'damage_profile' => '{"slashing": 90, "penetrating": 10}',
                        'riders' => null,
                        'accuracy' => 0,
                        'rate' => 1,
                        'magazine' => null,
                        'calibre' => null,
                    ],
                ],
                1 => [
                    'slug' => 'lk_canister',
                    'name' => 'LK Canister',
                    'flavor' => 'A single-use canister of aerosol tranquilizer for the Liquid Knuckles spray.',
                    'tier' => 1,
                    'rarity' => 'common',
                    'stackable' => 1,
                    'weight' => '0.50',
                    'size' => '0.5',
                    'base_cost' => 150,
                    'on_market' => 0,
                    'seed_quantity' => 50,
                    'ammunition' => [
                        'calibre' => 'LK Canister',
                        'type' => 'standard',
                        'damage' => 1,
                        'penetration' => 0,
                        'damage_profile' => '{"chemical": 100}',
                        'riders' => '[{"kind": "stun", "turns": 4, "amount": 0, "chance": 75}, {"kind": "poisoned", "turns": 2, "amount": 1, "chance": 5}]',
                    ],
                ],
                2 => [
                    'slug' => 'liquid_knuckles',
                    'name' => 'Liquid Knuckles',
                    'flavor' => 'A highly potent aerosol tranquilizer used for non-lethal personal defense.',
                    'tier' => 1,
                    'rarity' => 'common',
                    'stackable' => 0,
                    'weight' => '0.00',
                    'size' => '0.0',
                    'base_cost' => 450,
                    'on_market' => 0,
                    'seed_quantity' => 25,
                    'weapon' => [
                        'class' => 'pistol',
                        'damage' => null,
                        'damage_profile' => null,
                        'riders' => '[{"kind": "stun", "turns": 4, "amount": 0, "chance": 75}]',
                        'accuracy' => 0,
                        'rate' => 1,
                        'magazine' => 1,
                        'calibre' => 'LK Canister',
                    ],
                ],
                3 => [
                    'slug' => 'switchblade',
                    'name' => 'Switchblade',
                    'flavor' => 'A very useful tool indeed.',
                    'tier' => 1,
                    'rarity' => 'common',
                    'stackable' => 0,
                    'weight' => '0.50',
                    'size' => '0.5',
                    'base_cost' => 200,
                    'on_market' => 0,
                    'seed_quantity' => 25,
                    'weapon' => [
                        'class' => 'blade',
                        'damage' => 9,
                        'damage_profile' => '{"slashing": 25, "penetrating": 75}',
                        'riders' => null,
                        'accuracy' => 0,
                        'rate' => 1,
                        'magazine' => null,
                        'calibre' => null,
                    ],
                ],
                4 => [
                    'slug' => 'tebbit_knife',
                    'name' => 'Tebbit Knife',
                    'flavor' => '"Bioweapon coding. The runnel is coated with C-381. Produces cyanide compounds on contact with haemoglobin. Well away from the edges, so if you cut yourself there’s no problem, but if you sink it in anything with blood…"',
                    'tier' => 2,
                    'rarity' => 'rare',
                    'stackable' => 0,
                    'weight' => '0.50',
                    'size' => '1.0',
                    'base_cost' => 4500,
                    'on_market' => 0,
                    'seed_quantity' => 2,
                    'weapon' => [
                        'class' => 'blade',
                        'damage' => 10,
                        'damage_profile' => '{"slashing": 25, "penetrating": 75}',
                        'riders' => '[{"kind": "poisoned", "turns": 3, "amount": 8, "chance": 75}]',
                        'accuracy' => 0,
                        'rate' => 1,
                        'magazine' => null,
                        'calibre' => null,
                    ],
                ],
                5 => [
                    'slug' => '22',
                    'name' => '.22 LR',
                    'flavor' => 'The humble .22 Long Rifle ammunition might look flimsy and inconsequential but it can still penetrate the cranium and make anyone\'s day real bad. Super cheap and easy to carry.',
                    'tier' => 1,
                    'rarity' => 'common',
                    'stackable' => 1,
                    'weight' => '0.01',
                    'size' => '0.1',
                    'base_cost' => 3,
                    'on_market' => 0,
                    'seed_quantity' => 5000,
                    'ammunition' => [
                        'calibre' => '.22LR',
                        'type' => 'standard',
                        'damage' => 7,
                        'penetration' => 0,
                        'damage_profile' => '{"blunt": 100}',
                        'riders' => null,
                    ],
                ],
                6 => [
                    'slug' => 'zipgun',
                    'name' => 'Zip Gun',
                    'flavor' => 'A crude, home made one-shot gun. Very easy to make, and their low cost make them a favorite in the projects for people needing something simple for self defense.',
                    'tier' => 1,
                    'rarity' => 'common',
                    'stackable' => 0,
                    'weight' => '0.50',
                    'size' => '1.0',
                    'base_cost' => 250,
                    'on_market' => 1,
                    'seed_quantity' => 10,
                    'weapon' => [
                        'class' => 'pistol',
                        'damage' => null,
                        'damage_profile' => null,
                        'riders' => null,
                        'accuracy' => -4,
                        'rate' => 1,
                        'magazine' => 1,
                        'calibre' => '.22LR',
                    ],
                ],
                7 => [
                    'slug' => 'kalashtec-udar9',
                    'name' => 'Kalashtec Udar-9',
                    'flavor' => 'A compact, lightweight sub machine gun for the international market. Much of this snub nosed little SMG is made from recycled plastics, light weight alloys and up-cycled materials - which makes it light and small, but also prone to overheating and with a recoil response that takes some getting used to.',
                    'tier' => 1,
                    'rarity' => 'uncommon',
                    'stackable' => 0,
                    'weight' => '3.00',
                    'size' => '3.0',
                    'base_cost' => 3900,
                    'on_market' => 0,
                    'seed_quantity' => 4,
                    'weapon' => [
                        'class' => 'smg',
                        'damage' => null,
                        'damage_profile' => null,
                        'riders' => null,
                        'accuracy' => -2,
                        'rate' => 5,
                        'magazine' => 38,
                        'calibre' => '9mm',
                    ],
                ],
                8 => [
                    'slug' => 'rds22p',
                    'name' => 'RD-S22P',
                    'flavor' => 'Rangaku Dynamics combines Japanese engineering with Dutch design. The RD-S22P is the "Police" version of their sub machine gun chambered in .22LR. It is an old design, and quite outdated since the proliferation of various ballistic protection, but is a favorite of many small-town Police departments.',
                    'tier' => 1,
                    'rarity' => 'uncommon',
                    'stackable' => 0,
                    'weight' => '3.00',
                    'size' => '3.0',
                    'base_cost' => 3800,
                    'on_market' => 0,
                    'seed_quantity' => 3,
                    'weapon' => [
                        'class' => 'smg',
                        'damage' => null,
                        'damage_profile' => null,
                        'riders' => null,
                        'accuracy' => -1,
                        'rate' => 5,
                        'magazine' => 32,
                        'calibre' => '.22LR',
                    ],
                ],
                9 => [
                    'slug' => 'kuroda_silkframe',
                    'name' => 'Kuroda Lifeframe-300',
                    'flavor' => 'Kuroda manufactures some of the worlds most high-tech civilian protective gear, and their Lifeframe range is a big seller in the security industry. Light, durable, flexible and breathable, but able to absorb or at least slow light shrapnel and a range of light caliber fire.',
                    'tier' => 1,
                    'rarity' => 'uncommon',
                    'stackable' => 0,
                    'weight' => '2.00',
                    'size' => '5.0',
                    'base_cost' => 2100,
                    'on_market' => 0,
                    'seed_quantity' => 3,
                    'modifiers' => '[{"op": "add", "amount": -1, "target": {"id": "damage.hitpoints.blunt"}}, {"op": "add", "amount": -3, "target": {"id": "damage.hitpoints.slashing"}}, {"op": "add", "amount": -8, "target": {"id": "damage.hitpoints.penetrating"}}, {"op": "add", "amount": -3, "target": {"id": "damage.hitpoints.fragmentation"}}]',
                    'armour' => ['slots' => '["torso"]'],
                ],
            ],
            'shops' => [
                0 => [
                    'location' => 'dynamico_armaments',
                    'item' => '22',
                    'price' => 4,
                    'max_stock' => 5000,
                    'restock_amount' => 500,
                    'restock_hours' => 1,
                ],
                1 => [
                    'location' => 'dynamico_armaments',
                    'item' => 'lk_canister',
                    'price' => 160,
                    'max_stock' => 20,
                    'restock_amount' => 10,
                    'restock_hours' => 1,
                ],
                2 => [
                    'location' => 'dynamico_armaments',
                    'item' => 'liquid_knuckles',
                    'price' => 490,
                    'max_stock' => 5,
                    'restock_amount' => 2,
                    'restock_hours' => 1,
                ],
                3 => [
                    'location' => 'the-night-market',
                    'item' => '22',
                    'price' => 5,
                    'max_stock' => 500,
                    'restock_amount' => 50,
                    'restock_hours' => 1,
                ],
                4 => [
                    'location' => 'the-night-market',
                    'item' => 'zipgun',
                    'price' => 270,
                    'max_stock' => 2,
                    'restock_amount' => 2,
                    'restock_hours' => 1,
                ],
                5 => [
                    'location' => 'dynamico_armaments',
                    'item' => 'kuroda_silkframe',
                    'price' => 2200,
                    'max_stock' => 5,
                    'restock_amount' => 2,
                    'restock_hours' => 1,
                ],
                6 => [
                    'location' => 'dynamico_armaments',
                    'item' => 'rds22p',
                    'price' => 3950,
                    'max_stock' => 3,
                    'restock_amount' => 1,
                    'restock_hours' => 1,
                ],
                7 => [
                    'location' => 'dynamico_armaments',
                    'item' => 'kalashtec-udar9',
                    'price' => 4095,
                    'max_stock' => 3,
                    'restock_amount' => 1,
                    'restock_hours' => 2,
                ],
            ],
        ];

        foreach ($data['items'] as $row) {
            if (DB::table('items')->where('slug', $row['slug'])->exists()) {
                continue;
            }

            $id = DB::table('items')->insertGetId(array_diff_key($row, ['weapon' => 1, 'ammunition' => 1, 'armour' => 1]) + [
                'container' => 'physical',
                'capacity' => 0.0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (['weapon' => 'weapons', 'ammunition' => 'ammunition', 'armour' => 'armour'] as $key => $table) {
                if (isset($row[$key])) {
                    DB::table($table)->insert(['item_id' => $id] + $row[$key] + ['created_at' => $now, 'updated_at' => $now]);
                }
            }
        }

        foreach ($data['shops'] as $shop) {
            $location = DB::table('locations')->where('slug', $shop['location'])->value('id');
            $item = DB::table('items')->where('slug', $shop['item'])->value('id');

            if ($location === null || $item === null || DB::table('shop_items')->where('location_id', $location)->where('item_id', $item)->exists()) {
                continue;
            }

            DB::table('shop_items')->insert([
                'location_id' => $location,
                'item_id' => $item,
                'price' => $shop['price'],
                'max_stock' => $shop['max_stock'],
                'stock' => $shop['max_stock'],
                'restock_amount' => $shop['restock_amount'],
                'restock_hours' => $shop['restock_hours'],
                'restocked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Any of it may have been bought, carried or tuned since; there is nothing safe to take back.
    }
};
