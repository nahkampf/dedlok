@use('App\Support\Text')
@props(['character' => null])

{{-- A supporter's badge, in ember, beside the name of their character. Nothing for anybody else. Asked of the CHARACTER because that is
     what is on screen; the flag is the account's (`User::isSupporter()`), so a screen listing several characters should eager-load `user`. --}}
@if ($character?->user?->isSupporter())
    <span class="tag tag--ember" title="{{ Text::get('supporters.badge_hint') }}" {{ $attributes }}>{{ Text::get('supporters.badge') }}</span>
@endif
