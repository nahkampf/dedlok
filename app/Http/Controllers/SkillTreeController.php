<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Skill;
use App\Skills\SkillTree;
use Illuminate\View\View;

/**
 * The skill catalogue, drawn as one tree per category.
 *
 * Public and read-only: it describes what exists in the game, not what any
 * character has. Nothing here may touch character_skills.
 */
class SkillTreeController extends Controller
{
    public function __invoke(): View
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
        ]);
    }
}
