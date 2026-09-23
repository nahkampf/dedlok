<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Archetypes\LoadoutGranter;
use App\Http\Middleware\ForbidSecondCharacter;
use App\Models\Archetype;
use App\Models\ArchetypeItem;
use App\Models\ArchetypePerk;
use App\Models\ArchetypeSkill;
use App\Models\Character;
use App\Models\Setting;
use App\Models\SuggestedHandle;
use App\Rules\ValidHandle;
use App\Skills\SkillProgression;
use App\Stats\Modifier;
use App\Stats\Op;
use App\Stats\Sources\ArchetypePerks;
use App\Stats\StatRegistry;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

class CharacterCreationController extends Controller
{
    /** Used when the setting is missing, so the page still works. */
    public const DEFAULT_ATTRIBUTE_POINTS = 300;

    public const POINTS_SETTING = 'new_character_attribute_points';

    public function create(Request $request): View
    {
        $points = $this->attributePoints();

        return view('character.create', [
            // Set by ForbidSecondCharacter when it lets an admin look at a
            // screen their own character already disqualifies them from. The
            // middleware has answered the question; asking the database again
            // here would be a second answer to keep in step with the first.
            'preview' => (bool) $request->attributes->get(ForbidSecondCharacter::PREVIEW, false),
            'points' => $points,
            'starting' => Character::startingAttributes($points),
            'satisfiable' => Character::poolIsSatisfiable($points),
            'archetypes' => $this->archetypes(),
            // The denominator of the loadout meters: five segments, because
            // five is where a skill tops out. Read from the progression rather
            // than written into the markup, so the meter cannot outlive the
            // curve it draws.
            'maxLevel' => SkillProgression::fromConfig()->maxLevel(),
            'derived' => $this->derivedSpec(),
            'archetypeEffects' => $this->archetypeEffects(),
        ]);
    }

    public function store(Request $request, LoadoutGranter $granter): RedirectResponse
    {
        $points = $this->attributePoints();

        $validator = $this->validator($request, $points);
        $validated = $validator->validate();

        $handle = ValidHandle::normalize($validated['handle']);

        DB::transaction(function () use ($request, $validated, $handle, $granter): void {
            $archetype = Archetype::withLoadout()->get((int) $validated['archetype']);

            $character = $request->user()->character()->create(array_merge(
                array_intersect_key($validated, array_flip(Character::ATTRIBUTES)),
                ['handle' => $handle, 'archetype' => (int) $validated['archetype']],
            ));

            // Inside the transaction with the character: a character that
            // exists without the cash, skills and gear their archetype
            // promised is worse than no character at all, and nothing would
            // ever notice the difference.
            $granter->grant($character, $archetype);

            SuggestedHandle::whereKey($handle)->first()?->update(['in_use' => true]);
        });

        return redirect()->route('home');
    }

    private function validator(Request $request, int $points): Validator
    {
        $rules = array_merge(Character::creationAttributeRules(), [
            'handle' => [
                'required',
                'string',
                new ValidHandle,
                Rule::unique(Character::class, 'handle'),
            ],
            /*
             * A playable archetype, not merely an existing one. The cards
             * disable the rest, but the browser is not a security boundary —
             * a hand-rolled POST would otherwise mint a character as a role
             * that has no loadout, no perks and nothing built behind it.
             */
            'archetype' => [
                'required',
                'integer',
                Rule::in(Archetype::playable()->keys()->all()),
            ],
        ]);

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $rules, [
            'handle.unique' => Text::get('auth.handle_taken'),
            'archetype.required' => Text::get('character.archetype.required'),
            'archetype.in' => Text::get('character.archetype.required'),
        ]);

        /*
         * The pool must balance exactly. The browser enforces this too, but the
         * browser is not a security boundary — a hand-rolled POST would
         * otherwise mint a character with 1000 points.
         *
         * Runs only once the per-attribute rules have passed, so the sum is not
         * computed over garbage.
         */
        $validator->after(function (Validator $validator) use ($request, $points): void {
            if ($validator->errors()->hasAny(Character::ATTRIBUTES)) {
                return;
            }

            $spent = 0;

            foreach (Character::ATTRIBUTES as $attribute) {
                $spent += (int) $request->input($attribute, 0);
            }

            if ($spent !== $points) {
                $validator->errors()->add('attributes', Text::get('character.attributes.must_balance', [
                    'total' => $points,
                ]));
            }
        });

        return $validator;
    }

    /**
     * Everything the browser needs to compute the derived readouts itself.
     *
     * The formula is shipped as data rather than reimplemented in JavaScript,
     * so PHP and the browser read the same source and cannot disagree.
     *
     * @return list<array<string, mixed>>
     */
    private function derivedSpec(): array
    {
        $registry = StatRegistry::fromConfig();
        $spec = [];

        foreach ((array) config('stats.formulas', []) as $statId => $formula) {
            $stat = $registry->get($statId);

            $spec[] = [
                'id' => $statId,
                'label' => Text::get("character.derived_names.{$statId}"),
                'abbr' => Text::get("character.abbreviations.{$statId}"),
                'terms' => $formula,
                'min' => $stat->min,
                'max' => $stat->max,
                'rounding' => $stat->rounding,
            ];
        }

        return $spec;
    }

    /**
     * Per-archetype effect on each derived stat, flattened to (add, percent).
     *
     * The browser applies these so the readouts tell the truth about the
     * archetype the player has actually selected, instead of showing a base
     * value they will never have.
     *
     * This is a deliberate SUBSET of the resolver: only Add and
     * PercentageOfBase are expressible here. ArchetypePerkModifierTest asserts
     * no live perk targeting a derived stat uses anything else, so the subset
     * cannot silently go wrong — if one ever does, the test fails rather than
     * the screen quietly lying.
     *
     * @return array<int, array<string, array{add: float, percent: float}>>
     */
    private function archetypeEffects(): array
    {
        $derived = Character::derivedStats();
        $registry = StatRegistry::fromConfig();
        $effects = [];

        foreach (Archetype::roster()->keys() as $id) {
            $perStat = [];

            foreach ((new ArchetypePerks((int) $id))->modifiers() as $modifier) {
                foreach ($registry->matching($modifier->target) as $statId) {
                    if (! in_array($statId, $derived, true)) {
                        continue;
                    }

                    $perStat[$statId] ??= ['add' => 0.0, 'percent' => 0.0];

                    match ($modifier->op) {
                        Op::Add => $perStat[$statId]['add'] += $modifier->amount,
                        Op::PercentageOfBase => $perStat[$statId]['percent'] += $modifier->amount,
                        default => null,   // guarded by ArchetypePerkModifierTest
                    };
                }
            }

            $effects[(int) $id] = $perStat;
        }

        return $effects;
    }

    private function attributePoints(): int
    {
        return (int) (Setting::get(self::POINTS_SETTING) ?? self::DEFAULT_ATTRIBUTE_POINTS);
    }

    /**
     * The cards: who each archetype is, what it changes, and what it hands you.
     *
     * Read through withLoadout() rather than the memoised roster, because this
     * is one of the two screens that needs the loadout rows and the roster is
     * deliberately kept to perks — see Archetype::withLoadout().
     *
     * @return list<array<string, mixed>>
     */
    private function archetypes(): array
    {
        $archetypes = [];

        // Offered first, withdrawn after — a menu opens with what can be
        // ordered. Within each half the roster's own order holds, because
        // archetype ids are the order they were written in.
        $roster = Archetype::withLoadout()
            ->sortByDesc(fn (Archetype $archetype): bool => (bool) $archetype->playable);

        foreach ($roster as $archetype) {
            $archetypes[] = [
                'id' => $archetype->id,
                'slug' => $archetype->slug,
                'name' => $archetype->name,
                'role' => $archetype->role,
                'description' => $archetype->description,
                'perks' => $archetype->perks
                    ->map(fn (ArchetypePerk $perk): array => [
                        'label' => $perk->displayLabel(),
                        'effect' => $this->perkEffect($perk),
                    ])
                    ->all(),
                'playable' => (bool) $archetype->playable,
                'creds' => (int) $archetype->starting_creds,
                'skills' => $this->startingSkills($archetype),
                'items' => $this->startingItems($archetype),
            ];
        }

        return $archetypes;
    }

    /**
     * An archetype's starting skills, sorted the way a player reads them.
     *
     * By title rather than by id: the loadout is a list on a card, and
     * insertion order is a fact about the editor rather than about the
     * character. A grant whose skill has been deleted is skipped — the row
     * cascades with it, so this only sees one mid-delete.
     *
     * @return list<array{title: string, level: int}>
     */
    private function startingSkills(Archetype $archetype): array
    {
        return $archetype->startingSkills
            ->filter(fn (ArchetypeSkill $grant): bool => $grant->skill !== null)
            ->sortBy(fn (ArchetypeSkill $grant): string => $grant->skill->title)
            ->map(fn (ArchetypeSkill $grant): array => [
                'title' => $grant->skill->title,
                'level' => $grant->level,
            ])
            ->values()
            ->all();
    }

    /**
     * An archetype's starting items, by display name.
     *
     * displayName() rather than the column, so an imprint in a loadout reads
     * as the skill it teaches instead of as a blank.
     *
     * @return list<array{name: string, quantity: int}>
     */
    private function startingItems(Archetype $archetype): array
    {
        return $archetype->startingItems
            ->filter(fn (ArchetypeItem $grant): bool => $grant->item !== null)
            ->sortBy(fn (ArchetypeItem $grant): string => $grant->item->displayName())
            ->map(fn (ArchetypeItem $grant): array => [
                'name' => $grant->item->displayName(),
                'quantity' => $grant->quantity,
            ])
            ->values()
            ->all();
    }

    /**
     * Signed display value. The sign is always shown — "+5" and "−10%" read as
     * modifiers, where "5" and "10%" read as absolute values.
     *
     * A live perk's effect is generated from its actual Modifier, so the card
     * cannot drift from what the resolver does. A display-only perk still
     * formats from the legacy number-and-unit pair.
     */
    private function perkEffect(ArchetypePerk $perk): string
    {
        if ($perk->isLive()) {
            return implode(', ', array_map(
                fn (array $definition): string => Modifier::fromArray($definition)->effectText(),
                $perk->modifiers,
            ));
        }

        return Modifier::signed((float) $perk->display_modifier)
            .($perk->display_unit === 'percent' ? '%' : '');
    }
}
