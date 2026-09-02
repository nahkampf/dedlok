<?php

declare(strict_types=1);

namespace App\Skills;

use App\Models\Skill;

/**
 * One skill's place in its category's list.
 *
 * There is no nesting any more — the categories are collapsible sections, and
 * a section IS the grouping the indentation used to draw. What is left of the
 * graph is the only part a player needs from it: what this skill wants before
 * they can learn it.
 *
 * The list is alphabetical. Depth is still computed while building, because
 * that walk is what catches a prerequisite cycle, but it no longer orders
 * anything and so is not carried here.
 */
final class SkillTreeNode
{
    /** @param  list<array{skill: Skill, level: int}>  $requirements */
    public function __construct(
        public readonly Skill $skill,
        public readonly array $requirements = [],
        public readonly bool $unreachable = false,
    ) {}

    /** Can this be taken with nothing else on the sheet first? */
    public function isOpen(): bool
    {
        return $this->requirements === [];
    }
}
