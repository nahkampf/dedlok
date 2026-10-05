<?php

declare(strict_types=1);

namespace App\City;

/**
 * The shape of one encounter, as keys — pure, so the editor can ask about a draft
 * that does not exist yet (`App\Skills\PrerequisiteGraph`'s own shape).
 *
 * SQL cannot see a graph, so "no player can be trapped" is asked here. A node is
 * STUCK when no ending can be reached from it: every path loops. Loops themselves
 * are fine — "ask another question" is a loop — a trap is not.
 *
 * A target that names no node closes the encounter at run time (the runner treats a
 * missing node as an ending), so it counts as a way out here too; the request
 * refuses a mistyped key long before that matters.
 */
final class EncounterGraph
{
    /**
     * @param  array<string, array{ends: bool, choices: list<array{next: ?string, pass: ?string, fail: ?string, check: bool, fight?: array{win: ?string, lose: ?string, flee: ?string}}>}>  $nodes
     */
    public function __construct(private readonly array $nodes) {}

    /**
     * Where a choice can lead: one target without a check, two with one. NULL is "closes".
     *
     * A FIGHT choice goes three ways, by how the fight ends, and has no `next` of its own — without this it
     * would read as a button that closes the encounter, and a screen whose only way out is a fight that loops
     * back would never be reported as a trap.
     *
     * @param  array{next: ?string, pass: ?string, fail: ?string, check: bool, fight?: array{win: ?string, lose: ?string, flee: ?string}}  $choice
     * @return list<?string>
     */
    public static function targets(array $choice): array
    {
        if (isset($choice['fight'])) {
            return [$choice['fight']['win'], $choice['fight']['lose'], $choice['fight']['flee']];
        }

        return $choice['check']
            ? [$choice['pass'] ?? $choice['next'], $choice['fail'] ?? $choice['next']]
            : [$choice['next']];
    }

    /** @return list<string> keys of the nodes no ending can be reached from */
    public function stuck(): array
    {
        $closing = [];

        do {
            $grew = false;

            foreach ($this->nodes as $key => $node) {
                if (isset($closing[$key]) || ! $this->closes($node, $closing)) {
                    continue;
                }

                $closing[$key] = true;
                $grew = true;
            }
        } while ($grew);

        return array_values(array_diff(array_keys($this->nodes), array_keys($closing)));
    }

    /**
     * @param  array{ends: bool, choices: list<array{next: ?string, pass: ?string, fail: ?string, check: bool, fight?: array{win: ?string, lose: ?string, flee: ?string}}>}  $node
     * @param  array<string, true>  $closing
     */
    private function closes(array $node, array $closing): bool
    {
        if ($node['ends']) {
            return true;
        }

        foreach ($node['choices'] as $choice) {
            foreach (self::targets($choice) as $target) {
                if ($target === null || ! isset($this->nodes[$target]) || isset($closing[$target])) {
                    return true;
                }
            }
        }

        return false;
    }
}
