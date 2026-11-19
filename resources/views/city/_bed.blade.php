{{-- A looping bed for a district or place: public/assets/sound/{districts|locations}/<slug>.mp3, the slug with - as _ and
     without a leading "the_". No file, no sound. ui-kit.js plays it, behind the sound toggle. --}}
@php
    $key = preg_replace('/^the_/', '', str_replace('-', '_', $slug));
    $bed = file_exists(public_path("assets/sound/{$kind}/{$key}.mp3")) ? "assets/sound/{$kind}/{$key}.mp3" : null;
@endphp
@if ($bed)
    <span hidden data-bed-sound="{{ \App\Support\Asset::url($bed) }}"></span>
@endif
