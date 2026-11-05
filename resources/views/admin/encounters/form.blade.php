@use('App\Support\Asset')
@use('App\Support\Text')
@extends('layouts.app')
@section('title', $encounter ? $encounter->title : Text::get('admin.encounters.new'))

@php
    $creating = $encounter === null;
    $value = fn (string $field, $fallback = null) => old($field, $encounter?->{$field} ?? $fallback);
    $enum = fn (string $field, $fallback = null) => ($v = $value($field, $fallback)) instanceof \BackedEnum ? $v->value : $v;

    // A blank row at each level: one outcome in a choice, one choice on a screen. The "+" buttons
    // add more; the same blanks are the <template>s they clone.
    $blankOutcome = ['branch' => 'always', 'kind' => '', 'amount' => '', 'ref' => '', 'item_id' => null, 'entity_id' => null, 'text' => '', 'entry_slug' => '', 'entry_text' => ''];
    $blankChoice = ['label' => '', 'requires_flag' => '', 'cost' => 0, 'next_key' => '', 'check_stat' => '', 'difficulty' => '', 'pass_key' => '', 'fail_key' => '', 'fight_npc_id' => '', 'win_key' => '', 'lose_key' => '', 'flee_key' => '', 'outcomes' => [$blankOutcome]];
    $blankNode = ['key' => '', 'body' => '', 'entry' => false, 'ends' => false, 'outcomes' => [$blankOutcome], 'choices' => [$blankChoice]];

    // What was posted back, or what is stored plus one blank row at each level.
    $nodeRows = old('nodes');
    if ($nodeRows === null) {
        $nodeRows = [
            ...array_map(fn (array $n): array => [
                ...$n,
                'outcomes' => [...$n['outcomes'], $blankOutcome],
                'choices' => [
                    ...array_map(fn (array $c): array => [...$c, 'outcomes' => [...$c['outcomes'], $blankOutcome]], $n['choices']),
                    $blankChoice,
                ],
            ], $stored),
            $blankNode,
        ];
    }
    $entryAt = old('entry');
    if ($entryAt === null) {
        $found = collect($nodeRows)->search(fn ($n) => ! empty($n['entry']));
        $entryAt = $found === false ? 0 : $found;
    }
    $keys = collect($nodeRows)->pluck('key')->filter()->values();

    // The prerequisites: what was posted back, or what is stored, plus one blank row for the "+".
    $blankPrerequisite = ['kind' => '', 'subject' => '', 'amount' => '', 'item_id' => null, 'entity_id' => null, 'negate' => false];
    $prerequisiteRows = old('prerequisites') ?? [...$storedPrerequisites, $blankPrerequisite];
@endphp

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li><a href="{{ route('admin.encounters.index') }}">{{ Text::get('admin.encounters.title') }}</a></li>
        <li aria-current="page">{{ $creating ? Text::get('admin.encounters.new') : $encounter->title }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ $creating ? Text::get('admin.encounters.new') : $encounter->title }}</h1>

    <details class="panel" style="margin-top:var(--s-4)">
        <summary class="panel__hd"><span class="micro">{{ Text::get('admin.encounters.help.title') }}</span></summary>
        <div class="panel__bd stack" style="--gap:var(--s-4)">
            @foreach (config('strings.admin.encounters.help.sections') as $section)
                <div class="stack" style="--gap:var(--s-2)">
                    <span class="micro" style="color:var(--ink-3)">{{ $section['heading'] }}</span>
                    <dl class="stack" style="margin:0;--gap:var(--s-2);font-size:var(--fs-sm)">
                        @foreach ($section['items'] as [$term, $text])
                            <div>
                                <dt style="color:var(--ink-0)">{{ $term }}</dt>
                                <dd style="margin:0;color:var(--ink-2)">{{ $text }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endforeach
        </div>
    </details>

    {{-- THE GRAPH. Drawn from the form as it is now, so it shows unsaved work and a brand-new
         encounter too (public/assets/js/encounter-graph.js → UI.nodeGraph). --}}
    <details class="panel" style="margin-top:var(--s-4)">
        <summary class="panel__hd"><span class="micro">{{ Text::get('admin.encounters.graph.title') }}</span></summary>
        <div class="panel__bd">
            <p class="field__hint" style="margin:0 0 var(--s-3)">{{ Text::get('admin.encounters.graph.hint') }}</p>
            <div class="graph" id="encounter-graph" data-label="{{ Text::get('admin.encounters.graph.title') }}"
                 data-text-entry="{{ Text::get('admin.encounters.graph.key.entry') }}"
                 data-text-end="{{ Text::get('admin.encounters.graph.key.end') }}"
                 data-text-loose="{{ Text::get('admin.encounters.graph.key.loose') }}"
                 data-text-pass="{{ Text::get('admin.encounters.graph.key.pass') }}"
                 data-text-fail="{{ Text::get('admin.encounters.graph.key.fail') }}"
                 data-text-win="{{ Text::get('admin.encounters.graph.fight.win') }}"
                 data-text-lose="{{ Text::get('admin.encounters.graph.fight.lose') }}"
                 data-text-away="{{ Text::get('admin.encounters.graph.fight.away') }}"
                 data-text-closes="{{ Text::get('admin.encounters.graph.closes') }}"
                 data-text-empty="{{ Text::get('admin.encounters.graph.no_text') }}"
                 data-text-roll="{{ Text::get('admin.encounters.graph.roll') }}"
                 data-text-arrival="{{ Text::get('admin.encounters.arrival') }}" hidden></div>
            <ul class="graph__key" aria-hidden="true">
                <li><i style="color:var(--cyan)">&#9672;</i>{{ Text::get('admin.encounters.graph.key.entry') }}</li>
                <li><i style="color:var(--ember)">&#9672;</i>{{ Text::get('admin.encounters.graph.key.end') }}</li>
                <li><i style="color:var(--ink-1)">&#9672;</i>{{ Text::get('admin.encounters.graph.key.plain') }}</li>
                <li><i style="color:var(--ink-3)">&#9636;</i>{{ Text::get('admin.encounters.graph.key.loose') }}</li>
                <li><i style="color:var(--ok)">&mdash;</i>{{ Text::get('admin.encounters.graph.key.pass') }}</li>
                <li><i style="color:var(--crit)">&mdash;</i>{{ Text::get('admin.encounters.graph.key.fail') }}</li>
                <li><i style="color:var(--ink-2)">&mdash;</i>{{ Text::get('admin.encounters.graph.key.other') }}</li>
            </ul>
        </div>
    </details>

    <x-auth-errors />

    <form method="POST" action="{{ $creating ? route('admin.encounters.store') : route('admin.encounters.update', $encounter) }}"
          class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)">
        @csrf
        @unless ($creating) @method('PUT') @endunless

        <div class="panel">
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                @if ($creating)
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.encounters.fields.slug') }}</span>
                        <input class="input" id="encounter-slug" name="slug" maxlength="40" required value="{{ $value('slug') }}">
                        <span class="field__hint">{{ Text::get('admin.encounters.fields.slug_hint') }}</span>
                    </label>
                @endif
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.title') }}</span>
                    <input class="input" name="title" maxlength="150" required value="{{ $value('title') }}">
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.starts_on') }}</span>
                    <select class="select" name="starts_on">
                        @foreach ($starts as $start)
                            <option value="{{ $start->value }}" @selected($enum('starts_on', 'travel') === $start->value)>{{ $start->label() }}</option>
                        @endforeach
                    </select>
                </label>
                @php($weight = (int) $value('weight', 10))
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.weight') }}
                        <span data-range-out style="color:var(--ink-0)">{{ Text::get('admin.encounters.weight_out', ['band' => \App\City\EncounterBand::forWeight($weight), 'weight' => $weight]) }}</span>
                    </span>
                    <input class="range" type="range" name="weight" min="1" max="100" step="1" value="{{ $weight }}"
                           data-format="{{ Text::get('admin.encounters.weight_out', ['band' => '{band}', 'weight' => '{value}']) }}"
                           data-bands="{{ json_encode(array_map(fn ($b) => [$b['upto'], Text::get('admin.encounters.weight_band.'.$b['word'])], config('city.encounter.weight_bands'))) }}">
                    <span class="field__hint">{{ Text::get('admin.encounters.fields.weight_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.chance') }}</span>
                    <input class="input num" type="number" name="chance" min="0.01" max="100" step="0.01" value="{{ $value('chance') }}">
                    <span class="field__hint">{{ Text::get('admin.encounters.fields.chance_hint', [
                        'travel' => rtrim(rtrim(number_format((float) config('city.encounter.travel_chance') * 100, 2), '0'), '.'),
                        'explore' => rtrim(rtrim(number_format((float) config('city.encounter.explore_chance') * 100, 2), '0'), '.'),
                        'move' => rtrim(rtrim(number_format((float) config('cyberspace.encounter.grid_step_chance') * 100, 2), '0'), '.'),
                        'system' => rtrim(rtrim(number_format((float) config('cyberspace.encounter.system_chance') * 100, 2), '0'), '.'),
                        'run' => rtrim(rtrim(number_format((float) config('cyberspace.encounter.run_chance') * 100, 2), '0'), '.'),
                        'after_run' => rtrim(rtrim(number_format((float) config('cyberspace.encounter.after_run_chance') * 100, 2), '0'), '.'),
                    ]) }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.district') }}</span>
                    <select class="select" name="district_id">
                        <option value="">{{ Text::get('admin.encounters.anywhere') }}</option>
                        @foreach ($districts as $district)
                            <option value="{{ $district->id }}" @selected((int) $value('district_id') === $district->id)>{{ $district->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.location') }}</span>
                    <select class="select" name="location_id">
                        <option value=""></option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected((int) $value('location_id') === $location->id)>{{ $location->name }} ({{ $location->district?->name }})</option>
                        @endforeach
                    </select>
                </label>
                {{-- The grid doors' filters. Which apply depends on the door; the others are refused on save. --}}
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.system_type') }}</span>
                    <select class="select" name="system_type">
                        <option value=""></option>
                        @foreach ($systemTypes as $type)
                            <option value="{{ $type->value }}" @selected($enum('system_type') === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.grade_min') }}</span>
                    <input class="input num" type="number" name="grade_min" min="1" max="9" value="{{ $value('grade_min') }}">
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.grade_max') }}</span>
                    <input class="input num" type="number" name="grade_max" min="1" max="9" value="{{ $value('grade_max') }}">
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.owner') }}</span>
                    <select class="select" name="owner_entity_id">
                        <option value=""></option>
                        @foreach ($owners as $owner)
                            <option value="{{ $owner->id }}" @selected((int) $value('owner_entity_id') === $owner->id)>{{ $owner->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.run_purpose') }}</span>
                    <select class="select" name="run_purpose">
                        <option value=""></option>
                        @foreach ($purposes as $purpose)
                            <option value="{{ $purpose->value }}" @selected($enum('run_purpose') === $purpose->value)>{{ $purpose->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.run_outcome') }}</span>
                    <select class="select" name="run_outcome">
                        <option value=""></option>
                        @foreach ($outcomes as $outcome)
                            <option value="{{ $outcome->value }}" @selected($enum('run_outcome') === $outcome->value)>{{ $outcome->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.spot_address') }}</span>
                    <input class="input" name="spot_address" maxlength="16" value="{{ old('spot_address', $spotAddress) }}" style="font-family:var(--font-mono)">
                    <span class="field__hint">{{ Text::get('admin.encounters.fields.spot_address_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.cooldown_hours') }}</span>
                    <input class="input num" type="number" name="cooldown_hours" min="1" max="8760" value="{{ $value('cooldown_hours') }}">
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.required_flag') }}</span>
                    <input class="input" name="required_flag" maxlength="60" value="{{ $value('required_flag') }}">
                </label>
                <label class="field" style="grid-column:1/-1">
                    <span class="field__label">{{ Text::get('admin.encounters.fields.intro') }}</span>
                    <textarea class="textarea" name="intro" maxlength="5000">{{ $value('intro') }}</textarea>
                    <span class="field__hint">{{ Text::get('admin.encounters.fields.intro_hint') }}</span>
                </label>
                <div class="row row--wrap" style="--gap:var(--s-5);grid-column:1/-1">
                    <label class="check">
                        <input type="checkbox" name="once" value="1" @checked((bool) $value('once', false))>
                        <span class="check__box"></span><span>{{ Text::get('admin.encounters.fields.once') }}</span>
                    </label>
                    <label class="check">
                        <input type="checkbox" name="retired" value="1" @checked((bool) $value('retired', false))>
                        <span class="check__box"></span><span>{{ Text::get('admin.encounters.fields.retired') }}</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.encounters.prerequisite.title') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-3)" data-rows>
                <span class="field__hint">{{ Text::get('admin.encounters.prerequisite.hint') }}</span>

                @foreach ($prerequisiteRows as $p => $row)
                    @include('admin.encounters._prerequisite', ['at' => "prerequisites[{$p}]", 'row' => $row])
                @endforeach

                <template>@include('admin.encounters._prerequisite', ['at' => 'prerequisites[__P__]', 'row' => $blankPrerequisite])</template>
                <button type="button" class="btn btn--sm btn--ghost cut" data-add-row data-token="__P__" style="align-self:start">+ {{ Text::get('admin.encounters.prerequisite.add') }}</button>
            </div>
        </div>

        <datalist id="node-keys">
            @foreach ($keys as $key) <option value="{{ $key }}"></option> @endforeach
        </datalist>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.encounters.nodes') }}</span></div>
            {{-- Rows grow with the "+" buttons (data-add-row in ui-kit.js). The server renders the
                 saved rows plus a blank one; each level also carries a <template> of a blank row,
                 rendered with a token where the index goes (__I__ screen, __J__ choice, __K__ outcome). --}}
            <div class="panel__bd stack" style="--gap:var(--s-5)" data-rows>
                <span class="field__hint">{{ Text::get('admin.encounters.nodes_hint') }}</span>

                @foreach ($nodeRows as $i => $node)
                    @include('admin.encounters._node', ['i' => $i, 'node' => $node])
                @endforeach

                <template>@include('admin.encounters._node', ['i' => '__I__', 'node' => $blankNode])</template>
                <button type="button" class="btn btn--sm btn--ghost cut" data-add-row data-token="__I__" style="align-self:start">+ {{ Text::get('admin.encounters.add_node') }}</button>
            </div>
        </div>

        <div class="row" style="--gap:var(--s-3)">
            <button type="submit" class="btn btn--primary cut">{{ Text::get('admin.encounters.save') }}</button>
        </div>
    </form>

    @unless ($creating)
        <form method="POST" action="{{ route('admin.encounters.destroy', $encounter) }}" style="margin-top:var(--s-6)"
              onsubmit="return confirm('{{ Text::get('admin.encounters.confirm_delete') }}')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn--ghost cut">{{ Text::get('admin.encounters.delete') }}</button>
        </form>
    @endunless
@endsection

@push('scripts')
    <script src="{{ Asset::url('assets/js/encounter-graph.js') }}" defer></script>
@endpush
