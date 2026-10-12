<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\City\FeatureEditor;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * List the imprint of every skill that is on the market in a shop, at list price.
 *
 * Adds only: a listing the place already has keeps its price and stock, and nothing is removed, so it can be run
 * again when a skill reaches the market. A listing with no price of its own sells for the item's `base_cost`, so
 * the shelf follows the list price and a retuned imprint is retuned here too. Goes through `FeatureEditor`, the
 * only writer of a place's listings, handing back everything else the place carries unchanged.
 */
class StockSkillsShop extends Command
{
    protected $signature = 'city:stock-skills
                            {location : the location\'s slug}
                            {--dry-run : say what would be added and write nothing}';

    protected $description = 'List every on-market skill imprint in a shop at list price';

    public function handle(): int
    {
        $location = Location::query()->where('slug', (string) $this->argument('location'))->first();

        if ($location === null) {
            $this->error('No such location.');

            return self::FAILURE;
        }

        $held = $location->shopItems()->pluck('item_id')->all();

        $imprints = Item::query()
            ->whereHas('useSkill', static fn ($q) => $q->where('on_market', true))
            ->whereNotIn('id', $held)
            ->orderBy('id')
            ->get(['id', 'slug']);

        if ($imprints->isEmpty()) {
            $this->info("{$location->name} already lists every skill on the market.");

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->info("{$location->name} would gain {$imprints->count()} listing(s): ".$imprints->pluck('slug')->implode(', '));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($location, $imprints): void {
            $keep = $location->shopItems()->get()->map(static fn ($row): array => [
                'item_id' => (int) $row->item_id,
                'price' => $row->price,
                'max_stock' => $row->max_stock,
                'restock_amount' => (int) $row->restock_amount,
                'restock_hours' => $row->restock_hours,
            ])->all();

            // Unlimited and at list price: a skill is learned once, so there is nothing to run out of.
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

        $this->info("{$location->name} now lists {$imprints->count()} more skill(s) at list price.");

        return self::SUCCESS;
    }
}
