@use('App\Support\Text')
{{-- A member's own line: who asked them, and whether they are in and able to go. --}}
    <p style="margin:0">{{ Text::get('cyberspace.party.invited_by', ['owner' => $party->owner?->handle]) }}
        @if ($mine?->joined_at) <span class="tag">{{ Text::get('cyberspace.party.joined') }}</span> @endif
        @if ($mine?->dropped_at) <span class="tag">{{ Text::get('cyberspace.party.dropped') }}</span>
        @elseif ($mine?->joined_at && isset($obstacles[$character->id])) <span class="tag" style="color:var(--crit)">{{ $obstacles[$character->id] }}</span>
        @elseif ($mine?->joined_at) <span class="tag tag--ok">{{ Text::get('cyberspace.party.ready') }}</span> @endif</p>

{{-- Everybody who is going, the owner first, with the same two facts the owner sees. --}}
<ul style="margin:0;padding-left:var(--s-4)">
    @if ($party->owner)
        <li>{{ $party->owner->handle }} <span class="tag">{{ Text::get('cyberspace.party.owner') }}</span>
            @include('run._party_stats', ['person' => $party->owner, 'ids' => $party->owner_loadout])
        </li>
    @endif
    @foreach ($party->members as $member)
        @if ($member->joined_at && ! $member->dropped_at && $member->character)
            <li>{{ $member->character->handle }}
                @include('run._party_stats', ['person' => $member->character, 'ids' => $member->loadout])
            </li>
        @endif
    @endforeach
</ul>
