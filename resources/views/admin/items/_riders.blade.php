{{-- The riders of a weapon or a round: one row per kind, because a list holds at most one of each. A
     row with no chance is no rider. Nothing reads these yet — they are authored ahead of the fight engine. --}}
@use('App\Combat\RiderKind')
@use('App\Support\Text')
@php($current = collect($current ?? [])->keyBy('kind'))
<div class="field" style="margin-top:var(--s-3)" @error($name) data-invalid @enderror>
    <span class="field__label">{{ Text::get('admin.items.fields.riders') }}</span>
    <div class="stack" style="--gap:var(--s-2)">
        @foreach (RiderKind::authorableKinds() as $kind)
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(110px,100%),1fr));align-items:end">
                <span class="field__label" style="margin:0">{{ $kind->label() }}</span>
                @foreach (['chance' => 1, 'turns' => 1] + ($kind->damaging() ? ['amount' => 1] : []) as $key => $min)
                    <label class="field" style="margin:0"><span class="field__label">{{ Text::get("admin.items.fields.rider_{$key}") }}</span>
                        <input class="input num" type="number" min="0" step="1" name="{{ $name }}[{{ $kind->value }}][{{ $key }}]"
                               value="{{ old("{$name}.{$kind->value}.{$key}", $current[$kind->value][$key] ?? '') }}"></label>
                @endforeach
            </div>
        @endforeach
    </div>
    <span class="field__hint">{{ Text::get($hint) }}</span>
    @error($name) <span class="field__error">{{ $message }}</span> @enderror
</div>
