@use('App\Support\Text')
@use('App\Cyberspace\Party\ShareMode')
<div class="row row--wrap" style="--gap:var(--s-3)">
    @foreach (['share_credits' => $credits, 'share_extraction' => $extraction] as $name => $current)
        <label class="field" style="min-width:min(14rem,100%)">
            <span class="field__label">{{ Text::get("cyberspace.party.{$name}") }}</span>
            <select class="input" name="{{ $name }}">
                @foreach (ShareMode::cases() as $mode)
                    <option value="{{ $mode->value }}" @selected($current === $mode)>{{ $mode->label() }}</option>
                @endforeach
            </select>
        </label>
    @endforeach
</div>
