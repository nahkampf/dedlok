<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\Skill;
use App\Models\TrainingQueueEntry;
use App\Skills\QueueRefusal;
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

    public function destroy(Request $request, TrainingQueueEntry $entry, TrainingQueue $queue): RedirectResponse
    {
        $character = $this->character($request);

        // Somebody else's entry is not a refusal to explain, it is a row that
        // does not exist as far as this player is concerned — the same answer
        // inventory.inject gives for somebody else's book.
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
