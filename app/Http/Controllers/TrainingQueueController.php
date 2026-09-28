<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\Skill;
use App\Models\TrainingQueueEntry;
use App\Skills\QueueRefusal;
use App\Skills\SkillProgression;
use App\Skills\TrainingPlanner;
use App\Skills\TrainingQueue;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Putting a skill into the training queue, and taking one out.
 *
 * Its own controller rather than two more methods on SkillsController, which
 * reads. Keeping that one invokable is what makes the split between drawing
 * the screen and changing it visible at the routes file.
 *
 * Both are POST/DELETE with no confirmation in front of them, because neither
 * destroys anything: progress lives on character_skills and an entry is only
 * ever intent and ordering. Taking one out and putting it back costs nothing
 * but the sub-point fraction TrainingQueue::remove() banks first.
 */
class TrainingQueueController extends Controller
{
    public function store(Request $request, TrainingQueue $queue): RedirectResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'skill' => ['required', 'integer', 'exists:skills,id'],
        ]);

        $skill = Skill::query()->findOrFail($validated['skill']);

        $result = $queue->enqueue($character, $skill);

        if ($result instanceof QueueRefusal) {
            return back()->with('error', $result->message());
        }

        return back()->with('status', Text::get('training.queued', [
            'skill' => $skill->title,
            'level' => $result->target_level,
        ]));
    }

    /**
     * Fill the queue with everything that reaches a level of a skill.
     *
     * The same door as `store()` — every entry still goes through
     * `TrainingQueue::enqueue()`, one at a time, in the order
     * `TrainingPlanner` worked out. What is added here is the arithmetic a
     * player would otherwise do on paper: which prerequisites, at which
     * levels, in which order.
     *
     * IT STOPS AT THE FIRST REFUSAL AND SAYS WHICH ONE. A plan is routinely
     * longer than the five entries a queue holds, and it can name a skill
     * whose imprint has never been spent — both are ordinary, neither is a
     * failure, and the queue already has the words for them. What lands is
     * everything up to that point, because a partial route is still the
     * beginning of the route.
     */
    public function plan(Request $request, TrainingQueue $queue, TrainingPlanner $planner): RedirectResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'skill' => ['required', 'integer', 'exists:skills,id'],
            'level' => ['required', 'integer', 'min:1', 'max:'.SkillProgression::fromConfig()->maxLevel()],
        ]);

        $skill = Skill::query()->findOrFail($validated['skill']);
        $level = (int) $validated['level'];

        $steps = $planner->plan($character, $skill, $level);

        if ($steps === []) {
            return back()->with('status', Text::get('training.plan_nothing', [
                'skill' => $skill->title,
                'level' => $level,
            ]));
        }

        $queued = 0;

        foreach ($steps as $step) {
            $result = $queue->enqueue($character, $step);

            // An imprint missing on the way to something else gets its own
            // sentence: "you have not injected that skill" read as the skill
            // the player planned, which they HAD injected.
            if ($result === QueueRefusal::NotInjected && $step->isNot($skill)) {
                return back()->with('status', Text::get('training.plan_needs_imprint', [
                    'count' => $queued,
                    'total' => count($steps),
                    'skill' => $step->title,
                    'target' => $skill->title,
                ]));
            }

            if ($result instanceof QueueRefusal) {
                return back()->with('status', Text::get('training.plan_stopped', [
                    'count' => $queued,
                    'total' => count($steps),
                    'skill' => $step->title,
                    'reason' => $result->message(),
                ]));
            }

            $queued++;
        }

        return back()->with('status', Text::plural('training.plan_queued', $queued, [
            'skill' => $skill->title,
            'level' => $level,
        ]));
    }

    public function destroy(Request $request, TrainingQueueEntry $entry, TrainingQueue $queue): RedirectResponse
    {
        $character = $this->character($request);

        // Somebody else's entry is not a refusal to explain, it is a row that
        // does not exist as far as this player is concerned — the same answer
        // inventory.inject gives for somebody else's imprint.
        abort_unless($entry->character_id === $character->id, 404);

        $title = $entry->skill?->title;
        $target = (int) $entry->target_level;

        $queue->remove($character, $entry);

        return back()->with('status', Text::get('training.dequeued', [
            'skill' => $title,
            'level' => $target,
        ]));
    }

    private function character(Request $request): Character
    {
        // RequireCharacter guarantees one on every route in this group, so a
        // missing character here is a routing mistake, not a player state.
        return $request->user()->character()->firstOrFail();
    }
}
