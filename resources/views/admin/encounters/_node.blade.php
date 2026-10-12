@use('App\Support\Text')
{{-- One screen. $i is its index, or the token __I__ when this is the template for a new one. --}}
<div class="panel panel--snug" data-row>
    <div class="panel__bd stack" style="--gap:var(--s-3)">
        <button type="button" class="btn btn--sm btn--ghost cut" data-remove-row style="align-self:start">✕ {{ Text::get('admin.encounters.remove_node') }}</button>
        <div class="row row--wrap" style="--gap:var(--s-3);align-items:end">
            <label class="field" style="margin:0;flex:1 1 160px;min-width:0">
                <span class="field__label">{{ Text::get('admin.encounters.node.key') }}</span>
                {{-- Starts as "<slug>_" until something else is typed (ui-kit.js, data-prefix). On a new
                     encounter it follows the slug field as that is typed. --}}
                <input class="input" name="nodes[{{ $i }}][key]" maxlength="40" value="{{ $node['key'] ?? '' }}"
                       @if ($encounter) data-prefix="{{ $encounter->slug }}" @else data-prefix-from="#encounter-slug" @endif>
            </label>
            <label class="check">
                <input type="radio" name="entry" value="{{ $i }}" @checked((string) $entryAt === (string) $i)>
                <span class="check__box"></span><span>{{ Text::get('admin.encounters.node.entry') }}</span>
            </label>
            <label class="check">
                <input type="checkbox" name="nodes[{{ $i }}][ends]" value="1" @checked(! empty($node['ends']))>
                <span class="check__box"></span><span>{{ Text::get('admin.encounters.node.ends') }}</span>
            </label>
            {{-- On an ending of a run encounter: what becomes of the run. Required there, refused elsewhere. --}}
            <label class="field" style="margin:0;flex:0 1 220px;min-width:0">
                <span class="field__label">{{ Text::get('admin.encounters.node.run_end') }}</span>
                <select class="select" name="nodes[{{ $i }}][run_end]">
                    <option value=""></option>
                    @foreach ($runEnds as $runEnd)
                        <option value="{{ $runEnd->value }}" @selected(($node['run_end'] ?? '') === $runEnd->value)>{{ $runEnd->label() }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <label class="field" style="margin:0">
            <span class="field__label">{{ Text::get('admin.encounters.node.body') }}</span>
            <textarea class="textarea" name="nodes[{{ $i }}][body]">{{ $node['body'] ?? '' }}</textarea>
        </label>

        {{-- What the SCREEN does to whoever reaches it, every time they do. Valid on an ending too. --}}
        <span class="micro" style="color:var(--ink-3)">{{ Text::get('admin.encounters.arrival') }}</span>
        <div class="stack" style="--gap:var(--s-2)" data-rows>
            @foreach ($node['outcomes'] ?? [] as $k => $outcome)
                @include('admin.encounters._outcome', ['outcomeAt' => "nodes[{$i}][outcomes][{$k}]", 'outcome' => $outcome, 'onScreen' => true])
            @endforeach

            <template>@include('admin.encounters._outcome', ['outcomeAt' => "nodes[{$i}][outcomes][__K__]", 'outcome' => $blankOutcome, 'onScreen' => true])</template>
            <button type="button" class="btn btn--sm btn--ghost cut" data-add-row data-token="__K__" style="align-self:start">+ {{ Text::get('admin.encounters.add_outcome') }}</button>
        </div>

        <span class="micro" style="color:var(--ink-3)">{{ Text::get('admin.encounters.choices') }}</span>
        <div class="stack" style="--gap:var(--s-3)" data-rows>
            @foreach ($node['choices'] ?? [] as $j => $choice)
                @include('admin.encounters._choice', ['i' => $i, 'j' => $j, 'choice' => $choice])
            @endforeach

            <template>@include('admin.encounters._choice', ['i' => $i, 'j' => '__J__', 'choice' => $blankChoice])</template>
            <button type="button" class="btn btn--sm btn--ghost cut" data-add-row data-token="__J__" style="align-self:start">+ {{ Text::get('admin.encounters.add_choice') }}</button>
        </div>
        <span class="field__hint">{{ Text::get('admin.encounters.choice.check_hint') }} {{ Text::get('admin.encounters.outcome.hint') }}</span>
    </div>
</div>
