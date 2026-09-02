<?php

declare(strict_types=1);

namespace App\Skills;

use App\Models\Skill;

/**
 * One skill's place in its category's tree.
 *
 * `depth` is the longest chain of prerequisites behind it, not the number of
 * parents it has — a skill gated behind two independent chains sits below the
 * deeper of them, because that is when it actually becomes reachable.
 */
final class SkillTreeNode
{
    /**
     * @param  list<SkillTreeNode>  $children
     * @param  list<array{skill: Skill, level: int}>  $alsoRequires
     */
    public function __construct(
        public readonly Skill $skill,
        public readonly int $depth,
        public readonly ?int $requiredLevel,
        public readonly ?Skill $requiredSkill = null,
        public array $children = [],
        public readonly array $alsoRequires = [],
        public readonly bool $unreachable = false,
    ) {}

    /**
     * Every requirement, the drawn one first.
     *
     * The edge to a node's parent is a requirement like any other, and reads
     * as one: "Cyberdecks 3" says more than "from level 3" does, and says it
     * the same way the requirements a tree could NOT draw already say it.
     *
     * @return list<array{skill: Skill, level: int}>
     */
    public function requirements(): array
    {
        $parent = $this->requiredSkill === null || $this->requiredLevel === null
            ? []
            : [['skill' => $this->requiredSkill, 'level' => $this->requiredLevel]];

        return [...$parent, ...$this->alsoRequires];
    }

    /** This skill plus everything hanging off it. */
    public function descendantCount(): int
    {
        return array_reduce(
            $this->children,
            fn (int $carry, self $child): int => $carry + $child->descendantCount(),
            1,
        );
    }

    public function isRoot(): bool
    {
        return $this->requiredLevel === null;
    }
}
