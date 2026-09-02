<?php

declare(strict_types=1);

namespace App\Skills;

use App\Models\Skill;

/**
 * One skill's place in its category's list.
 *
 * There is no nesting any more — the categories are collapsible sections, and
 * a section IS the grouping the indentation used to draw. What is left of the
 * graph is what a player actually needs from it: what this skill wants before
 * they can learn it, and where it sits in the order things unlock.
 *
 * `depth` is the longest chain of prerequisites behind the skill, not how many
 * it has. It orders the list rather than indenting it: a skill appears below
 * everything it needs.
 */
final class SkillTreeNode
{
    /** @param  list<array{skill: Skill, level: int}>  $requirements */
    public function __construct(
        public readonly Skill $skill,
        public readonly int $depth,
        public readonly array $requirements = [],
        public readonly bool $unreachable = false,
    ) {}

    /** Can this be taken with nothing else on the sheet first? */
    public function isOpen(): bool
    {
        return $this->requirements === [];
    }
}
