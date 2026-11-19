@use('App\Support\Text')
@use('App\Cyberspace\Party\RunPool')
{{-- THE TRADE BOX: items put up for the others of this co-op run. An accordion, closed until wanted, and only drawn open to the party
     once somebody else has joined (the poll shows and hides it). Reads $party and $character. --}}
@php($pool = app(RunPool::class))
@php($offerable = $pool->eligible($character, $party))
<details class="panel panel--snug" style="margin-top:var(--s-3)" data-party-pool-box @if (! $pool->open($party)) hidden @endif>
    <summary class="panel__hd"><h2>{{ Text::get('cyberspace.party.pool.heading') }}</h2></summary>
    <div class="stack" style="--gap:var(--s-3);padding:var(--s-3) 0">
        <p class="micro" style="margin:0;color:var(--ink-2)">{{ Text::get('cyberspace.party.pool.description') }}</p>

        @if ($offerable->isEmpty())
            <p class="micro" style="margin:0;color:var(--ink-3)">{{ Text::get('cyberspace.party.pool.nothing_to_offer') }}</p>
        @else
            <form method="POST" action="{{ route('run.party.pool.offer') }}" class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                @csrf
                <select class="input" name="item" required aria-label="{{ Text::get('cyberspace.party.pool.select') }}">
                    <option value="">{{ Text::get('cyberspace.party.pool.select') }}</option>
                    @foreach ($offerable as $row)
                        <option value="{{ $row->id }}">{{ $row->item->displayName() }} ({{ RunPool::typeOf($row->item) }})</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn--sm cut">{{ Text::get('cyberspace.party.pool.add') }}</button>
            </form>
        @endif

        <div data-party-pool>@include('run._party_pool_table')</div>
    </div>
</details>
