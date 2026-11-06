@use('App\Support\Text')
{{-- One participant's Synaptic Integrity as it is now and the run-only software they are bringing. Reads $person (Character) and $ids (inventory row ids). --}}
@php($fmt = fn ($n): string => rtrim(rtrim(number_format((float) $n, 1), '0'), '.'))
@php($brought = \App\Models\InventoryItem::query()->whereIn('id', $ids ?? [])->where('character_id', $person->id)->with('item.program')->get()->sortBy(fn ($row): int => $row->item->program?->kind === \App\Programs\ProgramKind::Icebreaker ? 0 : 1)->map(fn ($row): string => $row->item->displayName().($row->item->program ? ' ('.$row->item->program->kind->label().')' : '')))
<span class="micro num" style="display:block;color:var(--ink-2)">
    {{ Text::get('cyberspace.party.si', ['current' => $fmt($person->current_synaptic_integrity), 'max' => $fmt($person->stat('synaptic_integrity_max')->value)]) }}
    · {{ Text::get('cyberspace.run.setup.loadout') }}: {{ $brought->isEmpty() ? Text::get('cyberspace.party.loadout_none') : $brought->implode(', ') }}
</span>
