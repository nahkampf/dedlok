@use('App\Support\Text')
{{-- One choice. $j is its index, or __J__ for the template. --}}
@php($at = "nodes[{$i}][choices][{$j}]")
<div class="stack" style="--gap:var(--s-2);padding-left:var(--s-3);border-left:var(--hair) solid var(--line-1)" data-row>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(150px,100%),1fr))">
        <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.encounters.choice.label') }}</span>
            <input class="input" name="{{ $at }}[label]" maxlength="200" value="{{ $choice['label'] ?? '' }}"></label>
        <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.encounters.choice.next') }}</span>
            <input class="input" list="node-keys" name="{{ $at }}[next_key]" maxlength="40" value="{{ $choice['next_key'] ?? '' }}"></label>
        <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.encounters.choice.cost') }}</span>
            <input class="input num" type="number" min="0" name="{{ $at }}[cost]" value="{{ $choice['cost'] ?? 0 }}"></label>
        <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.encounters.choice.requires_flag') }}</span>
            <input class="input" name="{{ $at }}[requires_flag]" maxlength="60" value="{{ $choice['requires_flag'] ?? '' }}"></label>
        <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.encounters.choice.check_stat') }}</span>
            <select class="select" name="{{ $at }}[check_stat]">
                <option value=""></option>
                @php($picked = $choice['check_stat'] ?? '')
                {{-- A stat since retired from the registry stays selectable, so saving does not silently drop it. --}}
                @if ($picked !== '' && ! collect($stats)->contains(fn ($group) => isset($group[$picked])))
                    <option value="{{ $picked }}" selected>{{ $picked }}</option>
                @endif
                @foreach ($stats as $group => $options)
                    <optgroup label="{{ $group }}">
                        @foreach ($options as $id => $label)
                            <option value="{{ $id }}" @selected($picked === $id)>{{ $label }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select></label>
        <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.encounters.choice.difficulty') }}</span>
            <input class="input num" type="number" min="0" max="200" step="0.1" name="{{ $at }}[difficulty]" value="{{ $choice['difficulty'] ?? '' }}"></label>
        <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.encounters.choice.pass') }}</span>
            <input class="input" list="node-keys" name="{{ $at }}[pass_key]" maxlength="40" value="{{ $choice['pass_key'] ?? '' }}"></label>
        <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.encounters.choice.fail') }}</span>
            <input class="input" list="node-keys" name="{{ $at }}[fail_key]" maxlength="40" value="{{ $choice['fail_key'] ?? '' }}"></label>
    </div>

    {{-- A FIGHT: instead of a next screen or a roll, an opponent and three ways it can end. Rewards are the
         arrival outcomes of the win screen, so a fight choice carries none of its own. --}}
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(150px,100%),1fr))">
        <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.encounters.choice.fight_npc') }}</span>
            <select class="select" name="{{ $at }}[fight_npc_id]">
                <option value="">{{ Text::get('admin.encounters.choice.no_fight') }}</option>
                @foreach ($npcs as $npc)
                    <option value="{{ $npc->id }}" @selected((string) ($choice['fight_npc_id'] ?? '') === (string) $npc->id)>{{ $npc->name }} — {{ Text::get('combat.fight.screen.grade', ['grade' => $npc->grade]) }}</option>
                @endforeach
            </select></label>
        <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.encounters.choice.win') }}</span>
            <input class="input" list="node-keys" name="{{ $at }}[win_key]" maxlength="40" value="{{ $choice['win_key'] ?? '' }}"></label>
        <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.encounters.choice.lose') }}</span>
            <input class="input" list="node-keys" name="{{ $at }}[lose_key]" maxlength="40" value="{{ $choice['lose_key'] ?? '' }}"></label>
        <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.encounters.choice.flee') }}</span>
            <input class="input" list="node-keys" name="{{ $at }}[flee_key]" maxlength="40" value="{{ $choice['flee_key'] ?? '' }}"></label>
    </div>
    <span class="field__hint">{{ Text::get('admin.encounters.choice.fight_hint') }}</span>

    <div class="stack" style="--gap:var(--s-2)" data-rows>
        @foreach ($choice['outcomes'] ?? [] as $k => $outcome)
            @include('admin.encounters._outcome', ['outcomeAt' => "{$at}[outcomes][{$k}]", 'outcome' => $outcome, 'onScreen' => false])
        @endforeach

        <template>@include('admin.encounters._outcome', ['outcomeAt' => "{$at}[outcomes][__K__]", 'outcome' => $blankOutcome, 'onScreen' => false])</template>
        <button type="button" class="btn btn--sm btn--ghost cut" data-add-row data-token="__K__" style="align-self:start">+ {{ Text::get('admin.encounters.add_outcome') }}</button>
    </div>
</div>
