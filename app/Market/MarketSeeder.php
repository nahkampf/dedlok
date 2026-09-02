<?php

declare(strict_types=1);

namespace App\Market;

use App\Models\Item;
use App\Models\MarketPurchase;
use App\Models\MarketStock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;

/**
 * Restocks the market. Scheduled every eight hours; see routes/console.php.
 *
 * Not a Database\Seeders seeder — nothing here sets a database up. It is the
 * game's supply: three times a day it decides, per item, whether the market
 * got a delivery and how big it was.
 *
 * The pass is a top-up, never an addition. Each item has a target shelf count
 * and the market fills up TO it, so what players did not buy last time still
 * counts and an unpopular item does not silently pile up forever.
 *
 * Two things move the target off the item's own `seed_quantity`:
 *
 * 1. DEMAND. Units bought in the last seven days, against the shelf size. Once
 *    that ratio reaches the threshold, the target scales with it — a thing
 *    being cleaned out gets stocked deeper, up to a ceiling that stops a run
 *    on an item from feeding itself. The two branches meet exactly at the
 *    threshold; config/market.php has the formula and why.
 * 2. RARITY. One all-or-nothing roll per item per pass. Common always lands;
 *    ultra-rare lands one pass in twenty. A failed roll adds nothing at all
 *    rather than adding less, because scarcity a player can feel is a shelf
 *    that is usually empty, not a shelf that is always a little short.
 *
 * A pass that dies half way through is harmless and is deliberately not wrapped
 * in a transaction: every item is independent, the arithmetic reads the shelf
 * it finds, and the next pass tops up from wherever this one stopped. The one
 * thing that is NOT idempotent is the roll, and that is the point — running it
 * twice is two deliveries, which is why the schedule uses withoutOverlapping().
 */
final class MarketSeeder
{
    /** Items per query. The catalogue is expected to reach the hundreds. */
    private const CHUNK = 200;

    /**
     * @param  Closure(): int  $roll  Returns 1-100, compared against the rarity's chance.
     */
    public function __construct(
        private readonly Closure $roll,
        private readonly int $windowDays,
        private readonly float $threshold,
        private readonly float $maxMultiplier,
    ) {}

    /**
     * The roll is injected rather than called inline so a test can pin it.
     * A probabilistic pass that cannot be made deterministic is a pass whose
     * arithmetic can only be spot-checked.
     */
    public static function fromConfig(?Closure $roll = null): self
    {
        return new self(
            $roll ?? static fn (): int => random_int(1, 100),
            (int) Config::get('market.demand.window_days', 7),
            (float) Config::get('market.demand.threshold', 0.5),
            (float) Config::get('market.demand.max_multiplier', 4.0),
        );
    }

    public function run(?CarbonInterface $now = null, bool $dryRun = false): SeedResult
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $since = $now->subDays($this->windowDays);

        $result = new SeedResult;

        Item::query()
            ->restockable()
            ->chunkById(self::CHUNK, function (Collection $items) use ($result, $since, $now, $dryRun): void {
                $demand = $this->demandFor($items, $since);
                $shelves = $this->shelvesFor($items);

                foreach ($items as $item) {
                    $result->record($this->restock(
                        $item,
                        $demand[$item->id] ?? 0,
                        $shelves->get($item->id),
                        $now,
                        $dryRun,
                    ));
                }
            });

        return $result;
    }

    /**
     * One item's delivery.
     *
     * The roll comes first: it decides everything, and an item that fails it
     * needs no arithmetic. The demand figure is still reported, because a rare
     * item selling out is exactly what someone reading the log wants to see.
     */
    private function restock(
        Item $item,
        int $demand,
        ?MarketStock $shelf,
        CarbonImmutable $now,
        bool $dryRun,
    ): SeedOutcome {
        $roll = ($this->roll)();
        $stocked = $roll <= $item->rarity->seedChance();
        $before = (int) ($shelf?->quantity ?? 0);
        $target = $this->targetFor($item, $demand);
        $added = $stocked ? max(0, $target - $before) : 0;

        if ($added > 0 && ! $dryRun) {
            $shelf ??= new MarketStock(['item_id' => $item->id, 'quantity' => 0]);
            $shelf->quantity = $before + $added;
            $shelf->last_seeded_at = $now;
            $shelf->save();
        }

        return new SeedOutcome(
            item: $item,
            demand: $demand,
            target: $target,
            before: $before,
            added: $added,
            stocked: $stocked,
            roll: $roll,
        );
    }

    /**
     * How full the shelf should be this pass.
     *
     * See config/market.php for the formula and the reasoning. The division is
     * safe: scopeRestockable() only returns items with a seed quantity above
     * zero, and a threshold of zero is floored rather than allowed to divide.
     */
    private function targetFor(Item $item, int $demand): int
    {
        $shelf = $item->seed_quantity;
        $threshold = max($this->threshold, 0.0001);
        $ratio = $demand / $shelf;

        if ($ratio < $threshold) {
            return $shelf;
        }

        $multiplier = min($ratio / $threshold, max($this->maxMultiplier, 1.0));

        return (int) ceil($shelf * $multiplier);
    }

    /**
     * Units bought per item inside the window, in one query per chunk.
     *
     * @param  Collection<int, Item>  $items
     * @return array<int, int>
     */
    private function demandFor(Collection $items, CarbonInterface $since): array
    {
        return MarketPurchase::query()
            ->whereIn('item_id', $items->modelKeys())
            ->since($since)
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(quantity) as units')
            ->pluck('units', 'item_id')
            ->map(fn ($units): int => (int) $units)
            ->all();
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return Collection<int, MarketStock>
     */
    private function shelvesFor(Collection $items): Collection
    {
        return MarketStock::query()
            ->whereIn('item_id', $items->modelKeys())
            ->get()
            ->keyBy('item_id');
    }
}
