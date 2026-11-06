@use('App\Support\Text')
{{-- A member's own line: who asked them, and whether they are in and able to go. --}}
    <p style="margin:0">{{ Text::get('cyberspace.party.invited_by', ['owner' => $party->owner?->handle]) }}
        @if ($mine?->joined_at) <span class="tag">{{ Text::get('cyberspace.party.joined') }}</span> @endif
        @if ($mine?->dropped_at) <span class="tag">{{ Text::get('cyberspace.party.dropped') }}</span>
        @elseif ($mine?->joined_at && isset($obstacles[$character->id])) <span class="tag" style="color:var(--crit)">{{ $obstacles[$character->id] }}</span>
        @elseif ($mine?->joined_at) <span class="tag tag--ok">{{ Text::get('cyberspace.party.ready') }}</span> @endif</p>
