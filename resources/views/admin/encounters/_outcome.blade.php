@use('App\Support\Text')
{{-- One outcome. $outcomeAt is its field-name prefix; $onScreen is true for a screen's own, which has no
     pass/fail branch (it always applies). Its index is a number, or the token __K__ in a template. --}}
@php($oat = $outcomeAt)
<div class="row row--wrap" style="--gap:var(--s-2)" data-row>
    <button type="button" class="btn btn--sm btn--ghost" data-remove-row aria-label="{{ Text::get('admin.encounters.remove_outcome') }}" title="{{ Text::get('admin.encounters.remove_outcome') }}">✕</button>
    @if ($onScreen ?? false)
        <input type="hidden" name="{{ $oat }}[branch]" value="always">
    @else
        <select class="select" name="{{ $oat }}[branch]" style="width:8rem" aria-label="{{ Text::get('admin.encounters.outcome.branch') }}">
            @foreach ($branches as $branch)
                <option value="{{ $branch->value }}" @selected(($outcome['branch'] ?? 'always') === $branch->value)>{{ Text::get('admin.encounters.branches.'.$branch->value) }}</option>
            @endforeach
        </select>
    @endif
    {{-- Injury and Damage are groups of their types ("injury:neural_damage"). Picking one fills the
         text field beside it (data-split, ui-kit.js); the request reads the kind from before the
         colon and uses the text field if it was edited since. --}}
    @php($kindNow = (string) ($outcome['kind'] ?? ''))
    @php($refNow = (string) ($outcome['ref'] ?? ''))
    @php($groups = ['injury' => $injuries, 'damage' => $damageTypes])
    @php($pickedKind = isset($groups[$kindNow]) && $refNow !== '' ? $kindNow.':'.$refNow : $kindNow)
    <select class="select" name="{{ $oat }}[kind]" data-split style="width:12rem" aria-label="{{ Text::get('admin.encounters.outcome.kind') }}">
        <option value=""></option>
        @foreach ($kinds as $kind)
            @continue(isset($groups[$kind->value]))
            <option value="{{ $kind->value }}" @selected($pickedKind === $kind->value)>{{ $kind->label() }}</option>
        @endforeach
        @foreach ($groups as $word => $types)
            <optgroup label="{{ \App\City\OutcomeKind::from($word)->label() }}">
                @foreach ($types as $type => $label)
                    <option value="{{ $word }}:{{ $type }}" @selected($pickedKind === $word.':'.$type)>{{ $label }}</option>
                @endforeach
                {{-- A stored target that is not on the list stays selectable, so saving does not drop it. --}}
                @if ($kindNow === $word && $refNow !== '' && ! isset($types[$refNow]))
                    <option value="{{ $word }}:{{ $refNow }}" selected>{{ $refNow }}</option>
                @endif
            </optgroup>
        @endforeach
    </select>
    <input class="input num" type="number" step="0.01" name="{{ $oat }}[amount]" value="{{ $outcome['amount'] ?? '' }}" placeholder="{{ Text::get('admin.encounters.outcome.amount') }}" title="{{ Text::get('admin.encounters.outcome.amount') }}" style="width:7rem" aria-label="{{ Text::get('admin.encounters.outcome.amount') }}">
    <input class="input" data-split-target name="{{ $oat }}[ref]" value="{{ $outcome['ref'] ?? '' }}" placeholder="{{ Text::get('admin.encounters.outcome.ref') }}" title="{{ Text::get('admin.encounters.outcome.ref') }}" style="flex:1 1 140px;min-width:0" aria-label="{{ Text::get('admin.encounters.outcome.ref') }}">
    <select class="select" name="{{ $oat }}[item_id]" style="flex:1 1 160px;min-width:0" aria-label="{{ Text::get('admin.encounters.outcome.item') }}">
        <option value="">{{ Text::get('admin.encounters.outcome.item') }}</option>
        <x-grouped-options :groups="$items" :selected="$outcome['item_id'] ?? null" />
    </select>
    <select class="select" name="{{ $oat }}[entity_id]" style="flex:1 1 140px;min-width:0" aria-label="{{ Text::get('admin.encounters.outcome.entity') }}">
        <option value="">{{ Text::get('admin.encounters.outcome.entity') }}</option>
        @foreach ($entities as $entity)
            <option value="{{ $entity->id }}" @selected((int) ($outcome['entity_id'] ?? 0) === $entity->id)>{{ $entity->name }}</option>
        @endforeach
    </select>
    {{-- Journal outcomes: the note's slug is the field above (Flag, injury or resource). These are its heading (when the
         note is new) and a line to add under it. --}}
    <input class="input" data-message @disabled($kindNow !== 'message') name="{{ $oat }}[text]" maxlength="120" value="{{ $outcome['text'] ?? '' }}" placeholder="{{ Text::get('admin.encounters.outcome.message_title') }}" title="{{ Text::get('admin.encounters.outcome.message_title') }}" style="flex:1 1 100%;min-width:0" aria-label="{{ Text::get('admin.encounters.outcome.message_title') }}">
    <input class="input" data-message @disabled($kindNow !== 'message') name="{{ $oat }}[entry_text]" maxlength="500" value="{{ $outcome['entry_text'] ?? '' }}" placeholder="{{ Text::get('admin.encounters.outcome.message_body') }}" title="{{ Text::get('admin.encounters.outcome.message_body') }}" style="flex:1 1 100%;min-width:0" aria-label="{{ Text::get('admin.encounters.outcome.message_body') }}">
    <input class="input" data-journal @disabled($kindNow !== 'journal') name="{{ $oat }}[text]" maxlength="255" value="{{ $outcome['text'] ?? '' }}" placeholder="{{ Text::get('admin.encounters.outcome.journal_heading') }}" title="{{ Text::get('admin.encounters.outcome.journal_heading') }}" style="flex:1 1 100%;min-width:0" aria-label="{{ Text::get('admin.encounters.outcome.journal_heading') }}">
    <input class="input" data-journal @disabled($kindNow !== 'journal') name="{{ $oat }}[entry_slug]" maxlength="60" value="{{ $outcome['entry_slug'] ?? '' }}" placeholder="{{ Text::get('admin.encounters.outcome.journal_line_slug') }}" title="{{ Text::get('admin.encounters.outcome.journal_line_slug') }}" style="flex:1 1 160px;min-width:0" aria-label="{{ Text::get('admin.encounters.outcome.journal_line_slug') }}">
    <input class="input" data-journal @disabled($kindNow !== 'journal') name="{{ $oat }}[entry_text]" maxlength="500" value="{{ $outcome['entry_text'] ?? '' }}" placeholder="{{ Text::get('admin.encounters.outcome.journal_line') }}" title="{{ Text::get('admin.encounters.outcome.journal_line') }}" style="flex:3 1 240px;min-width:0" aria-label="{{ Text::get('admin.encounters.outcome.journal_line') }}">
</div>
