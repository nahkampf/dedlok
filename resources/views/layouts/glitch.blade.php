@use('App\Support\Text')
{{-- THE PAGE GLITCH's two halves (UI.glitch in ui-kit.js; docs/frontend.md). The filter tears the page in hard horizontal slices and
     lays an ember ghost of everything bright beside it. It is set on the page root only while an effect runs. The ember's channels are
     below as numbers because an SVG filter cannot read a CSS token: 1, .353, .212 is --ember (#FF5A36). The overlay is the edge flash,
     inverted bands and the black cut. --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <filter id="glitch-tear" x="0" y="0" width="100%" height="100%" color-interpolation-filters="sRGB">
        <feTurbulence id="glitch-noise" type="fractalNoise" baseFrequency="0 0.011" numOctaves="1" seed="1" result="n"/>
        <feComponentTransfer in="n" result="slices">
            <feFuncR type="discrete" tableValues=".5 .5 .5 .5 .95 .5 .5 .5 .05 .5 .5 .5 .5 .85 .5 .5"/>
        </feComponentTransfer>
        <feColorMatrix in="slices" type="matrix" values="1 0 0 0 0  0 0 0 0 .5  0 0 0 0 .5  0 0 0 0 1" result="map"/>
        <feDisplacementMap id="glitch-disp" in="SourceGraphic" in2="map" scale="0" xChannelSelector="R" yChannelSelector="G" result="torn"/>
        <feColorMatrix in="torn" type="matrix" values="0 0 0 0 1  0 0 0 0 .353  0 0 0 0 .212  1.6 1.6 1.6 0 -.55" result="emberMask"/>
        <feOffset id="glitch-ghost" in="emberMask" dx="0" dy="0" result="ghost"/>
        <feBlend in="ghost" in2="torn" mode="screen"/>
    </filter>
</svg>
<div class="glitch" data-glitch-layer aria-hidden="true">
    <div class="glitch__hit"></div>
    <div class="glitch__band"></div><div class="glitch__band"></div><div class="glitch__band"></div>
    <div class="glitch__black"></div>
    <div class="glitch__read">{{ Text::get('ui.glitch.lost') }}</div>
</div>
