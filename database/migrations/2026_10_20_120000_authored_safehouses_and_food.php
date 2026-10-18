<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Content authored in the editors: four more safehouses (a coffin, a squat, a one-room apartment, a loft) and three places
 * to eat (Kakurega, Pink House, an Automata vending machine) with their menus. Each is skipped when its slug already
 * exists, so running this over a database that has them (or after editing them in the admin) changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $districts = DB::table('districts')->pluck('id', 'slug');

        foreach ($this->safehouses() as $safehouse) {
            $district = $districts[$safehouse['district']] ?? null;

            if ($district === null || DB::table('safehouses')->where('slug', $safehouse['slug'])->exists()) {
                continue;
            }

            unset($safehouse['district']);
            DB::table('safehouses')->insert([
                ...$safehouse,
                'district_id' => $district,
                'actions' => json_encode($safehouse['actions']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($this->locations() as $location) {
            $district = $districts[$location['district']] ?? null;

            if ($district === null || DB::table('locations')->where('slug', $location['slug'])->exists()) {
                continue;
            }

            $meals = $location['meals'];
            unset($location['district'], $location['meals']);
            $id = DB::table('locations')->insertGetId($location + ['district_id' => $district, 'created_at' => $now, 'updated_at' => $now]);

            foreach ($meals as $meal) {
                DB::table('location_meals')->insert($meal + ['location_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('locations')->whereIn('slug', array_column($this->locations(), 'slug'))->delete();
        DB::table('safehouses')->whereIn('slug', array_column($this->safehouses(), 'slug'))->delete();
    }

    /** @return list<array<string, mixed>> */
    private function safehouses(): array
    {
        return [
            [
                'slug' => 'sleeper_coffin',
                'title' => 'Sleeper Coffin at the Grand Central',
                'description' => null,
                'district' => 'downtown',
                'rent' => 45,
                'security' => 10,
                'stealth' => 5,
                'storage_slots' => 2,
                'rest_rate' => 3,
                'meal_fullness' => 0,
                'meal_fatigue' => 0,
                'actions' => ['rest'],
            ],
            [
                'slug' => 'squat_highrise',
                'title' => 'Squat at the abandoned high-rise',
                'description' => null,
                'district' => 'eastside',
                'rent' => 9,
                'security' => 5,
                'stealth' => 5,
                'storage_slots' => 4,
                'rest_rate' => 2,
                'meal_fullness' => 10,
                'meal_fatigue' => 12,
                'actions' => ['eat', 'rest'],
            ],
            [
                'slug' => '1roomapt',
                'title' => 'One-room apt (bottom floor)',
                'description' => null,
                'district' => 'crystal-heights',
                'rent' => 90,
                'security' => 13,
                'stealth' => 11,
                'storage_slots' => 6,
                'rest_rate' => 9,
                'meal_fullness' => 15,
                'meal_fatigue' => 20,
                'actions' => ['eat', 'rest', 'shower'],
            ],
            [
                'slug' => 'hard_loft',
                'title' => 'Hard Loft',
                'description' => 'The upper floor of an old grain warehouse has been cleared out and turned into a warehouse apartment. Big open spaces, walls made of raw concrete and exposed brickwork and metal girders remind the occupants of the buildings history. A type of abode that was quite popular among artists a decade or two ago.',
                'district' => 'dockside',
                'rent' => 190,
                'security' => 15,
                'stealth' => 10,
                'storage_slots' => 18,
                'rest_rate' => 13,
                'meal_fullness' => 17,
                'meal_fatigue' => 22,
                'actions' => ['eat', 'rest', 'patch', 'meditate', 'shower'],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function locations(): array
    {
        return [
            [
                'slug' => 'kakurega',
                'name' => '隠れ家 Kakurega',
                'description' => 'A non-descript little red door in an unassuming alleyway leads down into Kakurega, one of the finest ramen places in The City. There are no frills here, no shtick, only great food.',
                'district' => 'downtown',
                'map_x' => 50,
                'map_y' => 50,
                'discovery' => 'open',
                'meals' => [
                    [
                        'name' => 'Tonkotsu',
                        'price' => 21,
                        'meal_fullness' => 28,
                        'meal_fatigue' => 50,
                    ],
                    [
                        'name' => 'Tantanmen',
                        'price' => 25,
                        'meal_fullness' => 30,
                        'meal_fatigue' => 55,
                    ],
                    [
                        'name' => 'Shio',
                        'price' => 23,
                        'meal_fullness' => 22,
                        'meal_fatigue' => 45,
                    ],
                    [
                        'name' => 'Kaarage',
                        'price' => 18,
                        'meal_fullness' => 16,
                        'meal_fatigue' => 25,
                    ],
                    [
                        'name' => 'Small cup of Sake',
                        'price' => 13,
                        'meal_fullness' => 4,
                        'meal_fatigue' => 8,
                    ],
                    [
                        'name' => 'Yebisu Malt Beer',
                        'price' => 14,
                        'meal_fullness' => 8,
                        'meal_fatigue' => 7,
                    ],
                ],
            ],
            [
                'slug' => 'pink_house',
                'name' => 'Розовый дом',
                'description' => 'Розовый дом, the "Pink House" is a very cosy little place in Little Moscow that serves traditional russian peasant-style food, but modernized. It\'s great food for a reasonable price, but be prepared to get funny looks if your russian is not up to snuff.',
                'district' => 'little-moscow',
                'map_x' => 50,
                'map_y' => 50,
                'discovery' => 'open',
                'meals' => [
                    [
                        'name' => 'Kasha Bowl',
                        'price' => 10,
                        'meal_fullness' => 10,
                        'meal_fatigue' => 10,
                    ],
                    [
                        'name' => 'Shchi',
                        'price' => 20,
                        'meal_fullness' => 17,
                        'meal_fatigue' => 15,
                    ],
                    [
                        'name' => 'Borscht',
                        'price' => 24,
                        'meal_fullness' => 19,
                        'meal_fatigue' => 17,
                    ],
                    [
                        'name' => 'Baltika Beer',
                        'price' => 14,
                        'meal_fullness' => 8,
                        'meal_fatigue' => 7,
                    ],
                ],
            ],
            [
                'slug' => 'automata_vending',
                'name' => 'Automata Vending Machine',
                'description' => 'Automata vending machines used to be everywhere, and some of them were huge - carrying everything from snacks and drinks to food, condoms, cigarettes, OTC medication, some even non-lethal self defense weapons and disposable clothing. There was a big scandal when it turned out quite a few of them also sold narcotics if you keyed in the secret code, and now not many are still left. This one in Dockside has to be thirty years old by now, is covered by grime, graffiti and  stickers, but someone still keeps it operational an stocked.',
                'district' => 'dockside',
                'map_x' => 50,
                'map_y' => 50,
                'discovery' => 'open',
                'meals' => [
                    [
                        'name' => 'Nitro 100 Energy Drink',
                        'price' => 8,
                        'meal_fullness' => 3,
                        'meal_fatigue' => 15,
                    ],
                    [
                        'name' => '\'Roid Fuel Power Bar',
                        'price' => 10,
                        'meal_fullness' => 9,
                        'meal_fatigue' => 9,
                    ],
                ],
            ],
        ];
    }
};
