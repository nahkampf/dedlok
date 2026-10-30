<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlayerTask;
use App\Tasks\TaskPriority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The tasks a player sets for themselves: add one, tick it off, bring it back. */
class PlayerTaskController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
        ]);

        PlayerTask::create([
            'character_id' => $request->user()->character()->firstOrFail()->getKey(),
            'title' => $data['title'],
            'priority' => $data['priority'],
        ]);

        return back();
    }

    public function complete(Request $request, PlayerTask $task): RedirectResponse
    {
        $this->mine($request, $task)->update(['completed_at' => now()]);

        return back();
    }

    public function reintroduce(Request $request, PlayerTask $task): RedirectResponse
    {
        $this->mine($request, $task)->update(['completed_at' => null]);

        return back();
    }

    public function destroy(Request $request, PlayerTask $task): RedirectResponse
    {
        $this->mine($request, $task)->delete();

        return back();
    }

    private function mine(Request $request, PlayerTask $task): PlayerTask
    {
        abort_unless($task->character_id === $request->user()->character()->firstOrFail()->getKey(), 404);

        return $task;
    }
}
