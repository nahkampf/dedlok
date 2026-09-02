<?php

declare(strict_types=1);

namespace App\Skills;

use App\Models\Skill;
use Illuminate\Support\Collection;

/**
 * The skill graph, arranged as one tree per category.
 *
 * IT IS NOT ACTUALLY A TREE, and that is the whole problem this class exists
 * to solve. Prerequisites form a directed acyclic graph: a skill may require
 * several others, and `advancedsystemprofiling` already requires two. A tree
 * gives every node one parent, so something has to give.
 *
 * What gives is the *extra* parents, not the information. A skill hangs under
 * its DEEPEST prerequisite — the one that gates it last, and so the one whose
 * branch it genuinely extends — and every other requirement is carried on the
 * node as `alsoRequires`, for the view to state outright. Nothing is hidden and
 * nothing is duplicated: each skill appears exactly once, in its own category.
 *
 * Cycles are a data error that `skills:validate` catches, but this must not
 * hang on one, so depth resolution refuses to walk a skill it is already
 * walking and marks what it finds unreachable.
 */
final class SkillTree
{
    /** @var array<int, Skill> */
    private array $skills = [];

    /** @var array<int, list<array{id: int, level: int}>> */
    private array $requirements = [];

    /** @var array<int, int|null> */
    private array $depths = [];

    /** @var array<int, true> */
    private array $walking = [];

    /** @param  Collection<int, Skill>  $skills  with prerequisites loaded */
    public function __construct(Collection $skills)
    {
        foreach ($skills as $skill) {
            $this->skills[$skill->id] = $skill;

            $this->requirements[$skill->id] = $skill->prerequisites
                ->map(fn ($prerequisite): array => [
                    'id' => (int) $prerequisite->required_skill_id,
                    'level' => (int) $prerequisite->required_level,
                ])
                ->all();
        }
    }

    /**
     * Every category, in name order, each with its roots.
     *
     * @return array<string, list<SkillTreeNode>>
     */
    public function categories(): array
    {
        $nodes = [];
        $childrenOf = [];
        $roots = [];

        foreach ($this->skills as $id => $skill) {
            $parent = $this->primaryParent($id);

            $nodes[$id] = new SkillTreeNode(
                skill: $skill,
                depth: $this->depth($id) ?? 0,
                requiredLevel: $parent['level'] ?? null,
                requiredSkill: isset($parent['id']) ? $this->skills[$parent['id']] : null,
                alsoRequires: $this->alsoRequires($id, $parent['id'] ?? null),
                unreachable: $this->depth($id) === null,
            );

            if ($parent === null) {
                $roots[] = $id;
            } else {
                $childrenOf[$parent['id']][] = $id;
            }
        }

        foreach ($nodes as $id => $node) {
            $node->children = array_map(
                fn (int $childId): SkillTreeNode => $nodes[$childId],
                $this->sorted($childrenOf[$id] ?? []),
            );
        }

        $categories = [];

        foreach ($this->sorted($roots) as $id) {
            $categories[$this->skills[$id]->category][] = $nodes[$id];
        }

        ksort($categories);

        return $categories;
    }

    /**
     * The prerequisite this skill hangs under, or null if it hangs under none.
     *
     * Deepest first, so the node lands at the end of the longest chain gating
     * it. Ties break on the higher required level and then on title, which is
     * arbitrary but stable — a tree that reorders itself between page loads is
     * worse than one that picks a side.
     *
     * A prerequisite in ANOTHER CATEGORY cannot be a parent: lanes are the
     * point of the layout, and a node cannot hang in two of them. Those become
     * `alsoRequires` too, and the skill sits at its own lane's root.
     *
     * @return array{id: int, level: int}|null
     */
    private function primaryParent(int $id): ?array
    {
        $category = $this->skills[$id]->category;

        $candidates = array_values(array_filter(
            $this->requirements[$id],
            fn (array $requirement): bool => isset($this->skills[$requirement['id']])
                && $this->skills[$requirement['id']]->category === $category
                && $this->depth($requirement['id']) !== null,
        ));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => [$this->depth($b['id']), $b['level'], $this->skills[$a['id']]->title]
            <=> [$this->depth($a['id']), $a['level'], $this->skills[$b['id']]->title]);

        return $candidates[0];
    }

    /**
     * Every requirement that is not the one it hangs under.
     *
     * @return list<array{skill: Skill, level: int}>
     */
    private function alsoRequires(int $id, ?int $parentId): array
    {
        $extra = [];

        foreach ($this->requirements[$id] as $requirement) {
            if ($requirement['id'] === $parentId || ! isset($this->skills[$requirement['id']])) {
                continue;
            }

            $extra[] = [
                'skill' => $this->skills[$requirement['id']],
                'level' => $requirement['level'],
            ];
        }

        usort($extra, fn (array $a, array $b): int => $a['skill']->title <=> $b['skill']->title);

        return $extra;
    }

    /**
     * How long the longest chain behind this skill is, or null inside a cycle.
     *
     * Memoised, because a wide graph asks for the same answer repeatedly, and
     * guarded, because a cycle would otherwise recurse until the stack runs
     * out. skills:validate is what reports the cycle; this only has to survive
     * one long enough to render the rest.
     */
    private function depth(int $id): ?int
    {
        if (array_key_exists($id, $this->depths)) {
            return $this->depths[$id];
        }

        if (isset($this->walking[$id])) {
            return null;   // a cycle: this skill is its own prerequisite
        }

        $this->walking[$id] = true;

        $deepest = -1;

        foreach ($this->requirements[$id] as $requirement) {
            if (! isset($this->skills[$requirement['id']])) {
                continue;
            }

            $behind = $this->depth($requirement['id']);

            if ($behind === null) {
                unset($this->walking[$id]);

                return $this->depths[$id] = null;
            }

            $deepest = max($deepest, $behind);
        }

        unset($this->walking[$id]);

        return $this->depths[$id] = $deepest + 1;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        usort($ids, fn (int $a, int $b): int => $this->skills[$a]->title <=> $this->skills[$b]->title);

        return $ids;
    }
}
