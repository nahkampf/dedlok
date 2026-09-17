<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\Skill;
use App\Models\TrainingQueueEntry;
use App\Skills\SkillProgression;
use App\Skills\TrainingQueue;
use App\Skills\TrainingQueueValidator;
use App\Support\Text;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * A character's own skills: what they know, what is training, and what is
 * lined up behind it.
 *
 * IT USED TO BE THE CATALOGUE — every skill in the game, drawn as one tree per
 * category, public because it described the game rather than a person, with a
 * player's own levels laid over the top as an overlay. That page is gone. A
 * catalogue is what the skill EDITOR is for; what a player wants from a screen
 * called Skills is their own, and an overlay on somebody else's list is a
 * worse way to read it than a list of the rows they actually have.
 *
 * So this is signed-in only, and RequireCharacter guarantees a character on
 * every route it does not name — which is why nothing here asks whether there
 * is one.
 */
class SkillsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $character = $request->user()->character()->firstOrFail();
        $progression = SkillProgression::fromConfig();

        // Both halves of the screen read a level from one map: the list of
        // what is known, and the meter on whatever is training.
        $levels = $this->attainedLevels($character);

        // One query for the definitions behind every row the screen draws —
        // what is known, and what is queued — rather than one per row.
        $skills = Skill::query()
            ->whereIn('id', $levels === [] ? [0] : array_keys($levels))
            ->orderBy('title')
            ->get();

        $queue = $character->trainingQueue()->with('skill')->get();

        return view('skills.index', [
            'skills' => $skills,
            'levels' => $levels,
            'max' => $progression->maxLevel(),
            'queue' => $queue,
            'limit' => TrainingQueue::LIMIT,
            'training' => $this->training($character, $queue->first(), $levels),
            // The queue is allowed to describe training that cannot happen
            // yet, so these are warnings on the screen and never a refusal.
            'problems' => (new TrainingQueueValidator)->problemsFor($character),
            'queueable' => $this->queueable($skills, $levels, $queue, $progression),
        ]);
    }

    /**
     * What this character has injected, how far they have taken it, and what
     * they have banked toward the next level.
     *
     * Level 0 is a real state — injected, untrained — so a key being PRESENT
     * is what marks a skill as known. Never read the level for that.
     *
     * @return array<int, array{level: int, points: int}>
     */
    private function attainedLevels(Character $character): array
    {
        return $character->characterSkills()
            ->get(['skill_id', 'attained_level', 'skill_points'])
            ->mapWithKeys(fn ($row): array => [
                (int) $row->skill_id => [
                    'level' => (int) $row->attained_level,
                    'points' => (int) $row->skill_points,
                ],
            ])
            ->all();
    }

    /**
     * The active entry, and when it will finish at the rate it earns now.
     *
     * THE RATE IS THE ONE IT HAS THIS INSTANT. A drug that wears off tonight
     * moves it, and nothing here pretends otherwise — an estimate that tried
     * to model every future modifier change would be a second, worse copy of
     * TrainingDeposit's segment walk, and would still be wrong the moment the
     * player installed something.
     *
     * @param  array<int, array{level: int, points: int}>  $levels
     * @return ?array<string, mixed>
     */
    private function training(Character $character, ?TrainingQueueEntry $active, array $levels): ?array
    {
        $skill = $active?->skill;

        if ($skill === null) {
            return null;
        }

        $points = $levels[$skill->id]['points'] ?? 0;
        $target = (int) $active->target_level;

        // The floor of the meter is the level below the one being trained, not
        // zero: a skill going from 2 to 3 is not two thirds of the way there.
        $floor = $target > 1 ? $skill->pointsForLevel($target - 1) : 0;
        $ceiling = $skill->pointsForLevel($target);

        $rate = $character->trainingRateFor($skill);
        $minutes = $rate > 0 ? max(0, $ceiling - $points) / $rate : null;

        return [
            'entry' => $active,
            'skill' => $skill,
            'target' => $target,
            'points' => $points,
            'ceiling' => $ceiling,
            'ratio' => $ceiling > $floor ? max(0, min(1, ($points - $floor) / ($ceiling - $floor))) : 0,
            'rate' => $rate,
            // A finishing moment rather than a count of minutes, so the view
            // can say "in about three hours" with the framework's own words.
            'finishes' => $minutes === null ? null : now()->addMinutes((int) ceil($minutes)),
        ];
    }

    /**
     * The skills that could go into the queue, grouped for the picker.
     *
     * Injected, and with a level left that is neither reached nor already
     * queued — which is the same sum TrainingQueue::enqueue() does, because
     * offering an option the writer would refuse is worse than offering none.
     *
     * @param  Collection<int, Skill>  $skills
     * @param  array<int, array{level: int, points: int}>  $levels
     * @param  Collection<int, TrainingQueueEntry>  $queue
     * @return array<string, list<array{id: int, label: string}>>
     */
    private function queueable(
        Collection $skills,
        array $levels,
        Collection $queue,
        SkillProgression $progression,
    ): array {
        $groups = [];

        foreach ($skills as $skill) {
            $queued = (int) $queue->where('skill_id', $skill->id)->max('target_level');
            $next = max($levels[$skill->id]['level'] ?? 0, $queued) + 1;

            if ($next > $progression->maxLevel()) {
                continue;
            }

            $groups[$skill->category][] = [
                'id' => $skill->id,
                'label' => Text::get('training.queueable', [
                    'skill' => $skill->title,
                    'level' => $next,
                ]),
            ];
        }

        return $groups;
    }
}
