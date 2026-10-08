{{-- One grid of achievement cards, used by both halves of the page: what has
     been earned and what has not. Identical on purpose — the header above it
     is what says which list this is, and a locked achievement that looked
     different would be a second way of saying the same thing. --}}
@props(['rows'])

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(160px,100%),1fr))">
    @foreach ($rows as $row)
        <div class="card">
            {{-- Found by convention, like every other picture in the game:
                 public/images/achievements/<id>.jpg, _default.jpg when a
                 particular one has not been drawn yet, and nothing at all
                 when even that is missing. --}}
            <x-entity-image kind="achievements" :id="$row['id']" :alt="$row['name']" square />

            {{-- The description is on the LOCKED cards too, which is what
                 makes the second list worth having: it is the only place a
                 player finds out what an achievement would take. --}}
            <div class="card__bd stack" style="text-align:center;--gap:var(--s-2)">
                <span>{{ $row['name'] }}</span>
                <span class="micro" style="color:var(--ink-3)">{{ $row['description'] }}</span>
                <span class="micro num" style="color:var(--ink-2)">{{ $row['share'] }}</span>
            </div>
        </div>
    @endforeach
</div>
