<?php

declare(strict_types=1);

return [
    'create' => [
        'title' => 'Create your character',
        'lede' => 'This is who you are on the street. The handle is permanent; spend your points carefully.',
        'submit' => 'Jack in',
    ],
    'handle' => [
        'section' => 'Handle',
        'label' => 'Handle',
        'hint' => 'Up to :max characters. This is the name the city knows you by.',
        'required' => 'Choose a handle.',
    ],
    'archetype' => [
        'section' => 'Archetype',
        'help' => 'Pick one. It sets your starting role in the city.',
        'required' => 'Choose an archetype.',
    ],
    'attributes' => [
        'physical' => 'Physical attributes',
        'mental' => 'Mental attributes',
        'pool' => 'Points remaining',
        'spent' => ':spent of :total spent',
        'balanced' => 'Ready to jack in',
        'remaining' => ':count still to spend',
        'overspent' => ':count over budget',
        'must_balance' => 'Spend exactly :total points before you can continue.',
        'reset' => 'Reset spread',
        'reset_hint' => 'Puts every attribute back to :each.',
        'pool_impossible' => 'This character cannot be built: :points points does not fit between :min and :max across all attributes. Adjust new_character_attribute_points.',
        'start' => 'Starting spread: :each per attribute.',
    ],
    'info' => [
        'title' => 'Attributes',
        'lede' => 'Ten attributes, split between what your body can do and what your mind can do. Each runs from :min to :max at creation.',
        'close' => 'Close',
        'open' => 'What does :attribute do?',
    ],

    'derived_names' => [
        'hitpoints_max' => 'Max Hitpoints',
        'synaptic_integrity_max' => 'Synaptic Integrity',
    ],

    'descriptions' => [
        // Physical
        'meat' => 'Raw bodily resilience. How much punishment the flesh absorbs before it stops doing what you tell it.',
        'motor' => 'Fine control and coordination. Steady hands on a lockpick, a scalpel, or a trigger, while everything around you is going wrong.',
        'senses' => 'Acuity of sight, hearing and whatever you have had installed since. Sets how much signal reaches you before you have to guess.',
        'muscle' => 'Applied force. Lifting, breaking, dragging a body, holding a door shut against the people on the other side.',

        // Mental
        'intellect' => 'Analysis and problem solving. Reading the shape of a system fast enough to act on it while it is still true.',
        'memory' => 'Recall and retention. How much you carry without a deck, and how much of it survives a bad night.',
        'perception' => 'Noticing what other people miss. The tell, the tail, the one detail in the room that does not belong.',
        'reflex' => 'The gap between something happening and you responding. Governs who moves first, which is usually who walks away.',
        'focus' => 'Sustained attention under load. Holding a thread while the room, the feed and your own chrome all compete for it.',
        'social' => 'Reading people and steering them. Leverage that does not require breaking anything.',
    ],

    'derived' => [
        'section' => 'Derived values',
        'help' => 'Calculated from your attributes. They update as you move the sliders.',
    ],

    'abbreviations' => [
        'hitpoints_max' => 'HP',
        'synaptic_integrity_max' => 'SI',
    ],

    'names' => [
        // Derived stats live here too, so a modifier can name what it affects.
        'hitpoints_max' => 'max HP',
        'synaptic_integrity_max' => 'max SI',
        'intellect' => 'Intellect',
        'memory' => 'Memory',
        'perception' => 'Perception',
        'reflex' => 'Reflex',
        'focus' => 'Focus',
        'social' => 'Social',
        'meat' => 'Meat',
        'motor' => 'Motor',
        'senses' => 'Senses',
        'muscle' => 'Muscle',
    ],
];
