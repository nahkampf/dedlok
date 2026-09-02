<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use App\Models\SkillPrerequisite;
use App\Skills\SkillProgression;
use App\Skills\TrainingQueueValidator;
use Illuminate\Console\Command;

/**
 * Checks the skill tables for the mistakes SQL cannot express.
 *
 * Skill definitions live in the database, so they get none of the protection
 * config content gets from tests and code review. This is the replacement, and
 * it belongs in CI and after any bulk edit.
 */
class ValidateSkills extends Command
{
    protected $signature = 'skills:validate';

    protected $description = 'Check skill definitions and character progress for inconsistencies';

    private int $problems = 0;

    public function handle(): int
    {
        $progression = SkillProgression::fromConfig();

        $this->checkRanks($progression);
        $this->checkAttributes();
        $this->checkPrerequisiteLevels($progression);
        $this->checkPrerequisiteCycles();
        $this->checkStoredLevels($progression);
        $this->checkPointCaps($progression);
        $this->checkTrainingQueues();

        $this->newLine();

        if ($this->problems > 0) {
            $this->error("{$this->problems} problem(s) found.");

            return self::FAILURE;
        }

        $this->info('Skills are consistent.');

        return self::SUCCESS;
    }

    private function checkRanks(SkillProgression $progression): void
    {
        $this->section('Ranks');

        foreach (Skill::all() as $skill) {
            if (! $progression->isValidRank($skill->rank)) {
                $this->problem("[{$skill->slug}] has rank {$skill->rank}, not one of ".
                    implode(', ', $progression->ranks()));
            }
        }
    }

    private function checkAttributes(): void
    {
        $this->section('Governing attributes');

        $valid = Character::ATTRIBUTES;

        foreach (Skill::all() as $skill) {
            foreach (['primary_attribute', 'secondary_attribute'] as $column) {
                if (! in_array($skill->{$column}, $valid, true)) {
                    $this->problem("[{$skill->slug}] {$column} is [{$skill->{$column}}], not an attribute");
                }
            }

            // Not an error, but almost always a copy-paste slip: a skill whose
            // secondary is its primary earns points from one attribute twice.
            if ($skill->primary_attribute === $skill->secondary_attribute) {
                $this->warn("  ! [{$skill->slug}] has the same primary and secondary attribute");
            }
        }
    }

    private function checkPrerequisiteLevels(SkillProgression $progression): void
    {
        $this->section('Prerequisite levels');

        foreach (SkillPrerequisite::with(['skill', 'requiredSkill'])->get() as $prerequisite) {
            $level = $prerequisite->required_level;

            if ($level < 1 || $level > $progression->maxLevel()) {
                $this->problem(
                    "[{$prerequisite->skill?->slug}] requires [{$prerequisite->requiredSkill?->slug}] ".
                    "at level {$level}, outside 1..{$progression->maxLevel()}"
                );
            }
        }
    }

    /**
     * A cycle is a skill nobody can ever learn, and no single row looks wrong.
     * Depth-first search with a colouring, so it names the actual cycle rather
     * than just reporting that one exists.
     */
    private function checkPrerequisiteCycles(): void
    {
        $this->section('Prerequisite graph');

        $edges = [];

        foreach (SkillPrerequisite::all() as $prerequisite) {
            $edges[$prerequisite->skill_id][] = $prerequisite->required_skill_id;
        }

        $slugs = Skill::pluck('slug', 'id');
        $state = [];   // 0 unvisited, 1 in progress, 2 done
        $stack = [];

        $visit = function (int $id) use (&$visit, &$state, &$stack, $edges, $slugs): void {
            if (($state[$id] ?? 0) === 2) {
                return;
            }

            if (($state[$id] ?? 0) === 1) {
                $cycle = array_slice($stack, array_search($id, $stack, true));
                $cycle[] = $id;

                $this->problem('cycle: '.implode(' -> ', array_map(
                    fn (int $node): string => $slugs[$node] ?? "#{$node}",
                    $cycle
                )));

                return;
            }

            $state[$id] = 1;
            $stack[] = $id;

            foreach ($edges[$id] ?? [] as $next) {
                $visit($next);
            }

            array_pop($stack);
            $state[$id] = 2;
        };

        foreach (array_keys($slugs->all()) as $id) {
            $visit((int) $id);
        }
    }

    /**
     * attained_level is denormalised from skill_points. One writer keeps them
     * honest; this is what notices when something else has written.
     */
    private function checkStoredLevels(SkillProgression $progression): void
    {
        $this->section('Stored levels');

        foreach (CharacterSkill::with('skill')->get() as $row) {
            if ($row->skill === null) {
                continue;
            }

            $derived = $progression->levelFor($row->skill_points, $row->skill->rank);

            if ($derived !== $row->attained_level) {
                $this->problem(
                    "character {$row->character_id} / [{$row->skill->slug}]: ".
                    "stored level {$row->attained_level}, but {$row->skill_points} points imply {$derived}"
                );
            }
        }
    }

    private function checkPointCaps(SkillProgression $progression): void
    {
        $this->section('Point caps');

        foreach (CharacterSkill::with('skill')->get() as $row) {
            if ($row->skill === null) {
                continue;
            }

            $cap = $progression->cap($row->skill->rank);

            if ($row->skill_points > $cap) {
                $this->problem(
                    "character {$row->character_id} / [{$row->skill->slug}]: ".
                    "{$row->skill_points} points exceeds the cap of {$cap}"
                );
            }
        }
    }

    /**
     * A queue entry is a (skill, level) pair, so soundness depends on the whole
     * sequence: whether "Codebreaking I" is valid at position 6 depends on what
     * positions 1 to 5 will have completed by then. The unique indexes enforce
     * that no two entries share a position or a level; only simulating the
     * queue forward can check the rest.
     */
    private function checkTrainingQueues(): void
    {
        $this->section('Training queues');

        $validator = new TrainingQueueValidator;

        Character::whereHas('trainingQueue')->with('trainingQueue')->each(
            function (Character $character) use ($validator): void {
                foreach ($validator->problemsFor($character) as $problem) {
                    $this->problem("character {$character->id}: {$problem}");
                }
            }
        );
    }

    private function section(string $name): void
    {
        $this->line("<comment>{$name}</comment>");
    }

    private function problem(string $message): void
    {
        $this->problems++;
        $this->line("  <fg=red>x</> {$message}");
    }
}
