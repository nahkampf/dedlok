<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Skill;
use App\Skills\SkillTree;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The skill catalogue, drawn as one tree per category.
 *
 * The tree itself describes the GAME: every skill, whoever is looking. What a
 * signed-in player has injected is laid over the top of it — a corner tick and
 * a level meter per row — and a guest simply sees the catalogue without them.
 *
 * That overlay is one query returning a skill_id => level map, never a join
 * into the tree: the arrangement must not depend on who is reading it, or two
 * players would see different shapes for the same catalogue.
 */
class SkillTreeController extends Controller
{
    public function __invoke(Request $request): View
    {
        // The whole graph in two queries. It is small enough to arrange in
        // memory and the arrangement needs every edge at once anyway.
        $skills = Skill::query()
            ->with('prerequisites')
            ->orderBy('title')
            ->get();

        return view('skills.index', [
            'categories' => (new SkillTree($skills))->categories(),
            'total' => $skills->count(),
            'levels' => $this->attainedLevels($request),
        ]);
    }

    /**
     * What the viewer has injected, and how far they have taken it.
     *
     * Level 0 is a real state — injected, untrained — so a key being PRESENT
     * is what marks a skill as known. Never read the level for that.
     *
     * @return array<int, int>
     */
    private function attainedLevels(Request $request): array
    {
        $character = $request->user()?->character()->first();

        if ($character === null) {
            return [];
        }

        return $character->characterSkills()
            ->pluck('attained_level', 'skill_id')
            ->map(fn ($level): int => (int) $level)
            ->all();
    }
}
