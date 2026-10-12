<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\City\FeatureEditor;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Keep a shop stocked with every skill that is for sale, at list price.
 *
 * Skills are not on the regular market (see `Item::scopeRestockable()`), so a shop like Skills R Us is where
 * they are bought. Run nightly for `city.skills_shop`, and by hand for any other place.
 *
 * - ADDS the imprint of every skill with `on_market` that the place does not list, unlimited.
 * - PRICES every skill imprint it lists at list price: a price of its own is cleared, so the listing sells for the
 *   imprint's `base_cost` and follows it when it is retuned. Other items on the shelf are left as they are.
 * - Removes nothing, and hands back the clinic, buy list and surgery unchanged. It goes through `FeatureEditor`,
 *   the only writer of a place's listings, which diffs, so an existing listing keeps its stock.
 */
class StockSkillsShop extends Command
{
    protected $signature = 'city:stock-skills
                            {location? : the location\'s slug (default: config city.skills_shop)}
                            {--dry-run : say what would change and write nothing}
                            {--quiet-when-idle : print nothing unless something changed}';

    protected $description = 'List every skill that is for sale in a shop, at list price';

    public function handle(): int
    {
        $slug = (string) ($this->argument('location') ?? Config::get('city.skills_shop'));
        $quiet = (bool) $this->option('quiet-when-idle');
        $location = Location::query()->where('slug', $slug)->first();

        if ($location === null) {
            // A shop that has not been made yet is not an error for the nightly run.
            if (! $quiet) {
                $this->error("No location '{$slug}'.");
            }

            return $quiet && $this->argument('location') === null ? self::SUCCESS : self::FAILURE;
        }

        $held = $location->shopItems()->pluck('item_id')->all();

        $imprints = Item::query()
            ->whereHas('useSkill', static fn ($q) => $q->where('on_market', true))
            ->whereNotIn('id', $held)
            ->orderBy('id')
            ->get(['id', 'slug']);

        // Listed imprints that carry a price of their own.
        $repriced = $location->shopItems()
            ->whereNotNull('price')
            ->whereHas('item', static fn ($q) => $q->whereNotNull('use_skill_id'))
            ->count();

        if ($imprints->isEmpty() && $repriced === 0) {
            if (! $quiet) {
                $this->info("{$location->name} is up to date.");
            }

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->info("{$location->name} would gain {$imprints->count()} listing(s) and reprice {$repriced}: ".$imprints->pluck('slug')->implode(', '));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($location, $imprints): void {
            $keep = $location->shopItems()->with('item:id,use_skill_id')->get()->map(static fn ($row): array => [
                'item_id' => (int) $row->item_id,
                // A skill imprint sells at list price; anything else on the shelf keeps whatever it had.
                'price' => $row->item?->use_skill_id !== null ? null : $row->price,
                'max_stock' => $row->max_stock,
                'restock_amount' => (int) $row->restock_amount,
                'restock_hours' => $row->restock_hours,
            ])->all();

            // Unlimited: a skill is learned once, so there is nothing to run out of.
            $added = $imprints->map(static fn (Item $item): array => [
                'item_id' => (int) $item->id,
                'price' => null,
                'max_stock' => null,
                'restock_amount' => 0,
                'restock_hours' => null,
            ])->all();

            $surgery = $location->surgery;

            FeatureEditor::apply(
                $location,
                [...$keep, ...$added],
                $location->clinicServices()->pluck('price', 'service')->map(static fn ($p): int => (int) $p)->all(),
                $location->buys()->get()->map(static fn ($row): array => [
                    'item_id' => (int) $row->item_id,
                    'percent' => (int) $row->percent,
                    'max_quantity' => $row->max_quantity,
                ])->all(),
                $surgery === null ? null : [
                    'rating' => (int) $surgery->rating,
                    'install_price' => (int) $surgery->install_price,
                    'removal_price' => (int) $surgery->removal_price,
                ],
            );
        });

        $this->info("{$location->name}: {$imprints->count()} skill(s) listed, {$repriced} put back to list price.");

        return self::SUCCESS;
    }
}
