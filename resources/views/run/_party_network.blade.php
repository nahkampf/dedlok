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
        <form method="POST" action="{{ route('run.party.network') }}" class="row row--wrap" style="--gap:var(--s-2);align-items:flex-end;margin-top:var(--s-3)">
            @csrf
            <label class="field" style="min-width:min(18rem,100%)">
                <span class="field__label">{{ Text::get('cyberspace.party.network') }}</span>
                <select class="input" name="network">
                    @foreach ($networks as $network)
                        <option value="{{ $network['character']->id }}" @selected($picked === $network)>{{ $label($network) }}</option>
                    @endforeach
                    <option value="" @selected($picked === null)>{{ Text::get('cyberspace.party.network_none') }}</option>
                </select>
            </label>
            <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('cyberspace.party.network_set') }}</button>
        </form>
    @else
        <p class="micro" style="margin:var(--s-3) 0 0;color:var(--ink-3)">
            {{ Text::get('cyberspace.party.network_is', ['network' => $picked === null ? Text::get('cyberspace.party.network_none') : $label($picked)]) }}
        </p>
    @endif
@endif
