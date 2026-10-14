<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\Hunt;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Programs\ProcessRefusal;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Stats\Modifier;
use App\Stats\Resolver;
use App\Support\Trace;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Somebody comes for you, on the open grid.
 *
 * THE SECOND THING HEAT DOES, rolled on every move rather than once a night —
 * `App\Cyberspace\Navigator::arrive()` is the one place all four ways of
 * moving already meet, the same call the sweep makes. Never a check: this is
 * the world deciding about you, the same shape `App\Characters\Bounties`
 * already uses, just capped rather than certain — see `config/cyberspace.php`,
 * `heat.hunt`, for why the two curves cannot share a shape.
 *
 * A HUNTER IS AN ICE, ON THE FLY. `spawn()` draws a real row from the twelve
 * in the catalogue, within the level range the rolled GRADE allows, and
 * snapshots it onto a `Hunt` exactly as a run snapshots one onto itself. That
 * is the whole of how Ensnare and every existing ICE resistance reach a
 * hunter with no new code: the fight, in `HunterCombat`, deals
 * `damage.synaptic_integrity.ice`, because it is that damage.
 *
 * THE LOADOUT IS DECIDED HERE, NOT REMEMBERED. Unlike a run's briefing, there
 * is no screen to choose one on — a hunter does not wait for you to get
 * ready. So the strongest owned icebreaker this deck's CURRENT budget can
 * actually run is started automatically, the way a run's briefing would have,
 * through the same `ProgramRunner::start(..., duringRun: true)` every fit
 * check that class already owns. Owning nothing usable is not a special case:
 * `icebreaker_damage` simply resolves to what the character's own modifiers
 * give it, which may be zero, and a fight that cannot be won still ends —
 * on the floor, same as any other defeat.
 */
final class Hunters
{
    /** @param  Closure(int, int): int  $roll */
    public function __construct(
        private readonly Resolver $resolver,
        private readonly ProgramRunner $programs,
        private readonly float $floor,
        private readonly int $floorBelow,
        private readonly int $certainAt,
        private readonly float $cap,
        private readonly Closure $roll,
    ) {}

    /** @param  ?Closure(int, int): int  $roll */
    public static function fromConfig(Resolver $resolver, ?Closure $roll = null): self
    {
        $roll ??= Trace::die(static fn (int $min, int $max): int => random_int($min, $max));

        return new self(
            $resolver,
            new ProgramRunner,
            (float) Config::get('cyberspace.heat.hunt.chance_at_floor', 0.5) / 100,
            (int) Config::get('cyberspace.heat.hunt.floor_below', 5),
            (int) Config::get('cyberspace.heat.hunt.certain_at', 100),
            (float) Config::get('cyberspace.heat.hunt.cap', 35.0) / 100,
            $roll,
        );
    }

    /**
     * The odds a hunter appears this move, for a character carrying this much
     * heat. Public and separate from the roll, like every other chance in the
     * game: a screen that warned somebody and the roll that judges them must
     * not be able to disagree. Nothing quotes it yet.
     */
    public function chanceFor(float $heat): float
    {
        if ($heat < $this->floorBelow) {
            return 0.0;
        }

        if ($heat >= $this->certainAt) {
            return $this->cap;
        }

        $span = max(1, $this->certainAt - $this->floorBelow);

        return $this->floor + (($heat - $this->floorBelow) / $span) * ($this->cap - $this->floor);
    }

    /**
     * Does one appear, this move?
     *
     * Ten thousandths and inside the bound, the same precision the bounty
     * roll uses — so a chance of nothing never fires and a chance at the cap
     * never exceeds it.
     */
    public function rolls(Character $character): bool
    {
        $chance = $this->chanceFor((float) $character->heat);

        Trace::scope($character, 'hunt.roll', fn () => Trace::note('hunt.chance', ['heat' => (float) $character->heat, 'chance' => $chance]));

        return $chance > 0.0 && ($this->roll)(1, 10_000) <= (int) round($chance * 10_000);
    }

    /**
     * One appears. Draws the ICE, starts the best loadout this deck can run,
     * and writes the row in one transaction — a hunt recorded without a
     * loadout running against it would be a fight nobody can act on.
     */
    public function spawn(Character $character): Hunt
    {
        return Trace::scope($character, 'hunt.spawn', fn (): Hunt => $this->spawnTraced($character));
    }

    private function spawnTraced(Character $character): Hunt
    {
        $heat = (float) $character->heat;
        $grade = $this->gradeFor($heat);
        $ice = $this->drawIce($grade);

        return DB::transaction(function () use ($character, $ice, $grade, $heat): Hunt {
            $hunt = Hunt::query()->create([
                'character_id' => $character->id,
                'ice_id' => $ice->id,
                'ice_integrity' => $ice->integrity,
                'grade' => $grade,
                'heat_at_spawn' => $heat,
                'started_at' => now(),
            ]);

            $character->forceFill(['current_hunt_id' => $hunt->id])->save();

            $this->startBestIcebreaker($character);

            return $hunt;
        });
    }

    /** Which of the five bands this much heat falls into. */
    private function gradeFor(float $heat): int
    {
        /** @var array<int, array{heat: array{int, ?int}}> $grades */
        $grades = (array) Config::get('cyberspace.heat.hunt.grades', []);

        foreach ($grades as $grade => $band) {
            [$min, $max] = $band['heat'];

            if ($heat >= $min && ($max === null || $heat <= $max)) {
                return (int) $grade;
            }
        }

        // Heat below the lowest band's floor never reaches here — rolls()
        // already refused it — so an empty or misconfigured table is the only
        // way to land here, and the top grade is the honest answer to "heat
        // this game has never seen a band for".
        return max(array_keys($grades)) ?: 1;
    }

    /** A real ICE row, drawn at random from the level range this grade allows. */
    private function drawIce(int $grade): Ice
    {
        [$min, $max] = (array) Config::get("cyberspace.heat.hunt.grades.{$grade}.ice_levels", [1, 1]);

        $candidates = Ice::query()->whereBetween('level', [$min, $max])->orderBy('level')->get();

        if ($candidates->isEmpty()) {
            throw new \RuntimeException("No ICE catalogued between levels {$min} and {$max}, for hunter grade {$grade}.");
        }

        return $candidates[($this->roll)(0, $candidates->count() - 1)];
    }

    /**
     * The strongest owned icebreaker this deck's current budget can actually
     * run, started the way a run's briefing would have started it.
     *
     * TRIES STRONGEST FIRST AND STOPS AT THE FIRST THAT FITS, rather than
     * duplicating `ProgramRunner::start()`'s own compute, memory, thread and
     * readiness checks — it already owns every one of them, and asking it
     * twice would be a second, worse copy of the answer.
     */
    private function startBestIcebreaker(Character $character): void
    {
        // Whatever else is running holds the deck's budget; Scripts are spared, since stopping one destroys it.
        Trace::note('hunt.stopping_programs', ['running' => $character->runningProcesses()->map(fn ($r) => $r->item?->name)->all()]);
        $this->programs->stopAllButScripts($character);

        $owned = InventoryItem::query()
            ->where('character_id', $character->id)
            ->whereNull('running_on_deck_id')
            ->whereHas('item.program', static fn ($q) => $q->where('kind', ProgramKind::Icebreaker->value))
            ->with('item.program')
            ->get();

        if ($owned->isEmpty()) {
            return;
        }

        $modifiers = $character->modifiers();

        $ranked_damage = [];

        $ranked = $owned->sortByDesc(function (InventoryItem $row) use ($modifiers, &$ranked_damage): float {
            $ownModifiers = array_map(
                static fn (array $m): Modifier => Modifier::fromArray($m),
                $row->item->program->modifiers ?? [],
            );

            return $ranked_damage[$row->getKey()] = $this->resolver->resolve('icebreaker_damage', 0.0, [...$modifiers, ...$ownModifiers])->value;
        });

        // TRIES STRONGEST FIRST AND STOPS ON THE FIRST NON-FIT REFUSAL. A
        // refusal that is not about THIS program's own size (deck not ready,
        // no deck at all) would refuse every candidate identically, so
        // retrying with a weaker one buys nothing — only "too big" is worth
        // trying a smaller one for.
        $doesNotFit = [ProcessRefusal::NotEnoughCompute, ProcessRefusal::NotEnoughMemory, ProcessRefusal::NoFreeThread];

        foreach ($ranked as $row) {
            $result = $this->programs->start($character, $row, duringRun: true);

            Trace::note('hunt.icebreaker_attempt', [
                'row' => $row->getKey(), 'item' => $row->item?->name,
                'resolved_damage' => $ranked_damage[$row->getKey()] ?? null,
                'succeeded' => $result->succeeded(), 'refusal' => $result->refusal?->value ?? $result->refusal?->name,
                'tries_next' => ! $result->succeeded() && in_array($result->refusal, $doesNotFit, true),
            ]);

            if ($result->succeeded() || ! in_array($result->refusal, $doesNotFit, true)) {
                return;
            }
        }
    }
}
