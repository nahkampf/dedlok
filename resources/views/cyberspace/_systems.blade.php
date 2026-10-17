@use('App\Support\Text')
{{-- THE KNOWN SYSTEMS, a fragment fetched when the modal opens and again whenever a filter changes (ui-kit.js,
     [data-fragment] and form[data-fragment-filter]). Filters combine: all of them must match. --}}
<form data-fragment-filter class="row row--wrap" style="--gap:var(--s-3);align-items:end;margin-bottom:var(--s-3)" onsubmit="return false">
    <label class="field" style="margin:0;flex:1 1 180px;min-width:0">
        <span class="field__label">{{ Text::get('cyberspace.known.owner') }}</span>
        <select class="select" name="owner">
            <option value="">{{ Text::get('cyberspace.known.any') }}</option>
            <option value="__none" @selected($selected['owner'] === '__none')>{{ Text::get('cyberspace.known.unowned') }}</option>
            @foreach ($options['owners'] as $name)
                <option value="{{ $name }}" @selected($selected['owner'] === $name)>{{ $name }}</option>
            @endforeach
        </select>
    </label>
    <label class="field" style="margin:0;flex:1 1 140px;min-width:0">
        <span class="field__label">{{ Text::get('cyberspace.known.type') }}</span>
        <select class="select" name="type">
            <option value="">{{ Text::get('cyberspace.known.any') }}</option>
            @foreach ($options['types'] as $type)
                <option value="{{ $type->value }}" @selected($selected['type'] === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </select>
    </label>
    <label class="field" style="margin:0;flex:0 1 120px;min-width:0">
        <span class="field__label">{{ Text::get('cyberspace.known.grade') }}</span>
        <select class="select" name="grade">
            <option value="">{{ Text::get('cyberspace.known.any') }}</option>
            @foreach ($options['grades'] as $grade)
                <option value="{{ $grade }}" @selected($selected['grade'] === (string) $grade)>{{ $grade }}</option>
            @endforeach
            <option value="unknown" @selected($selected['grade'] === 'unknown')>{{ Text::get('cyberspace.known.grade_unknown') }}</option>
        </select>
    </label>
</form>

@if ($rows->isEmpty())
    <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ $known === 0 ? Text::get('cyberspace.known.none') : Text::get('cyberspace.known.no_match') }}</p>
@else
    <p class="micro" style="color:var(--ink-3);margin:0 0 var(--s-2)">
        {{ Text::get($total > $rows->count() ? 'cyberspace.known.count_capped' : 'cyberspace.known.count', ['shown' => $rows->count(), 'total' => $total]) }}
    </p>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ Text::get('cyberspace.known.owner') }}</th>
                    <th>{{ Text::get('cyberspace.known.name') }}</th>
                    <th>{{ Text::get('cyberspace.known.type') }}</th>
                    <th class="t-right">{{ Text::get('cyberspace.known.grade') }}</th>
                    <th>{{ Text::get('cyberspace.known.address') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['owner'] !== '' ? $row['owner'] : Text::get('cyberspace.known.unowned') }}</td>
                        <td>
                            {{-- Pointing at the name or the address floats the system's card; clicking the name opens it. --}}
                            <button type="button" class="link" data-inspect="#sys-{{ $row['address'] }}" aria-haspopup="dialog"
                                    style="background:none;border:0;padding:0;color:inherit;font:inherit;text-align:left;cursor:pointer">{{ $row['name'] }}</button>
                        </td>
                        <td class="muted">{{ $row['type']->label() }}</td>
                        <td class="t-right num">{{ $row['grade'] ?? '—' }}</td>
                        <td>
                            <span class="row" style="--gap:var(--s-2);align-items:center;flex-wrap:nowrap">
                                <span data-inspect="#sys-{{ $row['address'] }}" data-inspect-hover><x-address :address="$row['address']" /></span>
                                <x-jump :address="$row['address']" />
                                @if ($row['complete'])
                                    <span class="mark--ok" title="{{ Text::get('cyberspace.known.full_scan') }}" aria-label="{{ Text::get('cyberspace.known.full_scan') }}">&#10003;</span>
                                @endif
                            </span>
                            <template id="sys-{{ $row['address'] }}">
                                <div class="item-card">
                                    <div class="item-card__hd">
                                        <strong>{{ $row['name'] }}</strong>
                                        <div class="micro num"><x-address :address="$row['address']" /></div>
                                    </div>
                                    <div class="card__stats" style="padding:0;border:0">
                                        <div class="card__stat"><span>{{ Text::get('cyberspace.known.owner') }}</span><b>{{ $row['owner'] !== '' ? $row['owner'] : Text::get('cyberspace.known.unowned') }}</b></div>
                                        <div class="card__stat"><span>{{ Text::get('cyberspace.known.type') }}</span><b>{{ $row['type']->label() }}</b></div>
                                    </div>
                                    @if ($row['scan'])
                                        <x-scan-facts :facts="$row['scan']->facts($character)" />
                                    @else
                                        <div class="micro" style="color:var(--ink-3);padding:var(--s-3)">{{ Text::get('cyberspace.known.no_scan') }}</div>
                                    @endif
                                </div>
                            </template>
                        </td>
                        <td class="t-right">
                            <form method="POST" action="{{ route('cyberspace.jump') }}">
                                @csrf
                                <input type="hidden" name="address" value="{{ $row['address'] }}">
                                <button type="submit" class="btn btn--sm cut" @unless ($jackedIn) disabled @endunless>{{ Text::get('cyberspace.map.jump') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
