@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.supporters.history'))

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li><a href="{{ route('admin.supporters.index') }}">{{ Text::get('admin.supporters.title') }}</a></li>
        <li aria-current="page">{{ Text::get('admin.supporters.history') }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ Text::get('admin.supporters.history') }}</h1>
    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">{{ Text::get('admin.supporters.history_lede') }}</p>

    <div class="panel" style="margin-top:var(--s-4)">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ Text::get('admin.supporters.account') }}</th>
                        <th>{{ Text::get('admin.supporters.status') }}</th>
                        <th>{{ Text::get('admin.supporters.tiers') }}</th>
                        <th>{{ Text::get('admin.supporters.paid') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        @php
                            $rows = $payments->get($user->id, collect());
                            $totals = $rows->whereNotNull('amount')->groupBy(fn ($r) => $r->currency ?? '')
                                ->map(fn ($g, $cur) => number_format((float) $g->sum('amount'), 2).' '.$cur);
                        @endphp
                        <tr>
                            <td>
                                {{ $user->email }}
                                <span class="micro" style="color:var(--ink-3)">{{ $user->character?->handle }}</span>
                            </td>
                            <td>
                                @if ($user->isSupporter())
                                    <span class="tag tag--ember">{{ Text::get('admin.supporters.now') }}</span>
                                    <span class="micro" style="color:var(--ink-3)">{{ $user->supporter_until ? '→ '.$user->supporter_until->format('Y-m-d') : Text::get('admin.supporters.for_good') }}</span>
                                @else
                                    <span class="tag">{{ Text::get('admin.supporters.lapsed') }}</span>
                                @endif
                            </td>
                            <td>{{ $rows->pluck('tier_name')->filter()->unique()->implode(', ') }}</td>
                            <td>{{ $totals->implode(' + ') ?: '—' }}</td>
                        </tr>
                        <tr>
                            <td colspan="4" style="padding-top:0">
                                @if ($rows->isEmpty())
                                    <span class="micro" style="color:var(--ink-3)">{{ Text::get('admin.supporters.no_payments') }}</span>
                                @else
                                    <details>
                                        <summary class="micro">{{ $rows->count() }} {{ Text::get('admin.supporters.payments') }}</summary>
                                        <ul class="micro" style="margin-top:var(--s-2)">
                                            @foreach ($rows as $r)
                                                <li>
                                                    {{ \Illuminate\Support\Carbon::parse($r->received_at)->format('Y-m-d H:i') }}
                                                    · {{ $r->tier_name ?? '—' }}
                                                    · {{ $r->amount !== null ? number_format((float) $r->amount, 2).' '.$r->currency : '—' }}
                                                    @if ($r->is_first) · {{ Text::get('admin.supporters.first_payment') }} @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">{{ Text::get('admin.supporters.history_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
