<?php

declare(strict_types=1);

use App\Implants\BodyRegion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One implant per region, so the body map has something to hold.
 *
 * PLACEHOLDERS, AND THEY SAY SO IN THEIR OWN NAMES. Each grants +2% maximum hit
 * points and nothing else — the same effect five times, which no real
 * catalogue would ever have. They exist to prove the machinery end to end and
 * to give the screen something to draw; what an implant actually DOES is
 * content design that has not happened.
 *
 * Written by migration rather than by a seeder for the reason ItemSeeder seeds
 * no items: a hardcoded catalogue in PHP would be a second source for content
 * the items table owns, and the one that wins on a re-seed. These are not
 * derived from anything, so the migration writes them once and the item editor
 * owns them from then on — including deleting them when the real ones arrive.
 *
 * `on_market` is false and `seed_quantity` is 0, so the market will not stock
 * placeholders. Nothing reaches a player by being forgotten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach (BodyRegion::cases() as $region) {
            $part = ucfirst($region->value);

            $rows[] = [
                'slug' => "dummy_implant_{$region->value}",
                'name' => "Dummy Implant {$part}",
                'flavor' => 'A placeholder. It does the same small thing every other placeholder does, and it will be deleted when real implants are written.',
                'container' => 'physical',
                'tier' => 1,
                'rarity' => 'common',
                // An implant is one object in one place. Two of them sharing a
                // row could not be installed individually, the same reason a
                // deck module does not stack.
                'stackable' => false,
                'implant_region' => $region->value,
                'modifiers' => json_encode([
                    ['op' => 'percentage_of_base', 'amount' => 2, 'target' => ['id' => 'hitpoints_max']],
                ]),
                'weight' => 0.1,
                'size' => 1,
                'base_cost' => 0,
                'on_market' => false,
                'seed_quantity' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($rows as $row) {
            if (! DB::table('items')->where('slug', $row['slug'])->exists()) {
                DB::table('items')->insert($row);
            }
        }
    }

    public function down(): void
    {
        DB::table('items')
            ->whereIn('slug', array_map(
                static fn (BodyRegion $r): string => "dummy_implant_{$r->value}",
                BodyRegion::cases(),
            ))
            ->delete();
    }
};
