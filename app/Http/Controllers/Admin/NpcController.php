<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Combat\ArmourSlot;
use App\Combat\DamageProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NpcRequest;
use App\Models\Encounter;
use App\Models\Item;
use App\Models\Npc;
use App\Stats\DamageOrigin;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The NPC editor: the opponents of a fight, as numbers and three pointers at items.
 *
 * A fight snapshots an NPC when it opens, so editing or deleting one never touches a fight already
 * in progress. An encounter's Fight choice NAMES one, though, and a choice with no opponent is not a
 * choice: the delete is refused (and the database restricts it) until the encounter stops using it.
 */
class NpcController extends Controller
{
    public function index(): View
    {
        return view('admin.npcs.index', [
            'npcs' => Npc::query()->with(['rangedWeapon', 'meleeWeapon'])->orderBy('grade')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.npcs.form', $this->formData(null));
    }

    public function store(NpcRequest $request): RedirectResponse
    {
        $npc = Npc::create($request->columns());

        return redirect()->route('admin.npcs.edit', $npc)->with('status', Text::get('admin.npcs.created'));
    }

    public function edit(Npc $npc): View
    {
        return view('admin.npcs.form', $this->formData($npc));
    }

    public function update(NpcRequest $request, Npc $npc): RedirectResponse
    {
        $npc->update($request->columns());

        return back()->with('status', Text::get('admin.npcs.updated'));
    }

    public function destroy(Npc $npc): RedirectResponse
    {
        $using = Encounter::query()
            ->whereHas('nodes.choices', fn ($q) => $q->where('fight_npc_id', $npc->getKey()))
            ->orderBy('title')->pluck('title');

        if ($using->isNotEmpty()) {
            return back()->with('error', Text::get('admin.npcs.in_use', ['encounters' => $using->implode(', ')]));
        }

        $npc->delete();

        return redirect()->route('admin.npcs.index')->with('status', Text::get('admin.npcs.deleted'));
    }

    /** @return array<string, mixed> */
    private function formData(?Npc $npc): array
    {
        $weapons = Item::query()->with('weapon')->whereHas('weapon')->orderBy('name')->get();

        return [
            'npc' => $npc,
            'rangedWeapons' => $weapons->filter(fn (Item $i): bool => $i->weapon->class->ranged())->values(),
            'meleeWeapons' => $weapons->reject(fn (Item $i): bool => $i->weapon->class->ranged())->values(),
            'rounds' => Item::query()->with('ammunition')->whereHas('ammunition')->orderBy('name')->get(),
            'types' => array_combine(DamageProfile::types(), array_map(static fn (DamageOrigin $t): string => $t->label(), DamageOrigin::types())),
            'slots' => ArmourSlot::cases(),
        ];
    }
}
