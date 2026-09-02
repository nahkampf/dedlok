<?php

declare(strict_types=1);

namespace App\Skills;

use App\Models\Skill;
use Illuminate\Support\Collection;

/**
 * The skill graph, grouped into one list per category.
 *
 * It stopped drawing a tree when the categories became collapsible sections:
 * the nesting and the section were saying the same thing, and the nesting was
 * the one that cost indentation a phone does not have. What survives is the
 * part a tree was never needed for — every skill states its own prerequisites,
 * so a skill with two of them is no longer a shape the layout has to
 * apologise for.
 *
 * ORDER carries what the indentation used to. A skill sits below everything it
 * needs, because depth — the longest chain of prerequisites behind it — sorts
 * ahead of the title. Reading a section top to bottom is reading the order
 * things unlock in.
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
     * Every category, in name order, each holding its skills in unlock order.
     *
     * @return array<string, list<SkillTreeNode>>
     */
    public function categories(): array
    {
        $categories = [];

        foreach ($this->skills as $id => $skill) {
            $categories[$skill->category][] = new SkillTreeNode(
                skill: $skill,
                depth: $this->depth($id) ?? 0,
                requirements: $this->requirementsFor($id),
                unreachable: $this->depth($id) === null,
            );
        }

        foreach ($categories as &$nodes) {
            usort($nodes, fn (SkillTreeNode $a, SkillTreeNode $b): int => [$a->depth, $a->skill->title] <=> [$b->depth, $b->skill->title]);
        }

        unset($nodes);

        ksort($categories);

        return $categories;
    }

    /**
     * What this skill wants first, in title order.
     *
     * A requirement naming a skill that is not loaded is dropped rather than
     * rendered as a hole: `skills:validate` is what reports it.
     *
     * @return list<array{skill: Skill, level: int}>
     */
    private function requirementsFor(int $id): array
    {
        $requirements = [];

        foreach ($this->requirements[$id] as $requirement) {
            if (! isset($this->skills[$requirement['id']])) {
                continue;
            }

            $requirements[] = [
                'skill' => $this->skills[$requirement['id']],
                'level' => $requirement['level'],
            ];
        }

        usort($requirements, fn (array $a, array $b): int => $a['skill']->title <=> $b['skill']->title);

        return $requirements;
    }

    /**
     * How long the longest chain behind this skill is, or null inside a cycle.
     *
     * Memoised, because a wide graph asks for the same answer repeatedly, and
     * guarded, because a cycle would otherwise recurse until the stack ran
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
}
