@use('App\Support\Asset')
@use('App\Support\Text')
{{-- What a link unfurls as (Facebook, Twitter/X, Discord, Slack): the page's own title plus the tagline, and the square
     picture. Absolute URLs, because a crawler has no page to resolve a relative one against. --}}
@php($unfurlTitle = trim($__env->yieldContent('title', config('app.name'))).' - '.Text::get('unfurl.tagline'))
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $unfurlTitle }}">
<meta property="og:description" content="{{ Text::get('unfurl.description') }}">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:image" content="{{ Asset::url('images/square.jpg') }}">
<meta property="og:image:width" content="640">
<meta property="og:image:height" content="640">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $unfurlTitle }}">
<meta name="twitter:image" content="{{ Asset::url('images/square.jpg') }}">
