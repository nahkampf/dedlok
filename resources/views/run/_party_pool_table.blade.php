@use('App\Support\Text')
@use('App\Cyberspace\Party\RunPool')
{{-- The run pool's table: who offered what, who claimed it, and the one button each person has. Reads $party and $character. --}}
@php($entries = app(RunPool::class)->entries($party))
@if ($entries->isEmpty())
    <p class="micro" style="margin:0;color:var(--ink-3)">{{ Text::get('cyberspace.party.pool.empty') }}</p>
@else
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ Text::get('cyberspace.party.pool.handle') }}</th>
                    <th>{{ Text::get('cyberspace.party.pool.item') }}</th>
                    <th>{{ Text::get('cyberspace.party.pool.type') }}</th>
                    <th>{{ Text::get('cyberspace.party.pool.claimed_by') }}</th>
                    <th>{{ Text::get('cyberspace.party.pool.claim_return') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entries as $entry)
                    @php($mineToOffer = $entry->offered_by_character_id === $character->id)
                    @php($mineToHold = $entry->claimed_by_character_id === $character->id)
                    <tr>
                        <td>{{ $entry->offerer?->handle }}</td>
                        <td>{{ $entry->item?->displayName() }}</td>
                        <td>{{ RunPool::typeOf($entry->item) }}</td>
                        <td>{{ $entry->claimant?->handle }}</td>
                        <td>
                            @if ($entry->claimed_by_character_id === null && ! $mineToOffer)
                                <form method="POST" action="{{ route('run.party.pool.claim', $entry->id) }}">@csrf
                                    <button type="submit" class="btn btn--sm cut">{{ Text::get('cyberspace.party.pool.claim') }}</button></form>
                            @elseif (($entry->claimed_by_character_id === null && $mineToOffer) || $mineToHold)
                                <form method="POST" action="{{ route('run.party.pool.return', $entry->id) }}">@csrf
                                    <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('cyberspace.party.pool.return') }}</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
