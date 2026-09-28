<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Market\Rarity;
use App\Models\Item;
use App\Models\LootTable;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemContent;
use App\Support\Text;
use Closure;
use Illuminate\Support\Facades\Config;

/**
 * Filling the world's machines back up. The only writer of `system_contents`.
 *
 * The grid's second supply, and it is written to the shape the first two
 * already have — `MarketSeeder` for the shelves, `GridSeeder` for the machines
 * themselves. A pass over a node that still has its contents does NOTHING: a
 * restock tops a node up to what it should be holding rather than adding to it,
 * so running twice is not two hauls and a node nobody has robbed does not
 * quietly become the richest thing on the grid.
 *
 * EMPTYING A NODE IS THEREFORE TEMPORARY, which is the point. A quiet thief has
 * somewhere to come back to, and the brake on that loop is the raid counter and
 * the detection floor rather than scarcity — see CYBERSPACE.md, *Lockdown*.
 *
 * EVERYTHING SCALES WITH GRADE, because grade is the promise a scan makes: the
 * machine that can end you is the machine worth breaking into. Type leans it
 * further — a silo keeps files, a compute node moves money — which is the crude
 * version of a loot table and says so.
 *
 * THE ROLL IS INJECTED, like the market's and the grid's. A probabilistic pass
 * tested against real randomness is a test that passes most of the time.
 */
final class NodeStocker
{
    /** Rows per insert. A grid restock is not one statement. */
    private const CHUNK = 500;

    /**
     * The loot table, read once per pass.
     *
     * @var list<LootTable>
     */
    private array $weights = [];

    /**
     * @param  array<string, mixed>  $config
     * @param  Closure(int, int): int  $roll  Inclusive both ends, like random_int.
     */
    public function __construct(
        private readonly array $config,
        private readonly Closure $roll,
    ) {}

    /** @param  ?Closure(int, int): int  $roll */
    public static function fromConfig(?Closure $roll = null): self
    {
        return new self(
            (array) Config::get('cyberspace.contents', []),
            $roll ?? static fn (int $min, int $max): int => random_int($min, $max),
        );
    }

    /**
     * Top every empty machine up.
     *
     * Empty is the whole test, and it is one query rather than a decision per
     * node: a machine that is still holding something was not robbed since the
     * last pass, and there is nothing to do to it.
     */
    public function run(bool $dryRun = false): NodeStockResult
    {
        $result = new NodeStockResult;
        $encryption = $this->encryptionLadder();
        $items = $this->stockableItems();
        // Read once for the whole pass: three thousand machines asking the
        // same forty rows is three thousand answers that never differ.
        $this->weights = LootTable::weights()->all();

        System::query()
            ->whereNot('type', SystemType::Portal->value)
            ->whereDoesntHave('contents')
            ->chunkById(self::CHUNK, function ($systems) use ($result, $encryption, $items, $dryRun): void {
                $rows = [];

                foreach ($systems as $system) {
                    $contents = $this->contentsFor($system, $encryption, $items);

                    $result->record($system, $contents);

                    foreach ($contents as $content) {
                        $rows[] = self::row($content, (int) $system->getKey());
                    }
                }

                if (! $dryRun && $rows !== []) {
                    SystemContent::query()->insert($rows);
                }
            });

        return $result;
    }

    /**
     * One content row, with every column the table has.
     *
     * A batch insert demands that every row name the same columns, and the
     * three kinds name different ones — which is the first thing this got
     * wrong. So the shape is filled in here rather than at each kind, and a
     * fourth kind cannot forget to.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function row(array $content, int $systemId): array
    {
        return array_merge([
            'system_id' => $systemId,
            'kind' => null,
            'amount' => null,
            'item_id' => null,
            'quantity' => null,
            'label' => null,
            'size_qb' => null,
            'encryption_skill_id' => null,
            'value_sealed' => null,
            'value_broken' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $content);
    }

    /**
     * What one machine should be holding.
     *
     * Public because the day a system relocates or is created mid-life it needs
     * stocking without a pass over the whole grid.
     *
     * @param  array<int, int>  $encryption  rank => skill id
     * @param  array<string, list<int>>  $items  rarity => item ids
     * @return list<array<string, mixed>>
     */
    public function contentsFor(System $system, array $encryption, array $items): array
    {
        $grade = (int) $system->grade;
        $lean = (array) ($this->config['by_type'][$system->type->value] ?? []);

        $contents = [];

        // Creds first, and always: the floor of any haul.
        [$low, $high] = $this->range('creds', $grade);
        $amount = (int) round(($this->roll)($low, $high) * ((int) ($lean['creds'] ?? 1000)) / 1000);

        $contents[] = [
            'kind' => ContentKind::Creds->value,
            'amount' => max(1, $amount),
        ];

        if ($this->hits((int) ($this->config['data']['chance'][$grade] ?? 0), (int) ($lean['data'] ?? 1000))) {
            $contents[] = $this->file($system->type, $grade, $encryption);
        }

        foreach ($this->itemsFor($system, $lean, $items) as $item) {
            $contents[] = $item;
        }

        return $contents;
    }

    /**
     * The items this machine should be holding.
     *
     * NONE TO SEVERAL, ordinarily: the odds are rolled again after each hit,
     * so a rich machine is rich rather than merely likely, capped so a run of
     * luck cannot empty the catalogue into one box.
     *
     * UNLESS IT WAS TOLD. A machine carrying a `stock_rule` holds what the rule
     * says — a mission cannot be built on the world happening to put the right
     * thing in the right place. A rule REPLACES the draw rather than adding to
     * it, unless it asks to keep it, which is for a node that should look
     * ordinary while carrying the one thing that matters.
     *
     * @param  array<string, int>  $lean
     * @param  array<string, list<int>>  $catalogue
     * @return list<array<string, mixed>>
     */
    private function itemsFor(System $system, array $lean, array $catalogue): array
    {
        $rule = $system->stock_rule;

        if (is_array($rule) && $rule !== []) {
            $told = $this->fromRule($rule);

            if (! ($rule['plus_random'] ?? false)) {
                return $told;
            }

            return array_merge($told, $this->randomItems($system, $lean, $catalogue));
        }

        return $this->randomItems($system, $lean, $catalogue);
    }

    /**
     * What a rule says, in the two shapes the design named.
     *
     * `fixed` is exactly these; `any_of` is this many drawn from that set,
     * without replacement, because "two of these three" means two different
     * ones. An id the catalogue has lost is skipped rather than fatal: a
     * mission whose reward was deleted should fail to give a reward, not take
     * the nightly restock of the whole grid down with it.
     *
     * @param  array<string, mixed>  $rule
     * @return list<array<string, mixed>>
     */
    private function fromRule(array $rule): array
    {
        $items = [];

        foreach ((array) ($rule['fixed'] ?? []) as $entry) {
            $id = (int) ($entry['item_id'] ?? 0);

            if ($id > 0) {
                $items[] = $this->item($id, max(1, (int) ($entry['quantity'] ?? 1)));
            }
        }

        $from = array_values(array_filter(array_map('intval', (array) ($rule['any_of']['from'] ?? []))));
        $count = min((int) ($rule['any_of']['count'] ?? 0), count($from));

        for ($i = 0; $i < $count; $i++) {
            // Drawn out of the bag rather than back into it: "two of these
            // three" means two different ones.
            $picked = array_splice($from, ($this->roll)(0, count($from) - 1), 1);
            $items[] = $this->item((int) $picked[0], 1);
        }

        return $items;
    }

    /**
     * @param  array<string, int>  $lean
     * @param  array<string, list<int>>  $catalogue
     * @return list<array<string, mixed>>
     */
    private function randomItems(System $system, array $lean, array $catalogue): array
    {
        if ($catalogue === []) {
            return [];
        }

        $chance = (int) ($this->config['item']['chance'][(int) $system->grade] ?? 0);
        $max = max(0, (int) ($this->config['item']['max'] ?? 1));
        $items = [];

        while (count($items) < $max && $this->hits($chance, (int) ($lean['item'] ?? 1000))) {
            $rarity = $this->rarityFor($system, $this->weights, $catalogue);

            if ($rarity === null) {
                break;
            }

            $shelf = $catalogue[$rarity];
            $items[] = $this->item($shelf[($this->roll)(0, count($shelf) - 1)], 1);
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function item(int $itemId, int $quantity): array
    {
        return [
            'kind' => ContentKind::Item->value,
            'item_id' => $itemId,
            'quantity' => $quantity,
        ];
    }

    /**
     * A file.
     *
     * Its encryption comes off the ladder those four skills form, the same way
     * a machine's operating system does: the harder the machine, the further up
     * it seals its data. Worth more broken than sealed, by a multiplier that is
     * the whole reason decryption is a decision.
     *
     * Its NAME comes from the kind of machine it was on, because that is most
     * of what makes a silo feel like a silo.
     *
     * @param  array<int, int>  $encryption
     * @return array<string, mixed>
     */
    private function file(SystemType $type, int $grade, array $encryption): array
    {
        [$low, $high] = $this->range('data.size_qb', $grade);
        $size = ($this->roll)($low, $high);

        [$low, $high] = $this->range('data.value', $grade);
        $sealed = ($this->roll)($low, $high);

        $multiplier = (int) ($this->config['data']['broken_multiplier'] ?? 2000);

        return [
            'kind' => ContentKind::Data->value,
            'label' => $this->labelFor($type),
            'size_qb' => $size,
            'encryption_skill_id' => $this->encryptionFor($grade, $encryption),
            'value_sealed' => $sealed,
            'value_broken' => (int) round($sealed * $multiplier / 1000),
        ];
    }

    /**
     * What the file calls itself.
     *
     * A name rather than "Data (14 QB)", because what a player is deciding
     * about is whether to carry it, and a thing with a name is a thing you
     * remember leaving behind. Composed from the strings file: there is no
     * catalogue of files to carry one.
     */
    private function labelFor(SystemType $type): string
    {
        $words = (array) Config::get("strings.cyberspace.files.words.{$type->value}", []);

        if ($words === []) {
            return Text::get('cyberspace.files.fallback');
        }

        return $words[($this->roll)(0, count($words) - 1)];
    }

    /**
     * Which encryption, by grade.
     *
     * Nine grades across however many encryption skills there are, so the top
     * grade gets the hardest and the bottom the cheapest whatever the ladder's
     * length becomes — the same arithmetic the operating systems use, and for
     * the same reason.
     *
     * @param  array<int, int>  $ladder
     */
    private function encryptionFor(int $grade, array $ladder): ?int
    {
        if ($ladder === []) {
            return null;
        }

        $rungs = array_values($ladder);
        $rung = (int) floor(($grade - Grade::MIN) * count($rungs) / Grade::MAX);

        return $rungs[$rung] ?? end($rungs);
    }

    /** @return array<int, int> */
    private function encryptionLadder(): array
    {
        return Skill::query()
            ->where('category', 'Encryption')
            ->orderBy('rank')
            ->pluck('id')
            ->all();
    }

    /**
     * What a node may be holding, from the catalogue, grouped by rarity.
     *
     * TWO FILTERS, AND THE SECOND IS THE IMPORTANT ONE.
     *
     * Listed only: `on_market = false` is how content is retired, and a node
     * holding something withdrawn is the catalogue leaking back in through the
     * world.
     *
     * And DATA ONLY. A machine on the grid holds what a machine can hold —
     * software, files, imprints. It cannot hand somebody a cyberdeck or an
     * implant, because those are objects in the world and a node is not a
     * place a player's hands can reach. `items.container` is the column that
     * already says which is which, so this is one clause rather than a new
     * flag on the catalogue.
     *
     * @return array<string, list<int>>
     */
    private function stockableItems(): array
    {
        return Item::query()
            ->where('on_market', true)
            ->where('container', Item::CONTAINER_DATA)
            ->get(['id', 'rarity'])
            ->groupBy(fn (Item $item): string => $item->rarity?->value ?? Rarity::Common->value)
            ->map(fn ($items): array => $items->pluck('id')->all())
            ->all();
    }

    /**
     * Which rarity this machine drops, out of the loot table.
     *
     * Weighted rather than gated, so the answer is "hardly ever" rather than
     * "never" — except where the table says weight 0, which is how ultra-rare
     * stays out of the bottom seven grades. A rarity nobody has any items of
     * falls through to whatever the catalogue does have, because a table that
     * picked an empty shelf would drop nothing and look like a bug.
     *
     * @param  list<LootTable>  $weights
     * @param  array<string, list<int>>  $catalogue
     */
    private function rarityFor(System $system, array $weights, array $catalogue): ?string
    {
        $covering = array_filter(
            $weights,
            fn ($row): bool => $row->covers($system->type, (int) $system->grade)
                && $row->weight > 0
                && ($catalogue[$row->rarity->value] ?? []) !== [],
        );

        // A typed row beats an untyped one for the same machine.
        $typed = array_filter($covering, static fn ($row): bool => $row->system_type !== null);
        $covering = $typed !== [] ? $typed : $covering;

        $total = array_sum(array_map(static fn ($row): int => $row->weight, $covering));

        if ($total <= 0) {
            return null;
        }

        $roll = ($this->roll)(1, $total);

        foreach ($covering as $row) {
            $roll -= $row->weight;

            if ($roll <= 0) {
                return $row->rarity->value;
            }
        }

        return null;
    }

    /** A per-mille chance, leaned on by the machine's type. */
    private function hits(int $perMille, int $lean): bool
    {
        $chance = (int) round($perMille * $lean / 1000);

        return $chance > 0 && ($this->roll)(1, 1000) <= $chance;
    }

    /**
     * A [min, max] pair for this grade, from a dotted config key.
     *
     * Falls back to the lowest grade's pair rather than throwing: a grade
     * nobody has written numbers for is a content gap, and a world that will
     * not restock because of one is worse than a poor node.
     *
     * @return array{int, int}
     */
    private function range(string $key, int $grade): array
    {
        $table = (array) data_get($this->config, $key, []);
        $pair = $table[$grade] ?? reset($table) ?: [1, 1];

        return [(int) $pair[0], (int) $pair[1]];
    }
}
