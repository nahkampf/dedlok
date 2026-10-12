<?php

declare(strict_types=1);

namespace App\City;

use App\Models\Encounter;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of an encounter's nodes, choices and outcomes.
 *
 * REPLACES THEM WHOLESALE on every save, which is safe because nothing points at
 * their ids: a live encounter carries `node_key` and the graph is wired by key, so
 * a character halfway through one is unaffected by an author saving it (and a node
 * that no longer exists closes the encounter gracefully). The encounter row itself
 * is updated, never replaced.
 */
final class EncounterEditor
{
    /**
     * @param  list<array<string, mixed>>  $nodes  Cleaned by `EncounterRequest`: each with `choices`, each with `outcomes`.
     * @param  list<array<string, mixed>>|null  $prerequisites  Cleaned by `EncounterRequest`; null leaves them as they are.
     */
    public static function apply(Encounter $encounter, array $nodes, ?array $prerequisites = null): void
    {
        DB::transaction(function () use ($encounter, $nodes, $prerequisites): void {
            // Replaced wholesale like the graph, but only when the form sent them: a caller that does not
            // know about prerequisites must not clear somebody else's.
            if ($prerequisites !== null) {
                $encounter->prerequisites()->delete();

                foreach (array_values($prerequisites) as $position => $row) {
                    $encounter->prerequisites()->create([...$row, 'position' => $position]);
                }
            }

            $encounter->nodes()->delete();

            foreach ($nodes as $row) {
                $node = $encounter->nodes()->create([
                    'node_key' => $row['key'], 'body' => $row['body'], 'entry' => $row['entry'], 'ends' => $row['ends'],
                    'run_end' => $row['run_end'] ?? null,
                ]);

                // What the screen itself does on arrival: the same rows, owned by the screen.
                foreach ($row['outcomes'] as $i => $outcome) {
                    $node->outcomes()->create([...$outcome, 'position' => $i]);
                }

                foreach ($row['choices'] as $position => $choice) {
                    $outcomes = $choice['outcomes'];
                    unset($choice['outcomes']);
                    $made = $node->choices()->create([...$choice, 'position' => $position]);

                    foreach ($outcomes as $i => $outcome) {
                        $made->outcomes()->create([...$outcome, 'position' => $i]);
                    }
                }
            }
        });
    }

    /**
     * The stored prerequisites as the form's rows.
     *
     * @return list<array<string, mixed>>
     */
    public static function prerequisiteRows(Encounter $encounter): array
    {
        return $encounter->prerequisites()->get()->map(static fn ($p): array => [
            'kind' => $p->kind->value, 'subject' => $p->subject, 'amount' => $p->amount,
            'item_id' => $p->item_id, 'entity_id' => $p->entity_id, 'negate' => $p->negate,
        ])->all();
    }

    /**
     * The stored encounter as the form's rows.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(Encounter $encounter): array
    {
        $rows = [];

        foreach ($encounter->nodes()->with(['outcomes', 'choices.outcomes'])->orderBy('id')->get() as $node) {
            $choices = [];

            foreach ($node->choices as $c) {
                $outcomes = [];

                foreach ($c->outcomes as $o) {
                    $outcomes[] = [
                        'branch' => $o->branch->value, 'kind' => $o->kind->value, 'amount' => $o->amount,
                        'ref' => $o->ref, 'item_id' => $o->item_id, 'entity_id' => $o->entity_id,
                    ];
                }

                $choices[] = [
                    'label' => $c->label, 'requires_flag' => $c->requires_flag, 'cost' => $c->cost,
                    'next_key' => $c->next_key, 'check_stat' => $c->check_stat, 'difficulty' => $c->difficulty,
                    'pass_key' => $c->pass_key, 'fail_key' => $c->fail_key,
                    'fight_npc_id' => $c->fight_npc_id, 'win_key' => $c->win_key, 'lose_key' => $c->lose_key, 'flee_key' => $c->flee_key,
                    'outcomes' => $outcomes,
                ];
            }

            $arrival = [];

            foreach ($node->outcomes as $o) {
                $arrival[] = [
                    'branch' => $o->branch->value, 'kind' => $o->kind->value, 'amount' => $o->amount,
                    'ref' => $o->ref, 'item_id' => $o->item_id, 'entity_id' => $o->entity_id,
                ];
            }

            $rows[] = ['key' => $node->node_key, 'body' => $node->body, 'entry' => $node->entry, 'ends' => $node->ends, 'run_end' => $node->run_end, 'outcomes' => $arrival, 'choices' => $choices];
        }

        return $rows;
    }
}
