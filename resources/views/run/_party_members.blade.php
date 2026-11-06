@use('App\Support\Text')
{{-- The owner's list of who has been asked and who is coming. Drawn by the panel and by its poll. --}}
    <ul style="margin:0;padding-left:var(--s-4)">
        @forelse ($party->members as $member)
            <li>{{ $member->character?->handle }}
                <span class="tag">{{ Text::get($member->joined_at ? 'cyberspace.party.joined' : 'cyberspace.party.invited') }}</span>
                @if ($member->dropped_at) <span class="tag">{{ Text::get('cyberspace.party.dropped') }}</span>
                @elseif ($member->joined_at && isset($obstacles[$member->character_id])) <span class="tag" style="color:var(--crit)">{{ $obstacles[$member->character_id] }}</span>
                @elseif ($member->joined_at) <span class="tag tag--ok">{{ Text::get('cyberspace.party.ready') }}</span> @endif</li>
        @empty
            <li class="muted">{{ Text::get('cyberspace.party.nobody') }}</li>
        @endforelse
    </ul>
