@use('App\Support\Text')
{{-- One prerequisite. $at is its field-name prefix; its index is a number, or the token __P__ in a template.
     Which fields matter depends on the kind; the others are ignored on save. --}}
<div class="row row--wrap" style="--gap:var(--s-2);align-items:center" data-row>
    <button type="button" class="btn btn--sm btn--ghost" data-remove-row aria-label="{{ Text::get('admin.encounters.prerequisite.remove') }}" title="{{ Text::get('admin.encounters.prerequisite.remove') }}">✕</button>
    <label class="check">
        <input type="checkbox" name="{{ $at }}[negate]" value="1" @checked((bool) ($row['negate'] ?? false))>
        <span class="check__box"></span><span>{{ Text::get('admin.encounters.prerequisite.not') }}</span>
    </label>
    <select class="select" name="{{ $at }}[kind]" style="width:11rem" aria-label="{{ Text::get('admin.encounters.prerequisite.kind') }}">
        <option value=""></option>
        @foreach ($prerequisiteKinds as $kind)
            <option value="{{ $kind->value }}" @selected(($row['kind'] ?? '') === $kind->value)>{{ $kind->label() }}</option>
        @endforeach
    </select>
    <input class="input" name="{{ $at }}[subject]" value="{{ $row['subject'] ?? '' }}" maxlength="100" placeholder="{{ Text::get('admin.encounters.prerequisite.subject') }}" style="flex:1 1 140px;min-width:0" aria-label="{{ Text::get('admin.encounters.prerequisite.subject') }}">
    <input class="input num" type="number" step="0.01" name="{{ $at }}[amount]" value="{{ $row['amount'] ?? '' }}" placeholder="{{ Text::get('admin.encounters.prerequisite.amount') }}" style="width:7rem" aria-label="{{ Text::get('admin.encounters.prerequisite.amount') }}">
    <select class="select" name="{{ $at }}[item_id]" style="flex:1 1 160px;min-width:0" aria-label="{{ Text::get('admin.encounters.outcome.item') }}">
        <option value="">{{ Text::get('admin.encounters.outcome.item') }}</option>
        <x-grouped-options :groups="$items" :selected="$row['item_id'] ?? null" />
    </select>
    <select class="select" name="{{ $at }}[entity_id]" style="flex:1 1 140px;min-width:0" aria-label="{{ Text::get('admin.encounters.outcome.entity') }}">
        <option value="">{{ Text::get('admin.encounters.outcome.entity') }}</option>
        @foreach ($entities as $entity)
            <option value="{{ $entity->id }}" @selected((int) ($row['entity_id'] ?? 0) === $entity->id)>{{ $entity->name }}</option>
        @endforeach
    </select>
</div>
