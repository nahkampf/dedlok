@if ($errors->any())
    <div class="callout callout--crit" style="margin-bottom:var(--s-4)">
        <div class="stack" style="--gap:4px">
            @foreach ($errors->all() as $error)
                <span>{{ $error }}</span>
            @endforeach
        </div>
    </div>
@endif
