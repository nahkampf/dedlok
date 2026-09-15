<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Everybody who was already here gets a scanner.
 *
 * Reading a machine now takes an analyser running on the deck, and a character
 * created before the gate existed has no way to get one: the archetype loadout
 * only fires at creation, and there is no storefront to buy from. Without this
 * the feature would ship visible and unreachable for exactly the people who
 * have been playing.
 *
 * A ONE-OFF GIFT, and a migration rather than a command because it happens
 * once, to a world in a particular state, and the record of it belongs in the
 * history beside the change that made it necessary. On a fresh database there
 * are no characters and it does nothing.
 *
 * ONLY TO THOSE WITHOUT ONE, so re-running it cannot quietly hand out a second.
 * The scanner is stackable, so a blind insert would deepen a pile rather than
 * fail — silence being the worst of the three outcomes.
 *
 * Written through the model rather than the query builder, because
 * `inventory_items.stack_key` is derived on save: a row inserted underneath the
 * model arrives with a null key, which for a stackable item is a pile that can
 * never merge with the one beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $scanner = Item::query()->where('slug', 'scanner-mk1')->first();

        if ($scanner === null) {
            return;
        }

        DB::transaction(static function () use ($scanner): void {
            Character::query()
                ->whereDoesntHave('inventory', static fn ($query) => $query->where('item_id', $scanner->getKey()))
                ->each(static function (Character $character) use ($scanner): void {
                    InventoryItem::create([
                        'character_id' => $character->getKey(),
                        'item_id' => $scanner->getKey(),
                        'quantity' => 1,
                    ]);
                });
        });
    }

    /**
     * Nothing is taken back.
     *
     * A gift that reaches into a player's stash to reclaim itself is worse
     * than an unreversed migration: by the time anybody rolls this back the
     * scanner may be running, inside a shard, or spent on something. What the
     * character does with it afterwards is theirs.
     */
    public function down(): void {}
};
