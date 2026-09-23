@use('App\Standing\Band')
@use('App\Support\Text')
@props([
    // The entity whose opinion this is. Null, or scenery, renders nothing:
    // most of the grid belongs to companies nobody can have a relationship
    // with, and "Neutral with Soylent Nutritional" is a fact about nothing.
    'entity' => null,
    'standing' => 0.0,
])

@if ($entity?->interactable)
    @php($band = Band::for((float) $standing))

    {{-- THE BAND CARRIES THE MEANING AND THE FIGURE SITS BEHIND IT. A card
         says who answers for this system and what they make of you; -12.480
         is a number nobody can act on, and the entity's own screen is where
         it belongs. --}}
    <span class="tag" style="color:{{ $band->inkToken() }}"
          title="{{ Text::get('entities.standing') }} {{ number_format((float) $standing, 2) }}">
        {{ Text::get('entities.standing_with', ['band' => $band->label(), 'entity' => $entity->name]) }}
    </span>
@endif
