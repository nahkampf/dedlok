<?php

declare(strict_types=1);

use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\Item;
use App\Programs\ProgramKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Run trees (docs/RUN_TREE.md): Nmap needs an Nmapper running, so the first one arrives with the mechanic. `nmap` is a
 * background Utility (1 / 1 / 1) that reveals one edge out. One goes into the stash of every Deck Jockey who has none;
 * it is sold at Chrome Palace for 300 creds, and on the shelves of Lockjaw, Anansi Data Weaving, Ninth Circle Security
 * and Joint Signals Command for 2 loyalty at standing 0, exchanges of grades 1-5.
 *
 * NOT THE LOADOUT. A new Deck Jockey's nmap is added in the archetype editor when the feature ships: a loadout is
 * authored content (the first scanners' rule). Safe to run twice: everything is looked up by slug or name first.
 */
return new class extends Migration
{
    private const SHELVES = ['Lockjaw', 'Anansi Data Weaving', 'Ninth Circle Security', 'Joint Signals Command'];

    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('nmap_reach')->nullable()->after('assess_step')->comment('An Nmapper (a Utility): how many edges of a run tree one Nmap reveals around the runner. NULL is not an Nmapper.');
        });

        $now = now();

        $itemId = DB::table('items')->where('slug', 'nmap')->value('id') ?? DB::table('items')->insertGetId([
            'slug' => 'nmap',
            'name' => 'nmap',
            'flavor' => 'Network mapper. Probes linked systems and reveal them. Old, but battleproven software.',
            'container' => 'data',
            'tier' => 1,
            'rarity' => 'common',
            'base_cost' => 300,
            'on_market' => true,
            'seed_quantity' => 0,
            'stackable' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('programs')->insertOrIgnore([
            'item_id' => $itemId,
            'kind' => ProgramKind::Utility->value,
            'qflops_cost' => 1,
            'memory_cost' => 1,
            'storage_size' => 1,
            'background_process' => true,
            'nmap_reach' => 1,
            'modifiers' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // A NULL price is the item's base_cost. Five on the shelf, one back every two hours, like the shop's others.
        $palace = DB::table('locations')->where('slug', 'chrome_palace')->value('id');

        if ($palace !== null) {
            DB::table('shop_items')->insertOrIgnore([
                'location_id' => $palace,
                'item_id' => $itemId,
                'price' => null,
                'max_stock' => 5,
                'stock' => 5,
                'restock_amount' => 1,
                'restock_hours' => 2,
                'restocked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (DB::table('entities')->whereIn('name', self::SHELVES)->pluck('id') as $entity) {
            if (! DB::table('entity_offers')->where('entity_id', $entity)->where('item_id', $itemId)->exists()) {
                DB::table('entity_offers')->insert([
                    'entity_id' => $entity,
                    'min_grade' => 1,
                    'max_grade' => 5,
                    'item_id' => $itemId,
                    'loyalty_price' => 2,
                    'min_standing' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $deckJockey = DB::table('archetypes')->where('slug', 'deckjockey')->value('id');

        if ($deckJockey === null) {
            return;
        }

        $item = Item::query()->findOrFail($itemId);

        Character::query()->where('archetype', $deckJockey)
            ->whereDoesntHave('inventory', fn ($q) => $q->where('item_id', $itemId))
            ->each(fn (Character $character) => ItemGranter::grant($character, $item));
    }

    /** Removes the item with its program, shelves and grants (cascades), and the column. Copies players own go with it. */
    public function down(): void
    {
        $itemId = DB::table('items')->where('slug', 'nmap')->value('id');

        if ($itemId !== null) {
            DB::table('inventory_items')->where('item_id', $itemId)->delete();
            DB::table('entity_offers')->where('item_id', $itemId)->delete();
            DB::table('shop_items')->where('item_id', $itemId)->delete();
            DB::table('archetype_items')->where('item_id', $itemId)->delete();
            DB::table('programs')->where('item_id', $itemId)->delete();
            DB::table('items')->where('id', $itemId)->delete();
        }

        Schema::table('programs', fn (Blueprint $t) => $t->dropColumn('nmap_reach'));
    }
};
