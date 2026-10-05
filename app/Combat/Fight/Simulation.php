<?php

declare(strict_types=1);

namespace App\Combat\Fight;

use App\Checks\CheckRoller;

/**
 * N fights of one snapshot against another, with the same engine a real fight uses and a seeded die,
 * so a balance question has a repeatable answer. Never writes: it plays states in memory.
 *
 * The player's side plays a simple policy — attack when it can, load when it cannot, brace when it
 * has nothing — because the number being asked for is how a loadout fares, not how well it is played.
 * A fight that has not ended after `$cap` rounds is counted as a draw rather than looping.
 */
final class Simulation
{
    public function __construct(private readonly FightEngine $engine, private readonly int $cap = 60) {}

    public static function make(): self
    {
        return new self(new FightEngine(CheckRoller::fromConfig()));
    }

    /**
     * @return array{runs: int, wins: int, losses: int, escapes: int, draws: int, rounds: float, rounds_min: int, rounds_max: int, hp_lost_on_wins: float, hp_lost_share_on_wins: float, quote: list<float>}
     */
    public function run(FightState $opening, Mitigation $mitigation, int $runs, int $seed): array
    {
        mt_srand($seed);
        $dice = static fn (): float => mt_rand() / (mt_getrandmax() + 1);

        $wins = $losses = $escapes = $draws = 0;
        $rounds = [];
        $lost = [];

        for ($i = 0; $i < $runs; $i++) {
            $state = $opening;

            while ($state->result === null && $state->round <= $this->cap) {
                $state = $this->engine->round($state, ['a' => $this->policy($state)], $dice, $mitigation);
            }

            $rounds[] = $state->round;

            match (true) {
                $state->result === Result::BDown => [$wins++, $lost[] = $opening->a->hp - $state->a->hp],
                $state->result === Result::ADown => $losses++,
                $state->result !== null => $escapes++,
                default => $draws++,
            };
        }

        $maxHp = max(1, (int) ($opening->a->hpMax ?? $opening->a->hp));

        return [
            'runs' => $runs, 'wins' => $wins, 'losses' => $losses, 'escapes' => $escapes, 'draws' => $draws,
            'rounds' => array_sum($rounds) / max(1, $runs), 'rounds_min' => $rounds === [] ? 0 : min($rounds), 'rounds_max' => $rounds === [] ? 0 : max($rounds),
            'hp_lost_on_wins' => $lost === [] ? 0.0 : array_sum($lost) / count($lost),
            'hp_lost_share_on_wins' => $lost === [] ? 0.0 : array_sum($lost) / count($lost) / $maxHp,
            'quote' => $this->engine->quote($opening, Side::A),
        ];
    }

    /** What the simulated player does: attack, else load what they can, else brace. */
    public function policy(FightState $state): Action
    {
        if ($this->engine->canAttack($state, Side::A)) {
            return Action::attack();
        }

        $ranged = $state->a->ranged;

        if ($ranged !== null) {
            foreach (array_keys($ranged->specs) as $key) {
                if ($ranged->canLoad((string) $key)) {
                    return Action::load((string) $key);
                }
            }
        }

        return Action::brace();
    }
}
