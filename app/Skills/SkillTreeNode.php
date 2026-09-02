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
        public array $children = [],
        public readonly array $alsoRequires = [],
        public readonly bool $unreachable = false,
    ) {}

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

    /**
     * The rank as one of the kit's five tiers.
     *
     * Ranks are 1, 2, 4, 8, 16 — a training-time multiplier, so a higher rank
     * is a longer haul rather than a better skill. It maps onto t1–t5 because
     * the kit already reserves those for exactly this: how rare or costly a
     * thing is, shown as a hairline accent rather than a fill.
     */
    public function tierClass(): string
    {
        return 't'.min(5, (int) log(max(1, $this->skill->rank), 2) + 1);
    }
}
