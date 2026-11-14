@use('App\Support\Text')
{{-- The Nodes modal's body (#nodes-view, filled through [data-listing]): what a scan read the tree to hold, by kind. A
     trap reads as a conduit and a honeypot as a vault here, as everywhere a scan's Nodes are shown. --}}
@if ($counts === null)
    <p class="muted" style="margin:0">{{ Text::get('cyberspace.tree.gone') }}</p>
@else
    <div class="card__stats" style="padding:0;border:0">
        @foreach ($counts as $label => $count)
            <div class="card__stat"><span>{{ $label }}</span><b class="num">{{ $count }}</b></div>
        @endforeach
    </div>
@endif
