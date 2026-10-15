@use('App\Support\Text')
@use('App\Support\Qb')
@extends('layouts.app')
@section('title', Text::get('cyberspace.fortress.host.title'))

@section('content')
<a class="btn btn--quiet btn--sm" href="{{ route('cyberspace.index') }}">← {{ Text::get('cyberspace.fortress.host.back') }}</a>

<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <span class="micro">{{ $fortress->displayName() }}</span>
        <div class="spacer"></div>
        <span class="tag {{ $online ? '' : 'tag--ember' }}">{{ Text::get($online ? 'cyberspace.fortress.host.online' : 'cyberspace.fortress.host.offline') }}</span>
    </div>
    <div class="panel__bd stack" style="--gap:var(--s-2)">
        @foreach (['compute' => 'compute', 'memory' => 'memory', 'storage' => 'storage'] as $key => $label)
            <div class="row row--wrap micro num" style="justify-content:space-between">
                <span>{{ Text::get("cyberspace.fortress.host.{$label}") }}</span>
                <span>{{ Qb::format($used[$key]) }} / {{ Qb::format($totals[$key]) }}</span>
            </div>
        @endforeach
        @unless ($online)
            <p class="micro" style="margin:0;color:var(--ink-2)">{{ Text::get('cyberspace.fortress.host.offline_note', ['hours' => config('cyberspace.fortress.abandon_hours', 48)]) }}</p>
        @endunless
    </div>
</div>

@if ($role === 'owner')
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd"><span class="micro">{{ Text::get('cyberspace.fortress.host.areas') }}</span></div>
    <div class="panel__bd stack" style="--gap:var(--s-3)">
        <p class="micro" style="margin:0;color:var(--ink-3)">{{ Text::get('cyberspace.fortress.host.owner_storage') }}</p>
        @foreach ($areas as $area)
            <div class="stack" style="--gap:var(--s-2)">
                <div class="row row--wrap" style="justify-content:space-between;align-items:center">
                    <strong>{{ $area->name }}</strong>
                    <form method="POST" action="{{ route('fortress.areas.delete', [$fortress, $area]) }}">@csrf @method('DELETE')
                        <button class="btn btn--sm btn--ghost cut" type="submit">{{ Text::get('cyberspace.fortress.host.delete') }}</button></form>
                </div>
                @foreach (array_merge(['members'], array_map(fn ($r) => "role:{$r}", $roleNames)) as $audience)
                    <form method="POST" action="{{ route('fortress.areas.allow', [$fortress, $area]) }}" class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                        @csrf <input type="hidden" name="audience" value="{{ $audience }}">
                        <span class="micro" style="min-width:8rem">{{ $audience === 'members' ? Text::get('cyberspace.fortress.host.area_members') : $audience }}</span>
                        @foreach (['see' => 'see', 'deposit' => 'deposit_perm', 'withdraw' => 'withdraw_perm'] as $perm => $label)
                            <label class="row" style="--gap:var(--s-1)"><input type="checkbox" name="perms[]" value="{{ $perm }}" @checked(in_array($perm, (array) ($area->permissions[$audience] ?? []), true))>
                                <span class="micro">{{ Text::get("cyberspace.fortress.host.{$label}") }}</span></label>
                        @endforeach
                        <button class="btn btn--sm btn--ghost cut" type="submit">{{ Text::get('cyberspace.fortress.host.save') }}</button>
                    </form>
                @endforeach
            </div>
        @endforeach
        <form method="POST" action="{{ route('fortress.areas.create', $fortress) }}" class="row row--wrap" style="--gap:var(--s-2);align-items:flex-end">
            @csrf
            <label class="field"><span class="field__label">{{ Text::get('cyberspace.fortress.host.new_area') }}</span>
                <input class="input" name="name" maxlength="40" required></label>
            <button class="btn btn--sm cut" type="submit">{{ Text::get('cyberspace.fortress.host.create') }}</button>
        </form>
    </div>
</div>

<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd"><span class="micro">{{ Text::get('cyberspace.fortress.host.roles') }}</span></div>
    <div class="panel__bd stack" style="--gap:var(--s-2)">
        <form method="POST" action="{{ route('fortress.roles', $fortress) }}" class="row row--wrap" style="--gap:var(--s-3);align-items:flex-end">
            @csrf
            <label class="field"><span class="field__label">{{ Text::get('cyberspace.fortress.host.name') }}</span>
                <input class="input" name="name" maxlength="30" required></label>
            @foreach (['host', 'maintain', 'alarm', 'audit'] as $prop)
                <label class="row" style="--gap:var(--s-1)"><input type="checkbox" name="properties[]" value="{{ $prop }}"><span class="micro">{{ $prop }}</span></label>
            @endforeach
            <button class="btn btn--sm cut" type="submit">{{ Text::get('cyberspace.fortress.host.save') }}</button>
        </form>
    </div>
</div>
@endif

@if (\App\Cyberspace\FortressAccess::can($character, $fortress, 'host'))
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <span class="micro">{{ Text::get('cyberspace.fortress.host.market') }}</span>
        <div class="spacer"></div>
        <span class="micro num">{{ Text::get('cyberspace.fortress.host.wallet') }}: {{ number_format((int) $fortress->wallet) }}</span>
    </div>
    <div class="panel__bd stack" style="--gap:var(--s-3)">
        <form method="POST" action="{{ route('fortress.market.config', $fortress) }}" class="row row--wrap" style="--gap:var(--s-2);align-items:flex-end">
            @csrf
            <label class="field"><span class="field__label">{{ Text::get('cyberspace.fortress.host.market_area') }}</span>
                <select class="input" name="area">
                    <option value="">—</option>
                    @foreach ($areas as $area)
                        <option value="{{ $area->id }}" @selected((int) $fortress->market_area_id === $area->id)>{{ $area->name }}</option>
                    @endforeach
                </select></label>
            <label class="field"><span class="field__label">{{ Text::get('cyberspace.fortress.host.tax') }}</span>
                <input class="input num" type="number" name="tax" min="0" max="50" value="{{ $fortress->market_tax }}" style="width:5rem"></label>
            <button class="btn btn--sm cut" type="submit">{{ Text::get('cyberspace.fortress.host.save') }}</button>
        </form>
        <form method="POST" action="{{ route('fortress.wallet', $fortress) }}" class="row row--wrap" style="--gap:var(--s-2);align-items:flex-end">
            @csrf
            <input class="input num" type="number" name="amount" min="1" max="{{ (int) $fortress->wallet }}" required style="width:8rem">
            <button class="btn btn--sm cut" type="submit">{{ Text::get('cyberspace.fortress.host.take_out') }}</button>
        </form>
    </div>
</div>
@endif

@if (\App\Cyberspace\FortressAccess::can($character, $fortress, 'host'))
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd"><span class="micro">{{ Text::get('cyberspace.fortress.host.production') }}</span></div>
    <div class="panel__bd stack" style="--gap:var(--s-3)">
        @if ($role === 'owner')
            <form method="POST" action="{{ route('fortress.output', $fortress) }}" class="row row--wrap" style="--gap:var(--s-2);align-items:flex-end">
                @csrf
                <label class="field"><span class="field__label">{{ Text::get('cyberspace.fortress.host.output') }}</span>
                    <select class="input" name="area">
                        <option value="">{{ Text::get('cyberspace.fortress.host.owner_storage') }}</option>
                        @foreach ($areas as $area)
                            <option value="{{ $area->id }}" @selected((int) $fortress->output_area_id === $area->id)>{{ $area->name }}</option>
                        @endforeach
                    </select></label>
                <button class="btn btn--sm cut" type="submit">{{ Text::get('cyberspace.fortress.host.save') }}</button>
            </form>
        @endif
        @foreach ($jobs as $job)
            <div class="row row--wrap" style="justify-content:space-between;align-items:center">
                <span>{{ $job->kind === 'program' ? Text::get('cyberspace.fortress.host.job_program') : Text::get('cyberspace.fortress.host.job_components') }}
                    <span class="micro num" style="color:var(--ink-3)">{{ number_format(min(100, $job->progress / max(0.001, $job->work) * 100)) }}%@if (! $job->started && $job->kind === 'program') · {{ Text::get('cyberspace.fortress.host.blocked') }}@endif</span></span>
                <form method="POST" action="{{ route('fortress.jobs.cancel', [$fortress, $job]) }}">@csrf @method('DELETE')
                    <button class="btn btn--sm btn--ghost cut" type="submit">{{ Text::get('cyberspace.fortress.host.cancel') }}</button></form>
            </div>
        @endforeach
        <form method="POST" action="{{ route('fortress.jobs.queue', $fortress) }}" class="row row--wrap" style="--gap:var(--s-2);align-items:flex-end">
            @csrf
            <select class="input" name="kind">
                <option value="components">{{ Text::get('cyberspace.fortress.host.job_components') }}</option>
                <option value="program">{{ Text::get('cyberspace.fortress.host.job_program') }}</option>
            </select>
            <select class="input" name="design">
                <option value="">—</option>
                @foreach ($designs as $d)
                    <option value="{{ $d->item_id }}">{{ $d->item?->displayName() }}</option>
                @endforeach
            </select>
            <input class="input num" type="number" name="count" min="1" max="20" value="1" style="width:5rem" aria-label="{{ Text::get('cyberspace.fortress.host.count') }}">
            <button class="btn btn--sm cut" type="submit">{{ Text::get('cyberspace.fortress.host.queue') }}</button>
        </form>
    </div>
</div>
@endif

@if ($canAudit)
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd"><span class="micro">{{ Text::get('cyberspace.fortress.host.log') }}</span></div>
    <div class="panel__bd stack" style="--gap:var(--s-1)">
        @forelse ($log as $line)
            <div class="micro num" style="color:var(--ink-3)">{{ $line->created_at }} · {{ $line->handle ?? '—' }} · {{ $line->action }}{{ $line->detail ? ' · '.$line->detail : '' }}</div>
        @empty
            <p class="muted" style="margin:0">—</p>
        @endforelse
    </div>
</div>
@endif

@if ($role === 'owner')
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd"><span class="micro">{{ Text::get('cyberspace.fortress.host.settings') }}</span></div>
    <div class="panel__bd stack" style="--gap:var(--s-3)">
        <form method="POST" action="{{ route('fortress.settings', $fortress) }}" class="row row--wrap" style="--gap:var(--s-2);align-items:flex-end">
            @csrf
            <label class="field"><span class="field__label">{{ Text::get('cyberspace.fortress.host.name') }}</span>
                <input class="input" name="name" maxlength="48" value="{{ $fortress->name }}"></label>
            <label class="field"><span class="field__label">{{ Text::get('cyberspace.fortress.host.access') }}</span>
                <select class="input" name="access">
                    @foreach (['private', 'invited', 'public'] as $mode)
                        <option value="{{ $mode }}" @selected($fortress->access === $mode)>{{ Text::get("cyberspace.fortress.host.access_{$mode}") }}</option>
                    @endforeach
                </select></label>
            <button class="btn btn--sm cut" type="submit">{{ Text::get('cyberspace.fortress.host.save') }}</button>
        </form>
        @if ($fortress->fortress_cloak !== null)
            <form method="POST" action="{{ route('fortress.cloak', $fortress) }}" class="row row--wrap" style="--gap:var(--s-2);align-items:flex-end">
                @csrf
                <span class="micro" style="flex-basis:100%">{{ Text::get('cyberspace.fortress.host.cloak') }}</span>
                <label class="field"><span class="field__label">{{ Text::get('cyberspace.fortress.host.cloak_grade') }}</span>
                    <select class="input" name="grade">
                        @foreach (range(1, 9) as $g)
                            <option value="{{ $g }}" @selected((int) $fortress->cloak_grade === $g)>{{ \App\Cyberspace\Grade::roman($g) }}</option>
                        @endforeach
                    </select></label>
                <label class="field"><span class="field__label">{{ Text::get('cyberspace.fortress.host.cloak_type') }}</span>
                    <select class="input" name="type">
                        @foreach (\App\Cyberspace\FortressCloak::TYPES as $t)
                            <option value="{{ $t }}" @selected($fortress->cloak_type === $t)>{{ \App\Cyberspace\SystemType::from($t)->label() }}</option>
                        @endforeach
                    </select></label>
                <label class="field"><span class="field__label">{{ Text::get('cyberspace.fortress.host.cloak_owner') }}</span>
                    <select class="input" name="entity">
                        <option value="">—</option>
                        @foreach ($entities as $e)
                            <option value="{{ $e->id }}" @selected((int) $fortress->cloak_owner_id === $e->id)>{{ $e->name }}</option>
                        @endforeach
                    </select></label>
                <button class="btn btn--sm cut" type="submit">{{ Text::get('cyberspace.fortress.host.save') }}</button>
            </form>
        @endif
        @foreach ($members as $m)
            <div class="row row--wrap" style="justify-content:space-between;align-items:center">
                <span>{{ $m->handle }} <span class="tag">{{ Text::get("cyberspace.fortress.host.role_{$m->role}") }}</span></span>
                <form method="POST" action="{{ route('fortress.revoke', [$fortress, $m->id]) }}">@csrf
                    <button class="btn btn--sm btn--ghost cut" type="submit">{{ Text::get('cyberspace.fortress.host.revoke') }}</button></form>
            </div>
        @endforeach
        <form method="POST" action="{{ route('fortress.invite', $fortress) }}" class="row row--wrap" style="--gap:var(--s-2);align-items:flex-end">
            @csrf
            <label class="field"><span class="field__label">{{ Text::get('cyberspace.fortress.host.handle') }}</span>
                <input class="input" name="handle" maxlength="40" required></label>
            <select class="input" name="role">
                @foreach (['member', 'administrator'] as $r)
                    <option value="{{ $r }}">{{ Text::get("cyberspace.fortress.host.role_{$r}") }}</option>
                @endforeach
            </select>
            <button class="btn btn--sm cut" type="submit">{{ Text::get('cyberspace.fortress.host.invite') }}</button>
        </form>
    </div>
</div>
@endif

<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd"><span class="micro">{{ Text::get('cyberspace.fortress.host.installed') }}</span></div>
    <div class="panel__bd stack" style="--gap:var(--s-2)">
        @forelse ($hosted as $row)
            <div class="row row--wrap" style="justify-content:space-between;align-items:center;gap:var(--s-2)">
                <span>{{ $row->item->displayName() }}
                    <span class="tag">{{ $row->item->program->kind->label() }}</span>
                    @if ($row->fortress_running)<span class="tag">{{ Text::get('cyberspace.fortress.host.running') }}</span>@endif
                    @if ($row->defence !== null)
                        <span class="micro num" style="color:var(--ink-3)">{{ collect(\App\Cyberspace\FortressDefence::effective($row))->map(fn ($v, $k) => "{$k} {$v}")->implode(' · ') }}</span>
                    @endif
                </span>
                <span class="row" style="--gap:var(--s-2)">
                    <form method="POST" action="{{ route('fortress.'.($row->fortress_running ? 'stop' : 'start'), [$fortress, $row]) }}">@csrf
                        <button class="btn btn--sm cut" type="submit">{{ Text::get($row->fortress_running ? 'cyberspace.fortress.host.stop' : 'cyberspace.fortress.host.start') }}</button></form>
                    @if ($row->defence !== null)
                        <form method="POST" action="{{ route('fortress.maintain', [$fortress, $row]) }}">@csrf
                            <button class="btn btn--sm btn--ghost cut" type="submit">{{ Text::get('cyberspace.fortress.host.maintain') }}</button></form>
                    @endif
                    <form method="POST" action="{{ route('fortress.uninstall', [$fortress, $row]) }}">@csrf
                        <button class="btn btn--sm btn--ghost cut" type="submit">{{ Text::get('cyberspace.fortress.host.uninstall') }}</button></form>
                </span>
            </div>
        @empty
            <p class="muted" style="margin:0">{{ Text::get('cyberspace.fortress.host.nothing') }}</p>
        @endforelse
    </div>
</div>

<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd"><span class="micro">{{ Text::get('cyberspace.fortress.host.vault') }}</span></div>
    <div class="panel__bd stack" style="--gap:var(--s-2)">
        @forelse ($vault as $file)
            <div class="row row--wrap" style="justify-content:space-between;align-items:center">
                <span>{{ $file->label ?? ($file->item?->displayName().' ×'.$file->quantity) }} @if ($file->size_qb !== null)<span class="micro num">{{ Qb::format((float) $file->size_qb) }} QB</span>@endif</span>
                @if ($file->label !== null && (int) $file->area_id === (int) $fortress->market_area_id && $fortress->market_area_id !== null && ($role === 'owner' || (int) $file->deposited_by === $character->id))
                    <form method="POST" action="{{ route('fortress.market.list', [$fortress, $file]) }}" class="row" style="--gap:var(--s-1)">@csrf
                        <input class="input num" type="number" name="price" min="1" required style="width:6rem" placeholder="{{ Text::get('cyberspace.fortress.host.price') }}" value="{{ $listings[$file->id]->price ?? '' }}">
                        <button class="btn btn--sm btn--ghost cut" type="submit">{{ Text::get('cyberspace.fortress.host.list') }}</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('fortress.withdraw', [$fortress, $file]) }}">@csrf
                    <button class="btn btn--sm btn--ghost cut" type="submit">{{ Text::get('cyberspace.fortress.host.withdraw') }}</button></form>
            </div>
        @empty
            <p class="muted" style="margin:0">{{ Text::get('cyberspace.fortress.host.vault_empty') }}</p>
        @endforelse
        @foreach ($files as $row)
            <div class="row row--wrap" style="justify-content:space-between;align-items:center">
                <span>{{ $row->label() }}</span>
                <form method="POST" action="{{ route('fortress.deposit', [$fortress, $row]) }}" class="row" style="--gap:var(--s-2)">@csrf
                    <select class="input" name="area">
                        @if ($role === 'owner')<option value="">{{ Text::get('cyberspace.fortress.host.owner_storage') }}</option>@endif
                        @foreach ($areas as $area)
                            @if (\App\Cyberspace\FortressAccess::areaCan($character, $fortress, $area, 'deposit'))
                                <option value="{{ $area->id }}">{{ $area->name }}</option>
                            @endif
                        @endforeach
                    </select>
                    <button class="btn btn--sm cut" type="submit">{{ Text::get('cyberspace.fortress.host.deposit') }}</button></form>
            </div>
        @endforeach
    </div>
</div>

@if ($stash->isNotEmpty())
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd"><span class="micro">{{ Text::get('cyberspace.fortress.host.from_stash') }}</span></div>
    <div class="panel__bd stack" style="--gap:var(--s-2)">
        @foreach ($stash as $row)
            <div class="row row--wrap" style="justify-content:space-between;align-items:center">
                <span>{{ $row->item->displayName() }} <span class="tag">{{ $row->item->program->kind->label() }}</span></span>
                <form method="POST" action="{{ route('fortress.install', [$fortress, $row]) }}">@csrf
                    <button class="btn btn--sm cut" type="submit">{{ Text::get('cyberspace.fortress.host.install') }}</button></form>
            </div>
        @endforeach
    </div>
</div>
@endif
@endsection
