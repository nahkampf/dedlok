<?php

declare(strict_types=1);

namespace App\Support;

use App\Decks\DeckTotals;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Stats\Resolver;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnitEnum;

/**
 * A verbose diagnostic log for the characters named in `config/trace.php`.
 *
 * Writes nothing for anybody else, and never throws: tracing must not be able
 * to break a turn. One entry point wraps an action (`scope()`), and the dice
 * and the checks inside it report themselves while it runs (`note()`, `die()`
 * and `CheckRoller::roll()`), so a record reads as the whole account of one
 * action — state in, every roll, state out.
 */
final class Trace
{
    /** The traced character the current action belongs to, or null when nothing is being traced. */
    private static ?Character $subject = null;

    public static function enabledFor(?Character $character): bool
    {
        if ($character === null) {
            return false;
        }

        $handles = (array) Config::get('trace.handles', []);

        return $handles !== [] && in_array(mb_strtolower((string) $character->handle), $handles, true);
    }

    public static function active(): bool
    {
        return self::$subject !== null;
    }

    /**
     * Run an action under the trace: its state before, every note made inside,
     * its result and state after (or the exception that ended it).
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @param  array<string, mixed>  $context
     * @return T
     */
    public static function scope(?Character $character, string $event, Closure $fn, array $context = []): mixed
    {
        if (! self::enabledFor($character)) {
            return $fn();
        }

        $previous = self::$subject;
        self::$subject = $character;

        self::write("{$event}.begin", $context + ['state' => self::state($character)]);

        try {
            $result = $fn();
        } catch (Throwable $e) {
            self::write("{$event}.exception", ['class' => $e::class, 'message' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()]);
            self::$subject = $previous;

            throw $e;
        }

        self::write("{$event}.end", ['result' => self::describe($result), 'state' => self::state($character)]);
        self::$subject = $previous;

        return $result;
    }

    /** One line, only while an action is being traced. */
    public static function note(string $event, array $context = []): void
    {
        if (self::$subject !== null) {
            self::write($event, $context);
        }
    }

    /**
     * Wrap an integer die so each draw is recorded with its range and the code that asked.
     *
     * @param  Closure(int, int): int  $die
     * @return Closure(int, int): int
     */
    public static function die(Closure $die): Closure
    {
        return static function (int $min, int $max) use ($die): int {
            $result = $die($min, $max);

            if (self::$subject !== null) {
                $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[1] ?? [];

                self::write('die', [
                    'range' => [$min, $max],
                    'rolled' => $result,
                    'asked_by' => ($frame['class'] ?? '').'::'.($frame['function'] ?? '?'),
                ]);
            }

            return $result;
        };
    }

    /**
     * Everything that decides what happens to this character right now: vitals, resolved stats with their
     * working, the deck and its totals, and what is running on it.
     *
     * @return array<string, mixed>
     */
    public static function state(Character $character): array
    {
        $out = [
            'id' => $character->getKey(),
            'handle' => $character->handle,
            'heat' => $character->heat,
            'creds' => $character->creds,
            'hit_points' => $character->current_hitpoints,
            'synaptic_integrity' => $character->current_synaptic_integrity,
            'grid' => $character->grid_x === null ? null : [$character->grid_x, $character->grid_y],
            'district_id' => $character->district_id,
            'current_run_id' => $character->current_run_id ?? null,
            'current_hunt_id' => $character->current_hunt_id ?? null,
            'current_fight_id' => $character->current_fight_id ?? null,
            'equipped_deck_id' => $character->equipped_deck_id,
        ];

        $out['stats'] = self::attempt(function () use ($character): array {
            $stats = [];

            foreach (['hitpoints_max', 'synaptic_integrity_max', 'stealth', 'egress', 'defence', 'routing_hops', 'scan_duration', 'program_threads'] as $id) {
                $stats[$id] = self::attempt(fn (): array => self::stat($character->stat($id)));
            }

            $stats['icebreaker_damage'] = self::attempt(fn (): array => self::stat(
                app(Resolver::class)->resolve('icebreaker_damage', 0.0, $character->modifiers()),
            ));

            return $stats;
        });

        $out['modifiers'] = self::attempt(fn (): array => array_map(
            static fn ($m): mixed => method_exists($m, 'toArray') ? $m->toArray() : self::describe($m),
            $character->modifiers(),
        ));

        $out['deck'] = self::attempt(function () use ($character): ?array {
            $deck = $character->equippedDeck;

            if ($deck === null) {
                return null;
            }

            $totals = DeckTotals::forCharacter($character, $deck, app(Resolver::class));

            return [
                'item' => $deck->item?->name,
                'totals' => [
                    'qflops' => $totals->qflops, 'quantabits' => $totals->quantabits, 'bandwidth' => $totals->bandwidth,
                    'draw' => $totals->draw, 'supply' => $totals->supply, 'thermal_load' => $totals->thermalLoad,
                    'cooling' => $totals->cooling, 'storage' => $totals->storage, 'stored' => $totals->stored,
                ],
                'ready' => $totals->isReady(),
                'unmet' => array_map(static fn ($r): mixed => $r instanceof BackedEnum ? $r->value : (string) $r->name, $totals->unmet()),
                'fitted' => $deck->fittings->map(fn ($f): array => [
                    'slot_id' => $f->deck_slot_id,
                    'module' => $f->module?->item?->name,
                ])->all(),
            ];
        });

        $out['running'] = self::attempt(fn (): array => $character->runningProcesses()->map(
            static fn (InventoryItem $row): array => [
                'row' => $row->getKey(),
                'item' => $row->item?->name,
                'kind' => $row->item?->program?->kind?->value,
                'qflops_cost' => $row->item?->program?->qflops_cost,
                'memory_cost' => $row->item?->program?->memory_cost,
                'background' => $row->item?->program?->background_process,
                'lifetime_seconds' => $row->item?->program?->lifetime_seconds,
                'started_at' => $row->started_at?->toIso8601String(),
                'modifiers' => $row->item?->program?->modifiers,
            ],
        )->all());

        $out['owned_icebreakers'] = self::attempt(fn (): array => InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->whereHas('item.program', static fn ($q) => $q->where('kind', 'icebreaker'))
            ->with('item.program')
            ->get()
            ->map(static fn (InventoryItem $row): array => [
                'row' => $row->getKey(),
                'item' => $row->item?->name,
                'running' => $row->running_on_deck_id !== null,
                'listed' => $row->isListed(),
                'qflops_cost' => $row->item?->program?->qflops_cost,
                'memory_cost' => $row->item?->program?->memory_cost,
                'modifiers' => $row->item?->program?->modifiers,
            ])->all());

        return $out;
    }

    /** A plain-data rendering of anything a result might hold: enums, models, value objects, collections. */
    public static function describe(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 4) {
            return '…';
        }

        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Closure => 'closure',
            $value instanceof \DateTimeInterface => $value->format('c'),
            $value instanceof Model => ['model' => class_basename($value), 'attributes' => $value->getAttributes()],
            is_array($value) => array_map(static fn ($v): mixed => self::describe($v, $depth + 1), $value),
            $value instanceof \Traversable => array_map(static fn ($v): mixed => self::describe($v, $depth + 1), iterator_to_array($value)),
            is_object($value) => ['object' => class_basename($value)] + array_map(
                static fn ($v): mixed => self::describe($v, $depth + 1),
                get_object_vars($value),
            ),
            default => get_debug_type($value),
        };
    }

    private static function stat(object $resolved): array
    {
        return [
            'value' => $resolved->value, 'base' => $resolved->base, 'raw' => $resolved->raw,
            'steps' => self::describe($resolved->steps),
        ];
    }

    private static function attempt(Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            return 'unavailable: '.$e->getMessage();
        }
    }

    private static function write(string $event, array $context): void
    {
        try {
            Log::channel('trace')->debug($event, ['who' => self::$subject?->handle] + $context);
        } catch (Throwable) {
            // Tracing must never break the action it describes.
        }
    }
}
