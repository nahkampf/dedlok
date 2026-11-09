<?php

declare(strict_types=1);

namespace App\Combat;

use App\Combat\Fight\FightState;
use App\Models\Character;
use App\Stats\CombatStats;
use App\Stats\ResolvedStat;

/**
 * WHY an action's chance is what the button says: the player's number with every step that built it, the opponent's
 * number it is rolled against, and the margin between them. Display only: the roll is the engine's, and the numbers
 * read here are the ones `CombatantBuilder` handed it.
 *
 * Keyed `attack`, `headbutt`, `takedown`, `close_in`, `create_distance` and `use` (Disengage is rolled the same way as Create distance).
 */
final class ChanceExplainer
{
    /** @return array<string, array{yours_label: string, yours: float, base: float, steps: list<array{op: string, amount: float, sources: list<string>}>, extras: list<array{label: string, amount: float}>, theirs_label: string, theirs: float, margin: float}> */
    public static function for(Character $character, FightState $state): array
    {
        $foe = $state->b;
        $out = [
            'close_in' => self::entry('pursuit', $character->stat('pursuit'), [], 'defence', $foe->defence),
            'create_distance' => self::entry('defence', $character->stat('defence'), [], 'pursuit', $foe->pursuit),
            // Getting something off the belt with the opponent on you: the same two numbers as backing off.
            'use' => self::entry('defence', $character->stat('defence'), [], 'pursuit', $foe->pursuit),
        ];

        $edge = $state->a->status(RiderKind::Edge) !== null;
        $fists = $state->a->fists();

        if ($fists !== null) {
            $aim = $character->stat(CombatStats::aimId($fists->class));
            $out['headbutt'] = self::entry('aim', $aim, abs($fists->aim - $aim->value) < 0.000001 ? [] : [['label' => 'accuracy', 'amount' => $fists->aim - $aim->value]], 'defence', $foe->defence) + ['edge' => $edge];
        }

        // Muscle and motor as a pool, plus what skills have added to unarmed aim on top of its own pool.
        $unarmed = $character->stat(CombatStats::aimId(WeaponClass::Unarmed));
        $bonus = $unarmed->value - $unarmed->base;
        $out['takedown'] = self::entry('takedown', $character->stat('takedown'), abs($bonus) < 0.000001 ? [] : [['label' => 'unarmed_bonus', 'amount' => $bonus]], 'defence', $foe->defence) + ['edge' => false];

        $arm = $state->a->arm($state->range);

        if ($arm !== null) {
            $aim = $character->stat(CombatStats::aimId($arm->class));
            $accuracy = $arm->aim - $aim->value;
            $out['attack'] = self::entry('aim', $aim, abs($accuracy) < 0.000001 ? [] : [['label' => 'accuracy', 'amount' => $accuracy]], 'defence', $foe->defence) + ['edge' => $edge];
        }

        return $out;
    }

    /**
     * @param  list<array{label: string, amount: float}>  $extras
     * @return array{yours_label: string, yours: float, base: float, steps: list<array{op: string, amount: float, sources: list<string>}>, extras: list<array{label: string, amount: float}>, theirs_label: string, theirs: float, margin: float}
     */
    private static function entry(string $label, ResolvedStat $stat, array $extras, string $theirsLabel, float $theirs): array
    {
        $yours = $stat->value + array_sum(array_column($extras, 'amount'));

        return [
            'yours_label' => $label,
            'yours' => $yours,
            'base' => $stat->base,
            // Clamping and rounding are bookkeeping, not something a modifier did.
            'steps' => array_values(array_map(
                static fn ($s): array => ['op' => $s->op, 'amount' => $s->amount, 'sources' => $s->sources],
                array_filter($stat->steps, static fn ($s): bool => ! in_array($s->op, ['clamp', 'round'], true)),
            )),
            'extras' => $extras,
            'theirs_label' => $theirsLabel,
            'theirs' => $theirs,
            'margin' => $yours - $theirs,
        ];
    }
}
