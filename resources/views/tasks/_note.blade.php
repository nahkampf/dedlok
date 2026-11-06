{{-- One journal note: the heading is the fold, its lines are inside. Closed by default. --}}
<details>
    <summary>{{ $note->title }}</summary>
    <div>
        @if ($note->entries->isNotEmpty())
            <ul class="stack" style="--gap:var(--s-1);margin:0;padding-left:var(--s-5)">
                @foreach ($note->entries as $entry)
                    <li @class(['muted' => $entry->completed])>
                        @if ($entry->completed)
                            <s>{{ $entry->body }}</s>
                        @else
                            {{ $entry->body }}
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</details>
