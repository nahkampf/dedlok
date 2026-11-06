@use('App\Support\Text')
@use('App\Cyberspace\Party\ShareMode')
{{-- The party at this door: open one (when trained), set the two sharing options, invite, accept. Reads $party, $coopSlots, $character. --}}
@php($isOwner = $party && $party->owner_character_id === $character->id)
@php($mine = $party && ! $isOwner ? $party->members->firstWhere('character_id', $character->id) : null)
@if ($party || $coopSlots > 0)
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <span class="micro">{{ Text::get('cyberspace.party.heading') }}</span>
        <div class="spacer"></div>
        <span class="micro num">{{ Text::get('cyberspace.party.slots', ['slots' => $coopSlots]) }}</span>
    </div>
    <div class="panel__bd stack" style="--gap:var(--s-3)" @if ($party) data-party-poll="{{ route('run.party.status') }}" data-party-role="{{ $isOwner ? 'owner' : 'member' }}" @endif>
        @if (! $party)
            <form method="POST" action="{{ route('run.party.open') }}" class="stack" style="--gap:var(--s-2)">
                @csrf
                @include('run._party_modes', ['credits' => ShareMode::Even, 'extraction' => ShareMode::Even])
                <div><button type="submit" class="btn btn--sm cut">{{ Text::get('cyberspace.party.open') }}</button></div>
            </form>
        @elseif ($isOwner)
            @if ($party->members->isEmpty())
                <form method="POST" action="{{ route('run.party.options') }}" class="stack" style="--gap:var(--s-2)">
                    @csrf
                    @include('run._party_modes', ['credits' => $party->share_credits, 'extraction' => $party->share_extraction])
                    <div><button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('cyberspace.party.save') }}</button></div>
                </form>
            @else
                {{-- Somebody has been invited under these terms, so they are fixed: the same line the members read. --}}
                <p class="micro" style="margin:0;color:var(--ink-3)">
                    {{ Text::get('cyberspace.party.terms', ['credits' => $party->share_credits->label(), 'extraction' => $party->share_extraction->label()]) }}
                </p>
            @endif

            {{-- The run's id, to paste in chat: anybody at this system who clicks it joins while there are slots. --}}
            <div class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                <button type="button" class="copy" data-copy="run:{{ $party->id }}"
                        data-copied="{{ Text::get('cyberspace.reading.copied') }}"
                        data-copy-failed="{{ Text::get('cyberspace.reading.copy_failed') }}"
                        title="{{ Text::get('cyberspace.party.copy_link') }}">run:{{ $party->id }}</button>
                <span class="micro" style="color:var(--ink-3)">{{ Text::get('cyberspace.party.copy_link') }}</span>
            </div>

            <div data-party-live>@include('run._party_members')</div>

            @if ($party->members->count() < $coopSlots)
                <form method="POST" action="{{ route('run.party.invite') }}" class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                    @csrf
                    <input class="input" type="text" name="handle" maxlength="40" required
                           placeholder="{{ Text::get('cyberspace.party.handle') }}" aria-label="{{ Text::get('cyberspace.party.handle') }}">
                    <button type="submit" class="btn btn--sm cut">{{ Text::get('cyberspace.party.invite') }}</button>
                </form>
            @endif

            <form method="POST" action="{{ route('run.party.leave') }}">
                @csrf
                <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('cyberspace.party.disband') }}</button>
            </form>
        @else
            <div data-party-live>@include('run._party_me')</div>
            <p class="micro" style="margin:0;color:var(--ink-3)">
                {{ Text::get('cyberspace.party.terms', ['credits' => $party->share_credits->label(), 'extraction' => $party->share_extraction->label()]) }}
            </p>
            <form method="POST" action="{{ route('run.party.leave') }}">@csrf
                <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get($mine?->joined_at ? 'cyberspace.party.leave' : 'cyberspace.party.decline') }}</button></form>
        @endif
    </div>
</div>
@endif
