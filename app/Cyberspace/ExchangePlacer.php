<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Entity;
use App\Models\Exchange;
use App\Models\System;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Puts every interactable entity's front desks on the grid.
 *
 * A TOP-UP AND NEVER A RE-ROLL, like `MarketSeeder` and `GridSeeder`: a desk
 * that exists is left exactly where it is. An Exchange is the one system a
 * player is promised will still be there next week — nothing raids it, shuts
 * it or moves it — so a pass that relocated one would break the only promise
 * this type makes.
 *
 * ## Which desks an entity has
 *
 * Grade 1 up to whatever `cyberspace.exchanges.grades` gives them, because
 * most organisations run the bottom of the ladder and few run the top. The
 * roster is authored in config rather than rolled: which entity keeps a grade
 * 5 desk is a fact about the world, and a world that re-rolled it on every
 * pass would be a different world each time.
 *
 * ## Where they sit
 *
 * HIGHER GRADES SIT DEEPER, the way system grade already tracks distance from
 * the portal. Each desk is placed in a ring measured from the portal, so
 * reaching a grade 5 agent is a journey rather than a walk — and the gate on
 * the desk is about who you are, not where you are, so the two ask different
 * questions of the same player.
 *
 * The die is injected, like every other roll in cyberspace.
 */
final readonly class ExchangePlacer
{
    /** @param  Closure(int, int): int  $roll */
    public function __construct(
        private Grid $grid,
        private SystemNamer $namer,
        private Closure $roll,
    ) {}

    /** @param  Closure(int, int): int|null  $roll */
    public static function fromConfig(?Closure $roll = null): self
    {
        $roll ??= static fn (int $min, int $max): int => random_int($min, $max);

        return new self(
            Grid::fromConfig(),
            SystemNamer::fromConfig($roll),
            $roll,
        );
    }

    /**
     * Place what is missing, and report what was placed.
     *
     * @return list<array{entity: string, grade: int, position: string, agent: string}>
     */
    public function run(bool $dryRun = false): array
    {
        $grades = (array) Config::get('cyberspace.exchanges.grades', []);
        $taken = $this->grid->occupiedPositions();
        $placed = [];

        foreach (Entity::query()->interactable()->orderBy('id')->get() as $entity) {
            $wanted = (int) ($grades[$entity->name] ?? Config::get('cyberspace.exchanges.default_grades', 3));

            for ($grade = 1; $grade <= $wanted; $grade++) {
                if ($this->has($entity, $grade)) {
                    continue;
                }

                $position = $this->ringPosition($grade, $taken);

                if ($position === null) {
                    // Nowhere left at that depth is not an error: the world
                    // may simply be full there, and the desk can be placed by
                    // a later pass once something moves.
                    continue;
                }

                $taken["{$position->x},{$position->y}"] = true;
                $agent = $this->agentName();

                $placed[] = [
                    'entity' => $entity->name,
                    'grade' => $grade,
                    'position' => (string) $position,
                    'agent' => $agent,
                ];

                if ($dryRun) {
                    continue;
                }

                $this->place($entity, $grade, $position, $agent);
            }
        }

        return $placed;
    }

    /** Does this entity already keep a desk of this grade? */
    private function has(Entity $entity, int $grade): bool
    {
        return Exchange::query()
            ->where('grade', $grade)
            ->whereHas('system', fn ($query) => $query->where('owner_id', $entity->getKey()))
            ->exists();
    }

    /**
     * A free position for a desk of this grade.
     *
     * The rings divide the distance from the portal to the corner into five,
     * so grade 1 sits in the innermost fifth and grade 5 in the outermost.
     *
     * **THE DEPTH IS A FLOOR AND THE BAND IS A PREFERENCE.** The innermost
     * ring is nine cells on a small grid and ten entities all want a desk in
     * it, so a band that refused to give would leave organisations without a
     * front door. The first pass asks for the band; the second asks only to be
     * no closer than the band's floor, which is the half of the rule that
     * matters — a grade 5 desk must never sit next to the portal.
     */
    private function ringPosition(int $grade, array $taken): ?Position
    {
        $portal = $this->grid->portal();
        $size = $this->grid->size();
        $step = max(1, (int) floor($size / 2 / 5));

        $inner = ($grade - 1) * $step;
        $outer = $grade === 5 ? $size : $grade * $step;

        foreach ([$outer, $size] as $limit) {
            for ($try = 0; $try < 400; $try++) {
                $position = new Position(
                    ($this->roll)(0, $size - 1),
                    ($this->roll)(0, $size - 1),
                );

                $distance = $position->distanceTo($portal);

                if ($distance < $inner || $distance > $limit) {
                    continue;
                }

                if (isset($taken["{$position->x},{$position->y}"]) || ! $this->grid->isEmpty($position)) {
                    continue;
                }

                return $position;
            }
        }

        return null;
    }

    /** The system and its desk, written together or not at all. */
    private function place(Entity $entity, int $grade, Position $position, string $agent): void
    {
        DB::transaction(function () use ($entity, $grade, $position, $agent): void {
            $system = System::query()->create([
                'address' => Address::generate(),
                'x' => $position->x,
                'y' => $position->y,
                'type' => SystemType::Exchange,
                // Its own grade says difficulty, and nothing may be done to a
                // desk — so it stays at the floor, the way the portal's does.
                'grade' => Grade::MIN,
                'designation' => $this->namer->designate(),
                'owner_id' => $entity->getKey(),
            ]);

            Exchange::query()->create([
                'system_id' => $system->getKey(),
                'grade' => $grade,
                'agent' => $agent,
                'min_standing' => (float) (Config::get("cyberspace.exchanges.gate.{$grade}", 0)),
            ]);
        });
    }

    /** A person rather than a department: one name, drawn from two lists. */
    private function agentName(): string
    {
        $first = (array) Config::get('cyberspace.exchanges.agents.first', []);
        $last = (array) Config::get('cyberspace.exchanges.agents.last', []);

        if ($first === [] || $last === []) {
            return 'Agent';
        }

        return $first[($this->roll)(0, count($first) - 1)]
            .' '
            .$last[($this->roll)(0, count($last) - 1)];
    }
}
