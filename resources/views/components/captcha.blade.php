{{-- Cap's mount point.

     Deliberately a bare class: public/assets/js/ui-kit.js upgrades every
     .captcha into Cap's <cap-widget> custom element, reading the endpoint and
     the labels from the two globals <x-captcha-runtime /> emits. A new
     protected form needs this one tag and nothing else — no per-form script,
     no endpoint repeated in markup, no hidden input (the widget injects its
     own once it is inside a <form>).

     Renders nothing when Cap is switched off, so the auth screens can carry it
     unconditionally and a fresh clone with no Cap server still shows a working
     login form. --}}
@if (config('cap.enabled'))
    <div class="field">
        <span class="field__label">{{ __('captcha.label') }}</span>
        <div class="captcha"></div>
    </div>
@endif
