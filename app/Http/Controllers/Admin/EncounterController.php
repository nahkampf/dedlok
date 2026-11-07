<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\ItemOptions;
use App\Admin\ModifierField;
use App\City\EncounterEditor;
use App\City\EncounterImage;
use App\City\EncounterRunEnd;
use App\City\EncounterStart;
use App\City\OutcomeBranch;
use App\City\OutcomeKind;
use App\City\PrerequisiteKind;
use App\Cyberspace\RunOutcome;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\SystemType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EncounterRequest;
use App\Models\District;
use App\Models\Encounter;
use App\Models\Entity;
use App\Models\Location;
use App\Models\Npc;
use App\Models\System;
use App\Stats\StatRegistry;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The narrative-encounter editor. A delete takes any live instance with it
 * (`character_encounters` cascades and the character's pointer nulls); the
 * confirmation is the only brake, as everywhere in the admin. To retire one, tick
 * *retired*: it leaves every draw and anybody inside it still finishes.
 */
class EncounterController extends Controller
{
    public function index(): View
    {
        return view('admin.encounters.index', [
            'encounters' => Encounter::query()->withCount('nodes')->orderBy('title')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.encounters.form', $this->formData(null));
    }

    public function store(EncounterRequest $request): RedirectResponse
    {
        $encounter = DB::transaction(function () use ($request): Encounter {
            $encounter = Encounter::create($request->columns());
            EncounterEditor::apply($encounter, $request->nodeRows(), $request->prerequisiteRows());

            return $encounter;
        });

        $redirect = redirect()->route('admin.encounters.edit', $encounter)->with('status', Text::get('admin.encounters.created'));

        return $this->picture($request, $encounter) ? $redirect : $redirect->withErrors(['image' => Text::get('admin.encounters.fields.image_unreadable')]);
    }

    public function edit(Encounter $encounter): View
    {
        return view('admin.encounters.form', $this->formData($encounter));
    }

    public function update(EncounterRequest $request, Encounter $encounter): RedirectResponse
    {
        $from = $encounter->starts_on;

        DB::transaction(function () use ($request, $encounter): void {
            $encounter->update($request->columns());
            EncounterEditor::apply($encounter, $request->nodeRows(), $request->prerequisiteRows());
        });

        EncounterImage::moved($encounter, $from);
        $back = back()->with('status', Text::get('admin.encounters.updated'));

        return $this->picture($request, $encounter) ? $back : $back->withErrors(['image' => Text::get('admin.encounters.fields.image_unreadable')]);
    }

    /** Save the uploaded picture, if there is one. False when the file could not be read as an image. */
    private function picture(EncounterRequest $request, Encounter $encounter): bool
    {
        if (! $request->hasFile('image')) {
            return true;
        }

        try {
            (new EncounterImage)->store($encounter, $request->file('image'));
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }

    public function destroy(Encounter $encounter): RedirectResponse
    {
        $encounter->delete();

        return redirect()->route('admin.encounters.index')->with('status', Text::get('admin.encounters.deleted'));
    }

    /**
     * Every stat a choice can roll, grouped the way the modifier picker groups them, with the
     * skills' `check.<slug>` stats among them. The picker's tag SETS are left out: a roll needs
     * one stat, not a family of them.
     *
     * @return array<string, array<string, string>> group => [stat id => label]
     */
    private function stats(): array
    {
        $registry = app(StatRegistry::class);
        $stats = [];

        foreach (ModifierField::targets() as $group => $options) {
            foreach ($options as $id => $label) {
                if ($registry->has((string) $id)) {
                    $stats[$group][$id] = $label === $id ? $id : "{$label} ({$id})";
                }
            }
        }

        return $stats;
    }

    /** @return array<string, mixed> */
    private function formData(?Encounter $encounter): array
    {
        return [
            'encounter' => $encounter,
            'starts' => EncounterStart::cases(),
            'kinds' => OutcomeKind::cases(),
            'branches' => OutcomeBranch::cases(),
            'districts' => District::query()->orderBy('name')->get(['id', 'name']),
            'locations' => Location::query()->with('district:id,name')->orderBy('name')->get(['id', 'name', 'district_id']),
            'entities' => Entity::query()->where('interactable', true)->orderBy('name')->get(['id', 'name']),
            'items' => ItemOptions::grouped(),
            // Who a fight choice can open a fight with.
            'npcs' => Npc::query()->orderBy('name')->get(['id', 'name', 'grade']),
            'stats' => $this->stats(),
            // The two kinds whose target is picked from a list, as value => label.
            'injuries' => array_combine(OutcomeKind::INJURIES, array_map(
                static fn (string $key): string => (string) config("effects.{$key}.label", $key),
                OutcomeKind::INJURIES,
            )),
            'damageTypes' => array_combine(OutcomeKind::DAMAGE, array_map(
                static fn (string $key): string => Text::get("admin.encounters.damage_types.{$key}"),
                OutcomeKind::DAMAGE,
            )),
            'stored' => $encounter === null ? [] : EncounterEditor::rows($encounter),
            'prerequisiteKinds' => PrerequisiteKind::cases(),
            'systemTypes' => SystemType::cases(),
            // The pinned system's address AS IT IS NOW: the spot follows the machine, so this can change by itself.
            'spotAddress' => $encounter?->spot_system_id === null ? '' : (string) System::query()->whereKey($encounter->spot_system_id)->value('address'),
            'purposes' => RunPurpose::cases(),
            'outcomes' => RunOutcome::cases(),
            'runEnds' => EncounterRunEnd::cases(),
            'owners' => Entity::query()->orderBy('name')->get(['id', 'name']),
            'storedPrerequisites' => $encounter === null ? [] : EncounterEditor::prerequisiteRows($encounter),
        ];
    }
}
