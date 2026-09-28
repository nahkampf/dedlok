<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\DeletionImpact;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SkillRequest;
use App\Inventory\Imprint;
use App\Models\Character;
use App\Models\Skill;
use App\Skills\SkillProgression;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The skill creator and editor.
 *
 * A skill is never only a skill: it is a row, a set of graph edges, and a
 * imprint. The imprint is created here rather than left to the seeder, because
 * "exactly one imprint per skill" is what makes an imprint nameless and what makes
 * the injection gate meaningful — a skill made through this screen with no
 * imprint would be a skill nobody could ever learn.
 *
 * Everything that writes more than one table does it in a transaction, so a
 * skill can never exist with half its prerequisites or without its imprint.
 */
class SkillController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:255'],
        ]);

        $skills = Skill::query()
            ->with(['imprint:id,slug,use_skill_id'])
            ->withCount(['prerequisites', 'unlocks'])
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->where(
                fn ($inner) => $inner
                    ->where('slug', 'like', "%{$term}%")
                    ->orWhere('title', 'like', "%{$term}%")
            ))
            ->when($filters['category'] ?? null, fn ($query, string $category) => $query->inCategory($category))
            ->orderBy('category')
            ->orderBy('title')
            ->paginate(25)
            ->withQueryString();

        return view('admin.skills.index', [
            'skills' => $skills,
            'filters' => $filters,
            'categories' => Skill::query()->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function create(): View
    {
        return view('admin.skills.form', $this->formData(null));
    }

    public function store(SkillRequest $request): RedirectResponse
    {
        $skill = DB::transaction(function () use ($request): Skill {
            $skill = Skill::create($request->columns());

            $this->replacePrerequisites($skill, $request->prerequisites());

            // The imprint is not optional. See the class comment.
            Imprint::ensureFor($skill);

            return $skill;
        });

        return redirect()
            ->route('admin.skills.edit', $skill)
            ->with('status', Text::get('admin.skills.created', [
                'skill' => $skill->slug,
                'book' => Imprint::slugFor($skill),
            ]));
    }

    public function edit(Skill $skill): View
    {
        return view('admin.skills.form', $this->formData($skill));
    }

    public function update(SkillRequest $request, Skill $skill): RedirectResponse
    {
        DB::transaction(function () use ($request, $skill): void {
            $skill->update($request->columns());

            $this->replacePrerequisites($skill, $request->prerequisites());

            // Cost and availability follow the skill; the rank-derived values
            // do not, because they are the ones most likely to have been
            // tuned by hand in the item editor. App\Inventory\Imprint says
            // so at more length.
            Imprint::syncFor($skill);
        });

        return back()->with('status', Text::get('admin.skills.updated', ['skill' => $skill->slug]));
    }

    /**
     * Destroy the skill, its imprint, and everyone's progress in it.
     *
     * The imprint has to go FIRST: items.use_skill_id is restrictOnDelete, so a
     * skill something still points at cannot be dropped — the database would
     * refuse with a foreign key error rather than cascade. Everything else
     * (character_skills, training_queue, both directions of
     * skill_prerequisites) cascades on its own.
     */
    public function destroy(Skill $skill): RedirectResponse
    {
        $impact = DeletionImpact::forSkill($skill);
        $slug = $skill->slug;

        DB::transaction(function () use ($skill): void {
            $skill->imprint()->delete();

            $skill->delete();
        });

        return redirect()
            ->route('admin.skills.index')
            ->with('status', Text::get('admin.skills.deleted', [
                'skill' => $slug,
                'rows' => array_sum($impact->counts()),
            ]));
    }

    /**
     * Replace a skill's requirements wholesale.
     *
     * Delete-then-insert rather than a diff: the form submits the set it wants
     * rather than a list of changes, the rows carry nothing worth preserving
     * (no id anyone references, no timestamps), and a diff would be more code
     * to get a result that is identical.
     *
     * @param  list<array{required_skill_id: int, required_level: int}>  $prerequisites
     */
    private function replacePrerequisites(Skill $skill, array $prerequisites): void
    {
        $skill->prerequisites()->delete();

        if ($prerequisites === []) {
            return;
        }

        $skill->prerequisites()->createMany($prerequisites);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(?Skill $skill): array
    {
        $progression = SkillProgression::fromConfig();

        return [
            'skill' => $skill,
            'ranks' => $progression->ranks(),
            'maxLevel' => $progression->maxLevel(),
            'attributes' => Character::ATTRIBUTES,
            'physical' => Character::PHYSICAL_ATTRIBUTES,
            'mental' => Character::MENTAL_ATTRIBUTES,
            // Everything that could be a prerequisite. The skill itself is
            // excluded here as well as validated against: an option that can
            // only ever be an error does not belong in the list.
            'candidates' => Skill::query()
                ->when($skill !== null, fn ($query) => $query->whereKeyNot($skill->id))
                ->orderBy('title')
                ->get(['id', 'slug', 'title']),
            'existing' => $skill?->prerequisites()->with('requiredSkill:id,slug,title')->get() ?? collect(),
            'categories' => Skill::query()->distinct()->orderBy('category')->pluck('category'),
            'book' => $skill === null ? null : Imprint::forSkill($skill),
            'impact' => $skill === null ? null : DeletionImpact::forSkill($skill),
        ];
    }
}
