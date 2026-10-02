<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seven districts and a few places to find in them.
 *
 * THE DISTRICT NAMES ARE THE AUTHOR'S. The map positions, the links between
 * districts, every location and the colours are PLACEHOLDERS (the positions are laid out so
 * that no two name boxes touch on a 320px phone — see CLAUDE.md's map rules): the links decide which routes
 * travel will build, so they want a deliberate pass. Content is authored in
 * the admin once the editors exist; this only gives the City something to
 * stand on.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: int, 2: int, 3: string, 4: string}> slug => [name, x, y, color, description] */
    private const DISTRICTS = [
        'old-town' => ['Old Town', 33, 63, 'steel', 'Narrow streets and older money gone to seed. Everybody starts somewhere.'],
        'dockside' => ['Dockside', 22, 86, 'cyan', 'Cranes, containers and people who do not ask what is in them.'],
        'eastside' => ['Eastside', 80, 63, 'brass', 'Night markets, noodle smoke and a lot of quiet deals.'],
        'downtown' => ['Downtown', 50, 42, 'violet', 'Glass, ad-light and the offices that run everything else.'],
        'little-moscow' => ['Little Moscow', 76, 86, 'clay', 'A neighbourhood that kept its own language and its own rules.'],
        'crystal-heights' => ['Crystal Heights', 78, 20, 'jade', 'Towers above the smog. You are not supposed to be here.'],
        'knightsbridge-projects' => ['Knightsbridge Projects', 25, 20, 'moss', 'Concrete blocks stacked against the sky. The lifts rarely work.'],
    ];

    /** @var list<array{0: string, 1: string}> */
    private const LINKS = [
        ['old-town', 'downtown'],
        ['old-town', 'dockside'],
        ['old-town', 'eastside'],
        ['old-town', 'knightsbridge-projects'],
        ['downtown', 'crystal-heights'],
        ['downtown', 'knightsbridge-projects'],
        ['eastside', 'little-moscow'],
        ['eastside', 'crystal-heights'],
        ['dockside', 'little-moscow'],
    ];

    /** @var list<array<string, mixed>> */
    private const LOCATIONS = [
        ['old-town', 'the-rusty-nail', 'The Rusty Nail', 'A bar that has outlived three owners and a fire.', 30, 40, 'open', null, null],
        ['old-town', 'back-alley-clinic', 'Back-Alley Clinic', 'No sign on the door. You have to know.', 68, 62, 'explorable', 'difficult', null],
        ['dockside', 'pier-nine-warehouse', 'Pier Nine Warehouse', 'Crates stacked three high, and one of them is always open.', 62, 38, 'explorable', 'trivial', null],
        ['eastside', 'the-night-market', 'The Night Market', 'Stalls that appear after dark and are gone by morning.', 40, 50, 'explorable', 'difficult', null],
        ['downtown', 'meridian-plaza', 'Meridian Plaza', 'A public square under a great deal of private surveillance.', 50, 55, 'open', null, null],
        ['downtown', 'server-farm-annex', 'Server Farm Annex', 'Rows of humming racks nobody seems to maintain.', 70, 30, 'explorable', 'obscure', null],
        ['little-moscow', 'cafe-baikal', 'Café Baikal', 'Strong tea, strong opinions, and a back room.', 45, 45, 'open', null, null],
        ['crystal-heights', 'skybridge-lounge', 'Skybridge Lounge', 'Members only. The list is not written down anywhere you can read it.', 55, 50, 'explored_by_flag', null, 'crystal_heights_invite'],
        ['knightsbridge-projects', 'the-stairwell-market', 'The Stairwell Market', 'Trade on every landing, because the lifts do not work.', 35, 40, 'explorable', 'trivial', null],
        ['knightsbridge-projects', 'the-sub-basement', 'The Sub-Basement', 'Below the lowest floor on the plans.', 72, 78, 'explorable', 'secretive', null],
    ];

    public function up(): void
    {
        $now = now();
        $ids = [];

        foreach (self::DISTRICTS as $slug => [$name, $x, $y, $color, $description]) {
            $ids[$slug] = DB::table('districts')->insertGetId([
                'slug' => $slug, 'name' => $name, 'description' => $description,
                'map_x' => $x, 'map_y' => $y, 'color' => $color, 'travel_weight' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (self::LINKS as [$a, $b]) {
            [$low, $high] = [min($ids[$a], $ids[$b]), max($ids[$a], $ids[$b])];
            DB::table('district_links')->insert(['district_a_id' => $low, 'district_b_id' => $high]);
        }

        foreach (self::LOCATIONS as [$district, $slug, $name, $description, $x, $y, $discovery, $concealment, $flag]) {
            DB::table('locations')->insert([
                'district_id' => $ids[$district], 'slug' => $slug, 'name' => $name, 'description' => $description,
                'map_x' => $x, 'map_y' => $y, 'discovery' => $discovery,
                'concealment' => $concealment, 'required_flag' => $flag,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('locations')->whereIn('slug', array_column(self::LOCATIONS, 1))->delete();
        DB::table('districts')->whereIn('slug', array_keys(self::DISTRICTS))->delete();
    }
};
