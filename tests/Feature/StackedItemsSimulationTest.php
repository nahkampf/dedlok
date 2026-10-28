<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Inventory\ItemGranter;
use App\Inventory\ItemPlacer;
use App\Market\OfferBook;
use App\Market\OfferRefusal;
use App\Models\Character;
use App\Models\District;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\MarketOffer;
use App\Models\Safehouse;
use App\Models\Skill;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Safehouses\SafehouseStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * A stackable program is walked through every place a copy can be (loose, running, stored at a safehouse, listed on the
 * market, sold to somebody else) in a random order, and after every step the copies in each place must be exactly what
 * the step should have made them. A copy that joins the pile of another place keeps the total right and the places wrong,
 * which is the bug a total alone cannot see.
 */
final class StackedItemsSimulationTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $owner;

    private Character $buyer;

    private Item $item;

    private InventoryItem $card;

    /** @var list<string> */
    private array $trail = [];

    protected function setUp(): void
    {
        parent::setUp();

        Skill::factory()->create(['slug' => 'lattice', 'rank' => 1]);
        $dock = District::query()->where('slug', 'dockside')->firstOrFail();
        $home = Safehouse::create([
            'slug' => 'capsule', 'title' => 'Capsule hotel', 'district_id' => $dock->id, 'rent' => 40, 'security' => 0, 'stealth' => 0,
            'rest_rate' => 2, 'storage_slots' => 3, 'actions' => ['rest'],
        ]);

        $this->owner = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->owner);
        $this->owner->forceFill(['district_id' => $dock->id, 'safehouse_id' => $home->id, 'rent_due_at' => now()->addDay()])->save();

        $this->buyer = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3, 'creds' => 1_000_000]));
        $this->equipReadyDeck($this->buyer);

        $this->card = $this->ownedBy($this->owner, Item::factory()->holds('data', 64)->create());
        $this->item = Item::factory()->program(ProgramKind::Utility, qflops: 0.1, memory: 0.1, storage: 0.1, background: true)->create(['stackable' => true]);
    }

    /** @return array{loose: int, running: int, stored: int, offered: int, boxed: int, buyer: int} */
    private function places(): array
    {
        $out = ['loose' => 0, 'running' => 0, 'stored' => 0, 'offered' => 0, 'boxed' => 0, 'buyer' => 0];

        foreach (InventoryItem::query()->where('item_id', $this->item->id)->get() as $row) {
            $this->assertGreaterThanOrEqual(1, $row->quantity, 'a row with nothing in it is left behind '.$this->dump());

            if ($row->character_id === $this->buyer->id) {
                $out['buyer'] += $row->quantity;

                continue;
            }

            $in = array_filter([
                'running' => $row->running_on_deck_id !== null,
                'stored' => $row->safehouse_id !== null,
                'offered' => MarketOffer::query()->where('inventory_item_id', $row->id)->exists(),
                'boxed' => $row->parent_id !== null,
            ]);
            $this->assertLessThanOrEqual(1, count($in), 'one row is in two places '.$this->dump());

            $place = array_key_first($in) ?? 'loose';

            if ($place === 'running') {
                $this->assertSame(1, $row->quantity, 'a running row is one copy '.$this->dump());
            }

            $out[$place] += $row->quantity;
        }

        $this->assertLessThanOrEqual(1, InventoryItem::query()->where('character_id', $this->owner->id)->where('item_id', $this->item->id)->whereNull('parent_id')->whereNotNull('stack_key')->count(), 'two piles '.$this->dump());

        return $out;
    }

    private function dump(): string
    {
        return "\n".implode("\n", array_slice($this->trail, -12))."\n".json_encode(InventoryItem::query()->where('item_id', $this->item->id)->get(['id', 'character_id', 'quantity', 'running_on_deck_id', 'safehouse_id', 'stack_key'])->toArray());
    }

    private function pick(string $place): ?InventoryItem
    {
        $rows = InventoryItem::query()->where('character_id', $this->owner->id)->where('item_id', $this->item->id)->get()
            ->filter(fn (InventoryItem $r): bool => match ($place) {
                'running' => $r->running_on_deck_id !== null,
                'stored' => $r->safehouse_id !== null,
                'offered' => MarketOffer::query()->where('inventory_item_id', $r->id)->exists(),
                'boxed' => $r->parent_id !== null,
                default => $r->parent_id === null && $r->running_on_deck_id === null && $r->safehouse_id === null && ! MarketOffer::query()->where('inventory_item_id', $r->id)->exists(),
            })->values();

        return $rows->isEmpty() ? null : $rows[mt_rand(0, $rows->count() - 1)];
    }

    /** @return iterable<int> */
    public static function seeds(): iterable
    {
        foreach (range(1, 40) as $seed) {
            yield "seed $seed" => [$seed];
        }
    }

    /** @dataProvider seeds */
    #[DataProvider('seeds')]
    public function test_copies_stay_in_the_place_they_were_put(int $seed): void
    {
        mt_srand($seed);
        $runner = app(ProgramRunner::class);
        $expect = $this->places();
        ItemGranter::grant($this->owner, $this->item, 3);
        $expect['loose'] += 3;

        for ($step = 0; $step < 60; $step++) {
            $owner = $this->owner->fresh();
            $op = ['start', 'stop', 'stop_all', 'stop_hunt', 'deposit', 'withdraw', 'list', 'unlist', 'buy', 'grant', 'contain', 'eject'][mt_rand(0, 11)];
            $this->trail[] = "#$seed.$step $op ".json_encode($expect);

            switch ($op) {
                case 'start':
                    if ($row = $this->pick('loose')) {
                        if ($runner->start($owner, $row)->succeeded()) {
                            $expect['loose']--;
                            $expect['running']++;
                        }
                    }
                    break;
                case 'stop':
                    if ($row = $this->pick('running')) {
                        if ($runner->stop($owner, $row)->succeeded()) {
                            $expect['running']--;
                            $expect['loose']++;
                        }
                    }
                    break;
                case 'stop_all':
                    $runner->stopAll($owner, $owner->equippedDeck);
                    $expect['loose'] += $expect['running'];
                    $expect['running'] = 0;
                    break;
                case 'stop_hunt':
                    $runner->stopAllButScripts($owner);
                    $expect['loose'] += $expect['running'];
                    $expect['running'] = 0;
                    break;
                case 'deposit':
                    if ($row = $this->pick('loose')) {
                        $n = mt_rand(1, $row->quantity);
                        if (app(SafehouseStorage::class)->deposit($owner, $row, $n) === true) {
                            $expect['loose'] -= $n;
                            $expect['stored'] += $n;
                        }
                    }
                    break;
                case 'withdraw':
                    if ($row = $this->pick('stored')) {
                        $n = mt_rand(1, $row->quantity);
                        if (app(SafehouseStorage::class)->withdraw($owner, $row, $n) === true) {
                            $expect['stored'] -= $n;
                            $expect['loose'] += $n;
                        }
                    }
                    break;
                case 'list':
                    if ($row = $this->pick('loose')) {
                        $n = mt_rand(1, $row->quantity);
                        if (app(OfferBook::class)->list($owner, $row, 10, $n) instanceof MarketOffer) {
                            $expect['loose'] -= $n;
                            $expect['offered'] += $n;
                        }
                    }
                    break;
                case 'unlist':
                    if (($row = $this->pick('offered')) && ($offer = MarketOffer::query()->where('inventory_item_id', $row->id)->first())) {
                        $n = $row->quantity;
                        if (! ($done = app(OfferBook::class)->withdraw($owner, $offer)) instanceof OfferRefusal) {
                            $expect['offered'] -= $n;
                            $expect['loose'] += $n;
                        }
                    }
                    break;
                case 'buy':
                    if (($row = $this->pick('offered')) && ($offer = MarketOffer::query()->where('inventory_item_id', $row->id)->first())) {
                        $n = mt_rand(1, $row->quantity);
                        if (! app(OfferBook::class)->buy($this->buyer->fresh(), $offer, $n) instanceof OfferRefusal) {
                            $expect['offered'] -= $n;
                            $expect['buyer'] += $n;
                        }
                    }
                    break;
                case 'contain':
                    if ($row = $this->pick('loose')) {
                        if (app(ItemPlacer::class)->place($owner, $row, $this->card->fresh())->row !== null) {
                            $expect['loose'] -= $row->quantity;
                            $expect['boxed'] += $row->quantity;
                        }
                    }
                    break;
                case 'eject':
                    if ($row = $this->pick('boxed')) {
                        if (app(ItemPlacer::class)->place($owner, $row, null)->row !== null) {
                            $expect['boxed'] -= $row->quantity;
                            $expect['loose'] += $row->quantity;
                        }
                    }
                    break;
                case 'grant':
                    ItemGranter::grant($owner, $this->item, 1);
                    $expect['loose']++;
                    break;
            }

            $this->assertSame($expect, $this->places(), "after $op (seed $seed, step $step)".$this->dump());
        }
    }
}
