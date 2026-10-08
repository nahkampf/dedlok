<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Item;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;

/**
 * Where the decryption imprints sit.
 *
 * Cryptology and Precomputation: UNCOMMON, a shelf of 10 each (an imprint derives its rarity and shelf from rank once,
 * at creation, and rank 8 derived ultra-rare with a shelf of 4).
 *
 * Ouroboros (rank 16) leaves the market: it is gained through exchanges, quests and drops only. `Imprint::syncFor`
 * keeps the item's `on_market` in step with the skill's, so the skill is the one flag turned off; the item stays
 * ULTRA-RARE, which is what a drop table reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cryptology', 'precomputation'] as $slug) {
            $this->imprint($slug, ['rarity' => 'uncommon', 'seed_quantity' => 10]);
        }

        $ouroboros = Skill::query()->where('slug', 'ouroboros')->first();

        if ($ouroboros !== null) {
            $ouroboros->update(['on_market' => false]);
            Imprint::syncFor($ouroboros);
            $this->imprint('ouroboros', ['rarity' => 'ultra_rare', 'seed_quantity' => 0]);
        }
    }

    public function down(): void
    {
        $ouroboros = Skill::query()->where('slug', 'ouroboros')->first();

        if ($ouroboros !== null) {
            $ouroboros->update(['on_market' => true]);
            Imprint::syncFor($ouroboros);
        }
    }

    /** @param  array<string, mixed>  $columns */
    private function imprint(string $slug, array $columns): void
    {
        $skill = Skill::query()->where('slug', $slug)->first();

        if ($skill !== null) {
            Item::query()->where('use_skill_id', $skill->id)->update($columns);
        }
    }
};
