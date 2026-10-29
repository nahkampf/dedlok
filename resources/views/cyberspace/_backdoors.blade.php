@use('App\Support\Text')
{{-- THE BACKDOORS HELD, a fragment fetched when the modal opens. The jump button is the Jumper's: <x-jump> draws it only
     while one runs on the equipped deck. --}}
@if ($rows->isEmpty())
    <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('cyberspace.backdoors.none') }}</p>
@else
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ Text::get('cyberspace.known.name') }}</th>
                    <th>{{ Text::get('cyberspace.known.address') }}</th>
                    <th>{{ Text::get('cyberspace.known.type') }}</th>
                    <th class="t-right">{{ Text::get('cyberspace.known.grade') }}</th>
                    <th>{{ Text::get('cyberspace.backdoors.kind') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $backdoor)
                    @php($system = $backdoor->system)
                    <tr>
                        <td>{{ \Illuminate\Support\Str::after($system->displayName(), $system->type->label().' - ') }}</td>
                        <td>
                            <span class="row" style="--gap:var(--s-2);align-items:center;flex-wrap:nowrap">
                                <x-address :address="$system->address" />
                                <x-jump :address="$system->address" />
                            </span>
                        </td>
                        <td class="muted">{{ $system->type->label() }}</td>
                        <td class="t-right num">{{ $system->grade ?? '—' }}</td>
                        <td>{{ $backdoor->kind->label() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
