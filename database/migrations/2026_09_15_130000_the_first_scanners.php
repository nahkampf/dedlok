<?php

declare(strict_types=1);

use App\Programs\ProgramKind;
use App\Stats\SkillStats;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Three scanners, because reading a machine takes software and software comes
 * in versions.
 *
 * The probe refuses without an analyser running on the equipped deck, so
 * without these the feature is unreachable — and there is no storefront yet to
 * buy one from. They arrive by migration for the same reason the placeholder
 * implants did: content that a mechanic cannot work without has to exist before
 * the mechanic ships, and the item editor is where it is TUNED rather than
 * where it is first drawn.
 *
 * WHAT A VERSION IS WORTH IS ON ITS OWN ROW, through the two channels every
 * program already has. A steadier read is a modifier on `check.systemprofiling`;
 * another look at the machine is one on `scan_passes`. Nothing in the probe
 * knows these three exist, and a Mk IV is a row.
 *
 * The ladder is deliberately not linear. The Mk I is a tool that works; the Mk
 * II is what a working professional carries; the ORACLE is expensive in the
 * thing a deck has least of — compute — and that is what stops it being the
 * obvious answer at every grade. A scanner that spends half a deck's qFLOPS is
 * a scanner you unload before you go anywhere dangerous.
 */
return new class extends Migration
{
    public function up(): void
    {
        $check = ['id' => SkillStats::checkId('systemprofiling')];

        $scanners = [
            [
                'slug' => 'scanner-mk1',
                'name' => 'Surveyor Mk I',
                'flavor' => 'Reads a machine the way a torch reads a room: slowly, and only what is in front of it.',
                'tier' => 1,
                'base_cost' => 400,
                'qflops_cost' => 2,
                'memory_cost' => 2,
                'storage_size' => 1,
                'modifiers' => null,
            ],
            [
                'slug' => 'scanner-mk2',
                'name' => 'Surveyor Mk II',
                'flavor' => 'The same instrument with a better ear. Most of what a mapper carries, most of the time.',
                'tier' => 1,
                'base_cost' => 1600,
                'qflops_cost' => 6,
                'memory_cost' => 4,
                'storage_size' => 2,
                'modifiers' => [
                    ['op' => 'percentage_of_base', 'amount' => 10, 'target' => $check],
                ],
            ],
            [
                'slug' => 'scanner-oracle',
                'name' => 'ORACLE',
                'flavor' => 'Listens to a machine until it says something. Eats a deck alive while it does.',
                'tier' => 2,
                'base_cost' => 9000,
                'qflops_cost' => 24,
                'memory_cost' => 12,
                'storage_size' => 6,
                'modifiers' => [
                    ['op' => 'percentage_of_base', 'amount' => 25, 'target' => $check],
                    ['op' => 'add', 'amount' => 2, 'target' => ['id' => 'scan_passes']],
                ],
            ],
        ];

        $now = now();

        foreach ($scanners as $scanner) {
            $itemId = DB::table('items')->insertGetId([
                'slug' => $scanner['slug'],
                'name' => $scanner['name'],
                'flavor' => $scanner['flavor'],
                'container' => 'data',
                'tier' => $scanner['tier'],
                'rarity' => $scanner['tier'] === 1 ? 'common' : 'rare',
                'base_cost' => $scanner['base_cost'],
                // LISTED BUT NOT STOCKED. `on_market` says a thing is not
                // retired; `seed_quantity` is how many the market keeps on the
                // shelf, and the documented default is 0 — nothing reaches the
                // market by being forgotten. Nothing sells anything yet, so a
                // shelf size now would be a number invented before the shop it
                // belongs to, and it would shift every pinned die in the
                // market's own tests by adding rows to the restock pass.
                'on_market' => true,
                'seed_quantity' => 0,
                'stackable' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('programs')->insert([
                'item_id' => $itemId,
                'kind' => ProgramKind::Analyser->value,
                'qflops_cost' => $scanner['qflops_cost'],
                'memory_cost' => $scanner['memory_cost'],
                'storage_size' => $scanner['storage_size'],
                // A scanner runs on the open grid: mapping is the safe run
                // type, not an intrusion.
                'background_process' => true,
                'modifiers' => $scanner['modifiers'] === null ? null : json_encode($scanner['modifiers']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        /*
         * THE STARTING SCANNER IS NOT GRANTED HERE, and that is on purpose.
         *
         * A new character needs one — scanning is the safe way in and there is
         * no storefront to buy from — but a loadout is AUTHORED CONTENT. The
         * Deck Jockey's deck and its AuraOS were put there in the archetype
         * editor rather than by a migration, and a single grant arriving in the
         * repository would be the only loadout row the repository has: two
         * places to look for one list, and the file half is the one that gets
         * forgotten.
         *
         * So the items exist and the grant is an editor decision, which is also
         * where it can be tuned when the Mk I stops being the right thing to
         * start with.
         */
    }

    public function down(): void
    {
        $ids = DB::table('items')
            ->whereIn('slug', ['scanner-mk1', 'scanner-mk2', 'scanner-oracle'])
            ->pluck('id');

        // Including any loadout that granted one, since the items are going.
        DB::table('archetype_items')->whereIn('item_id', $ids)->delete();
        DB::table('programs')->whereIn('item_id', $ids)->delete();
        DB::table('items')->whereIn('id', $ids)->delete();
    }
};
