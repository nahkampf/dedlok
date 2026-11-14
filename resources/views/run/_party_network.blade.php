@use('App\Support\Text')
{{-- A TREE RUN'S TRACE NETWORK (docs/RUN_TREE.md): the whole crew comes in over one runner's routing chain and shares one
     trace. The owner picks it from every runner's network here; the members read which. Drawn in the live part of the
     panel, so the list follows who has joined. Nothing on a system that walks no tree. --}}
@php($networks = app(\App\Cyberspace\Party\Parties::class)->networks($party))
@php($label = static fn (array $n): string => Text::get('cyberspace.party.network_option', ['handle' => $n['character']->handle, 'grades' => $n['chain']->map(fn ($hop) => Text::get('cyberspace.run.setup.hop', ['grade' => $hop->grade]))->implode(', ')]))
@php($picked = collect($networks)->first(fn (array $n): bool => $n['character']->id === $party->chain_character_id))
@php($system = \App\Models\System::query()->where('address', $party->address)->first())
@if ($system !== null && \App\Cyberspace\Tree\RunTree::appliesTo($system))
    @if ($party->owner_character_id === $character->id)
        <form method="POST" action="{{ route('run.party.network') }}" class="stack" style="--gap:var(--s-2);margin-top:var(--s-3)">
            @csrf
            <fieldset class="stack" style="--gap:var(--s-2);border:0;padding:0;margin:0">
                <legend class="micro" style="color:var(--ink-2);margin-bottom:var(--s-2)">{{ Text::get('cyberspace.party.network') }}</legend>
                @foreach ($networks as $network)
                    <label class="check check--radio">
                        <input type="radio" name="network" value="{{ $network['character']->id }}" @checked($picked === $network)>
                        <span class="check__box"></span>
                        <span>{{ $network['character']->handle }} &ndash;
                            <span class="tag-chain">
                                @foreach ($network['chain'] as $hop)
                                    @unless ($loop->first)<span class="tag-chain__wire" aria-hidden="true"></span>@endunless
                                    <span class="tag" title="{{ $hop->displayName() }}">{{ Text::get('cyberspace.run.setup.hop', ['grade' => $hop->grade]) }}</span>
                                @endforeach
                            </span>
                        </span>
                    </label>
                @endforeach
                <label class="check check--radio">
                    <input type="radio" name="network" value="" @checked($picked === null)>
                    <span class="check__box"></span>
                    <span>{{ Text::get('cyberspace.party.network_none') }}</span>
                </label>
            </fieldset>
            <div><button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('cyberspace.party.network_set') }}</button></div>
        </form>
    @else
        <p class="micro" style="margin:var(--s-3) 0 0;color:var(--ink-3)">
            {{ Text::get('cyberspace.party.network_is', ['network' => $picked === null ? Text::get('cyberspace.party.network_none') : $label($picked)]) }}
        </p>
    @endif
@endif
