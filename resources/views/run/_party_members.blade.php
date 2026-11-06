@use('App\Support\Text')
{{-- The owner's list of who has been asked and who is coming. Drawn by the panel and by its poll. --}}
    <ul style="margin:0;padding-left:var(--s-4)">
        @forelse ($party->members as $member)
            <li>{{ $member->character?->handle }}
                <span class="tag">{{ Text::get($member->joined_at ? 'cyberspace.party.joined' : 'cyberspace.party.invited') }}</span>
                @if ($member->dropped_at) <span class="tag">{{ Text::get('cyberspace.party.dropped') }}</span>
                @elseif ($member->joined_at && isset($obstacles[$member->character_id])) <span class="tag" style="color:var(--crit)">{{ $obstacles[$member->character_id] }}</span>
                @elseif ($member->joined_at) <span class="tag tag--ok">{{ Text::get('cyberspace.party.ready') }}</span> @endif
                {{-- Who has said they are coming, and with what: their Synaptic Integrity as it is now, and the loadout they chose. --}}
                @if ($member->joined_at && ! $member->dropped_at && $member->character)
                    @php($fmt = fn ($n): string => rtrim(rtrim(number_format((float) $n, 1), '0'), '.'))
                    @php($brought = \App\Models\InventoryItem::query()->whereIn('id', $member->loadout ?? [])->where('character_id', $member->character_id)->with('item')->get()->map(fn ($row): string => $row->item->displayName()))
                    <span class="micro num" style="display:block;color:var(--ink-2)">
                        {{ Text::get('cyberspace.party.si', ['current' => $fmt($member->character->current_synaptic_integrity), 'max' => $fmt($member->character->stat('synaptic_integrity_max')->value)]) }}
                        · {{ Text::get('cyberspace.run.setup.loadout') }}: {{ $brought->isEmpty() ? Text::get('cyberspace.party.loadout_none') : $brought->implode(', ') }}
                    </span>
                @endif
            </li>
        @empty
            <li class="muted">{{ Text::get('cyberspace.party.nobody') }}</li>
        @endforelse
    </ul>
