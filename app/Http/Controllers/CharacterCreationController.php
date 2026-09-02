<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\Setting;
use App\Models\SuggestedHandle;
use App\Rules\ValidHandle;
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
            'points' => $points,
            'starting' => Character::startingAttributes($points),
            'satisfiable' => Character::poolIsSatisfiable($points),
            'archetypes' => $this->archetypes(),
            'derived' => $this->derivedSpec(),
            'archetypeEffects' => $this->archetypeEffects(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $points = $this->attributePoints();

        $validator = $this->validator($request, $points);
        $validated = $validator->validate();

        $handle = ValidHandle::normalize($validated['handle']);

        DB::transaction(function () use ($request, $validated, $handle): void {
            $request->user()->character()->create(array_merge(
                array_intersect_key($validated, array_flip(Character::ATTRIBUTES)),
                ['handle' => $handle, 'archetype' => (int) $validated['archetype']],
            ));

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
            'archetype' => [
                'required',
                'integer',
                Rule::in(array_keys((array) config('archetypes.list'))),
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

        foreach (array_keys((array) config('archetypes.list')) as $id) {
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

    /** @return list<array<string, mixed>> */
    private function archetypes(): array
    {
        $archetypes = [];

        foreach ((array) config('archetypes.list') as $id => $archetype) {
            $slug = $archetype['slug'];

            $archetypes[] = [
                'id' => (int) $id,
                'slug' => $slug,
                'name' => $archetype['name'],
                'role' => $archetype['role'],
                'description' => $archetype['description'],
                'perks' => array_map(
                    fn (array $perk): array => [
                        'label' => $this->perkLabel($perk),
                        'effect' => $this->perkEffect($perk),
                    ],
                    $archetype['perks'] ?? [],
                ),
            ];
        }

        return $archetypes;
    }

    /**
     * An attribute perk borrows the attribute's own name, so renaming
     * "Reflex" happens in one place rather than two.
     *
     * @param  array<string, mixed>  $perk
     */
    private function perkLabel(array $perk): string
    {
        if (isset($perk['modifiers'])) {
            return config("archetypes.perk_labels.{$perk['key']}");
        }

        return $perk['unit'] === 'attribute'
            ? Text::get("character.names.{$perk['key']}")
            : config("archetypes.perk_labels.{$perk['key']}");
    }

    /**
     * Signed display value. The sign is always shown — "+5" and "−10%" read as
     * modifiers, where "5" and "10%" read as absolute values.
     *
     * A live perk's effect is generated from its actual Modifier, so the card
     * cannot drift from what the resolver does. A display-only perk still
     * formats from the legacy modifier/unit pair.
     *
     * @param  array<string, mixed>  $perk
     */
    private function perkEffect(array $perk): string
    {
        if (isset($perk['modifiers'])) {
            return implode(', ', array_map(
                fn (array $definition): string => $this->modifierEffect(Modifier::fromArray($definition)),
                $perk['modifiers'],
            ));
        }

        return $this->signed($perk['modifier']).($perk['unit'] === 'percent' ? '%' : '');
    }

    /**
     * Render a real Modifier as card text: "+5% max HP".
     *
     * The stat name is appended because a perk's label is its own name
     * ("Scar tissue"), not the thing it changes — unlike the legacy perks,
     * where the label WAS the affected stat.
     */
    private function modifierEffect(Modifier $modifier): string
    {
        $amount = $modifier->op === Op::Multiplier
            ? '×'.$modifier->amount
            : $this->signed($modifier->amount).($modifier->op === Op::PercentageOfBase ? '%' : '');

        $target = $modifier->target->id;

        if ($target === null) {
            return $amount.' '.$modifier->target->describe();
        }

        $name = Text::get("character.names.{$target}");

        return $name === "character.names.{$target}" ? $amount : $amount.' '.$name;
    }

    /** U+2212 minus, not a hyphen, so a column of effects lines up. */
    private function signed(float $amount): string
    {
        return ($amount < 0 ? '−' : '+').(abs($amount) == (int) abs($amount)
            ? (string) (int) abs($amount)
            : (string) abs($amount));
    }
}
