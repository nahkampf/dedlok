<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cyberspace\SystemType;
use App\Cyberspace\Tracker;
use App\Models\Character;
use App\Programs\ProgramRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The map screen's Tracker box: choosing what to look for, and letting it go. Every rule is in `Tracker`. */
class TrackerController extends Controller
{
    public function filter(Request $request, Tracker $tracker): RedirectResponse
    {
        $data = $request->validate([
            'owner_id' => ['nullable', 'integer', 'exists:entities,id'],
            'type' => ['nullable', Rule::in(array_map(static fn (SystemType $t): string => $t->value, SystemType::cases()))],
            'grade' => ['nullable', 'integer', 'between:1,9'],
        ]);

        $error = $tracker->setFilter(
            $this->character($request),
            ($data['owner_id'] ?? '') === '' ? null : (int) $data['owner_id'],
            ($data['type'] ?? '') === '' ? null : $data['type'],
            ($data['grade'] ?? '') === '' ? null : (int) $data['grade'],
        );

        return $this->back($error);
    }

    public function release(Request $request, Tracker $tracker, ProgramRunner $runner): RedirectResponse
    {
        return $this->back($tracker->release($this->character($request), $runner));
    }

    private function back(?string $error): RedirectResponse
    {
        return redirect()->to(route('cyberspace.index').'#tracker')->with($error === null ? [] : ['error' => $error]);
    }

    private function character(Request $request): Character
    {
        return $request->user()->character()->firstOrFail();
    }
}
