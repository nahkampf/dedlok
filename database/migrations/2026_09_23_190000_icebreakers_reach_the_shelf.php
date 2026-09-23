<?php

declare(strict_types=1);

use App\Market\Rarity;
use App\Programs\ProgramKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The twelve icebreakers go on sale.
 *
 * They arrived `on_market` with a shelf size of ZERO, which is the default and
 * means listed but never stocked — nothing reaches the market in this codebase
 * by being forgotten. Giving them a size is the authoring step that was left.
 *
 * WHY THIS MATTERS MORE THAN MOST STOCK: an icebreaker is the gate in front of
 * every fight. Without one, a run that meets ICE has nothing to cut with, and
 * DefencesTest pins the other half of that — twenty turns is never enough, so
 * the ladder has to be climbable by buying the next piece as well as by
 * training for it.
 *
 * The sizes fall as the rarity climbs, which is the ordinary shape here: a
 * common piece is a shelf somebody can rely on, and an ultra-rare one is a
 * single copy that is only there 5% of the passes anybody looks.
 */
return new class extends Migration
{
    /** @return array<string, int> */
    private function shelves(): array
    {
        return [
            Rarity::Common->value => 8,
            Rarity::Uncommon->value => 5,
            Rarity::Rare->value => 3,
            Rarity::UltraRare->value => 1,
        ];
    }

    public function up(): void
    {
        foreach ($this->shelves() as $rarity => $size) {
            DB::table('items')
                ->whereIn('id', $this->icebreakers())
                ->where('rarity', $rarity)
                ->where('seed_quantity', 0)
                ->update(['seed_quantity' => $size, 'updated_at' => now()]);
        }
    }

    /**
     * Back to a shelf of nothing, which is what they were: listed, and never
     * delivered. The stock already on shelves is left where it is — it belongs
     * to the market now, and a rollback that emptied it would be taking
     * something out of a shop somebody may already have paid for.
     */
    public function down(): void
    {
        DB::table('items')
            ->whereIn('id', $this->icebreakers())
            ->update(['seed_quantity' => 0, 'updated_at' => now()]);
    }

    /** @return array<int, int> */
    private function icebreakers(): array
    {
        return DB::table('programs')
            ->where('kind', ProgramKind::Icebreaker->value)
            ->pluck('item_id')
            ->all();
    }
};
