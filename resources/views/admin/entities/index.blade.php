@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.entities.title'))

@section('content')
    <h1>{{ Text::get('admin.entities.title') }}</h1>

    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">
        {{ Text::get('admin.entities.lede') }}
    </p>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('admin.entities.title') }}</span>
            <span class="tag">{{ $entities->count() }}</span>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ Text::get('admin.entities.name') }}</th>
                        <th>{{ Text::get('admin.entities.category') }}</th>
                        <th>{{ Text::get('admin.entities.interactable') }}</th>
                        <th class="t-right">{{ Text::get('admin.entities.offers') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    {{-- EVERY entity, not only the interactable ones. A shelf
                         on flavour scenery is a mistake worth being able to
                         see rather than one this screen hides, and the tag is
                         what says which are real. --}}
                    @foreach ($entities as $entity)
                        <tr>
                            <td>
                                <a href="{{ route('admin.entities.edit', $entity) }}">{{ $entity->name }}</a>
                            </td>
                            <td class="muted">{{ $entity->category->label() }}</td>
                            <td>
                                @if ($entity->interactable)
                                    <span class="tag tag--ok">{{ Text::get('admin.entities.interactable') }}</span>
                                @else
                                    <span class="tag">{{ Text::get('admin.entities.scenery') }}</span>
                                @endif
                            </td>
                            <td class="t-right num">{{ $entity->offers_count }}</td>
                            <td class="t-right">
                                <a class="btn btn--sm btn--ghost cut" href="{{ route('admin.entities.edit', $entity) }}">
                                    {{ Text::get('admin.entities.edit') }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
