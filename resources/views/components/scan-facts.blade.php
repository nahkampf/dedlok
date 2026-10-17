@use('App\Support\Text')
@props(['facts'])

{{-- What a scan found, as the scanner window shows it: a label on the left and what the system gave
     up on the right. SystemScan::facts() builds the rows; ui-kit.js paints the same thing into the
     window while a read is running, so the two must keep the same look.

     An answer is ember and says what it found. Both blanks wear the same plain box — "nobody can read
     this yet" and "you have not read it yet" are different facts and neither is an answer. The mark on
     the operating system is the one place a fact also says whether the reader can DO anything with it. --}}
<div class="card__stats" style="padding:0;border:0">
    @foreach ($facts as $fact)
        @php($known = $fact['value'] !== null)
        <div class="card__stat" data-fact="{{ $fact['key'] }}">
            <span>{{ $fact['label'] }}</span>
            @if ($fact['discoverable'])
                <span class="tag @if ($known) tag--ember @endif">
                    @if ($known && ($fact['mark'] ?? null))
                        <span class="{{ $fact['mark'] === 'ok' ? 'mark--ok' : 'mark--no' }}" title="{{ Text::get($fact['mark'] === 'ok' ? 'cyberspace.reading.os_known' : 'cyberspace.reading.os_unknown') }}">{{ $fact['mark'] === 'ok' ? "\u{2713}" : "\u{2717}" }}</span>
                    @endif
                    {{ $known ? $fact['value'] : Text::get('cyberspace.reading.unread') }}
                </span>
            @elseif ($known)
                <span class="tag tag--ember">{{ $fact['value'] }}</span>
            @else
                <span class="tag">{{ Text::get('cyberspace.reading.not_yet') }}</span>
            @endif
        </div>
    @endforeach
</div>
