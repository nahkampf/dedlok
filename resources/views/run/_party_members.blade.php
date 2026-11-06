@use('App\Support\Text')
{{-- The owner's list of who has been asked and who is coming. Drawn by the panel and by its poll. --}}
    <ul style="margin:0;padding-left:var(--s-4)">
        @if ($party->owner)
            <li>{{ $party->owner->handle }} <span class="tag">{{ Text::get('cyberspace.party.owner') }}</span>
                @include('run._party_stats', ['person' => $party->owner, 'ids' => $party->owner_loadout])
            </li>
        @endif
        @forelse ($party->members as $member)
            <li>{{ $member->character?->handle }}
                <span class="tag">{{ Text::get($member->joined_at ? 'cyberspace.party.joined' : 'cyberspace.party.invited') }}</span>
                @if ($member->dropped_at) <span class="tag">{{ Text::get('cyberspace.party.dropped') }}</span>
                @elseif ($member->joined_at && isset($obstacles[$member->character_id])) <span class="tag" style="color:var(--crit)">{{ $obstacles[$member->character_id] }}</span>
                @elseif ($member->joined_at) <span class="tag tag--ok">{{ Text::get('cyberspace.party.ready') }}</span> @endif
                {{-- Who has said they are coming, and with what: their Synaptic Integrity as it is now, and the loadout they chose. --}}
                @if ($member->joined_at && ! $member->dropped_at && $member->character)
                    @include('run._party_stats', ['person' => $member->character, 'ids' => $member->loadout])
                @endif
            </li>
        @empty
            <li class="muted">{{ Text::get('cyberspace.party.nobody') }}</li>
        @endforelse
    </ul>
