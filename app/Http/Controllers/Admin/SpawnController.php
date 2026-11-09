<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\Item;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Puts items into a character's stash. `ItemGranter` is the only writer, so stacking and imprints behave as everywhere else. */
class SpawnController extends Controller
{
    public function index(): View
    {
        $items = Item::query()
            ->with(['ammunition', 'weapon', 'drug', 'deckModule', 'program', 'useSkill'])
            ->orderBy('name')
            ->get();

        return view('admin.spawn.index', ['items' => $items]);
    }

    /** Handles for the autocomplete. Unlike the messages one, it includes the admin's own. */
    public function handles(Request $request): JsonResponse
    {
        $typed = trim((string) $request->query('q'));

        return response()->json($typed === '' ? [] : Character::query()
            ->where('handle', 'like', addcslashes($typed, '%_\\').'%')
            ->orderBy('handle')->limit(8)->pluck('handle')->all());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'handle' => ['required', 'string'],
            'pick' => ['required', 'array', 'min:1'],
            'pick.*' => ['integer', 'exists:items,id'],
            'qty' => ['array'],
            'qty.*' => ['integer', 'min:1', 'max:1000'],
        ]);

        $character = Character::where('handle', trim($data['handle']))->first();

        if ($character === null) {
            return back()->withInput()->withErrors(['handle' => Text::get('admin.spawn.unknown')]);
        }

        $given = DB::transaction(function () use ($data, $character): int {
            $total = 0;

            foreach (Item::whereIn('id', $data['pick'])->get() as $item) {
                $total += ItemGranter::grant($character, $item, (int) ($data['qty'][$item->id] ?? 1));
            }

            return $total;
        });

        return back()->with('status', Text::get('admin.spawn.done', ['count' => $given, 'who' => $character->handle]));
    }
}
