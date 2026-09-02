@use('App\Support\Text')
{{-- Everything the .captcha class needs to work, emitted once per page.

     The inline block runs during parsing, so the globals exist before the
     deferred ui-kit.js looks for them. The widget script's own order does not
     matter: a <cap-widget> created before the definition loads is upgraded
     when it arrives.

     Both files are served from public/assets rather than from a CDN. Cap
     Standalone does not serve the widget itself, and jsDelivr would put a
     third party back in the render path that self-hosting Cap exists to
     remove — CAP_CUSTOM_WASM_URL is what stops the widget reaching for it. --}}
@php
    $endpoint = \App\Captcha\Cap::endpoint();
@endphp

@if (config('cap.enabled') && $endpoint)
    <script>
        window.CAP_ENDPOINT = @js($endpoint);
        window.CAP_I18N = @js(Text::group('captcha.widget'));
        window.CAP_CUSTOM_WASM_URL = @js(\App\Captcha\Cap::wasmUrl());
    </script>
    <script src="{{ \App\Captcha\Cap::widgetScript() }}" defer></script>
@endif
