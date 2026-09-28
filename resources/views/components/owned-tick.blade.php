@use('App\Support\Text')
@props(['item', 'owned' => [], 'injected' => []])
{{-- The kit's corner tick on a listing: ONE YOU HAVE, and nothing else. An imprint
     whose skill is already injected says so first, in cyan — that is the answer
     a shopper needs about an imprint, and owning a spare copy of one is the lesser
     fact. Anything else they hold a row of, anywhere, is the default --ok.

     Renders nothing for a thing they do not have. The parent carries .ticked. --}}
@if ($item->use_skill_id !== null && isset($injected[$item->use_skill_id]))
    <span class="tick" style="--tick:var(--cyan)" title="{{ Text::get('market.tick.injected') }}"></span>
@elseif (isset($owned[$item->id]))
    <span class="tick" title="{{ Text::get('market.tick.owned') }}"></span>
@endif
