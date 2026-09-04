<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Player-visible strings
|--------------------------------------------------------------------------
|
| The game speaks English and only English. This file replaced lang/en/*.php
| when translation was removed: there is one locale, so there is no locale to
| resolve, negotiate or switch, and nothing here is keyed by language.
|
| What belongs here is anything a player reads that is NOT attached to a
| database row. Anything that IS — an item's name, a skill's title — lives on
| the row instead, because the row already exists and a second home for half of
| its content is a second thing to keep in step.
|
| Read it with App\Support\Text, which substitutes :placeholders. Keys still
| name the ROLE of a string rather than its text, so rewording copy stays a
| one-line change here.
|
*/

return [

    'ui' => [
        'nav' => [
            'run' => 'Run',
            'stash' => 'Stash',
            'deck' => 'Deck',
            'skills' => 'Skills',
            'contracts' => 'Contracts',
            'profile' => 'Profile',

            // The rail itself: what it is called to a screen reader, and the
            // control that opens it where there is no pointer to hover with.
            'sections' => 'Sections',
            'menu' => 'Menu',
        ],

        // The bar that is always on screen. Two resources and the money, and
        // nothing that is not a number you need at a glance.
        'hud' => [
            'ratio' => ':current / :max',
            // The same pair in words, for anything that cannot see the bar.
            'aria' => ':label :current of :max',
        ],
        'action' => [
            'jack_in' => 'Jack in',
            'jack_out' => 'Jack out',
            'disconnect' => 'Disconnect',
            'cancel' => 'Cancel',
            'save' => 'Save',
        ],
        'status' => [
            'online' => 'Online',
            'nominal' => 'Nominal',
            'degrading' => 'Degrading',
            'traced' => 'Traced',
        ],
        'vitals' => [
            'health' => 'Health',
            'stamina' => 'Stamina',
            'heat' => 'Heat',
            'ram' => 'RAM',
        ],
    ],

    'auth' => [
        'login' => 'Jack in',
        'register' => 'New identity',
        'handle' => 'Handle',
        'handle_hint' => 'Up to :max characters.',
        'handle_taken' => 'That handle is already claimed.',
        'suggest_handle' => 'Suggest a handle',
        'suggest_handle_empty' => 'Every suggested handle is taken. Pick your own.',
        'suggest_handle_failed' => 'Could not reach the handle registry. Try again.',
        'email' => 'Email',
        'password' => 'Password',
        'confirm_password' => 'Confirm password',
        'remember' => 'Stay jacked in',
        'forgot' => 'Lost your key?',
        'start_playing' => 'New account',
        'have_account' => 'Already have an identity?',
        'logout' => 'Jack out',
    ],

    'captcha' => [

        'label' => 'Human check',

        // Shown by us, not by the widget, when a submission is refused.
        'failed' => 'That check did not come back clean. Try again.',
        'unavailable' => 'The verification service is unreachable. Try again in a moment.',

        'widget' => [
            'initial-state' => "Confirm you're human",
            'verifying-label' => 'Checking…',
            'solved-label' => 'Verified',
            'error-label' => 'Failed',
            'troubleshooting-label' => 'Troubleshooting',
            'wasm-disabled' => 'Enable WebAssembly for a faster check',
            'verify-aria-label' => 'Select to confirm you are human',
            'verifying-aria-label' => 'Checking, please wait',
            'verified-aria-label' => 'Verified',
            'required-label' => "Confirm you're human before continuing",
            'error-aria-label' => 'The check failed, please try again',
        ],
    ],

    'character' => [
        'create' => [
            'title' => 'Create your character',
            'lede' => 'This is who you are on the street. The handle is permanent; spend your points carefully.',
            'submit' => 'Jack in',
            // One character per user, so coming back to this screen with one
            // already made is a dead end rather than a second chance.
            'already' => 'You already have a character. There is only one of you.',
            // The admin's read-only view of the same screen. They maintain
            // what is on it — the archetype cards, the loadouts, the pool —
            // and looking at it is the only way to see that work.
            'preview' => 'Preview. You already have a character, so this screen cannot be submitted — it is here so you can see what a new player sees.',
            'preview_hint' => 'Read-only preview.',
        ],
        'handle' => [
            'section' => 'Handle',
            'label' => 'Handle',
            'hint' => 'Up to :max characters. This is the name the city knows you by.',
            'required' => 'Choose a handle.',
        ],
        'archetype' => [
            'section' => 'Archetype',
            'help' => 'Pick one. It sets your starting role in the city, and what you walk out with. The greyed-out roles are written but not built yet.',
            'required' => 'Choose an archetype.',
            // On a card nobody can select. The role still reads, because a
            // roster that hides what is coming looks shorter than it is.
            'unavailable' => 'Not yet',
        ],

        /*
        | What an archetype hands a character at creation. The heading is on
        | the card's second stat block; the perks above it are what the
        | archetype CHANGES, these are what it GIVES.
        */
        'loadout' => [
            'section' => 'You start with',
            'creds' => 'Creds',
            // The label on the segmented meter, which is what the card shows
            // instead of a number — read out by a screen reader and shown on
            // hover, so it carries the number the segments imply.
            'level' => 'Level :level of :max',
            // Level 0 is a real state: the skill is on the sheet, untrained.
            // An empty meter says it; this says what the empty meter means.
            'injected' => 'Injected, untrained',
            'quantity' => '×:count',
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
            'reflex' => 'The gap between something happening and you responding. Governs who moves first, which is usually who walks away.',
            'meat' => 'Raw bodily resilience. How much punishment the flesh absorbs before it stops doing what you tell it.',
            'motor' => 'Fine control and coordination. Steady hands on a lockpick, a scalpel, or a trigger, while everything around you is going wrong.',
            'senses' => 'Acuity of sight, hearing and whatever you have had installed since. Sets how much signal reaches you before you have to guess.',
            'muscle' => 'Applied force. Lifting, breaking, dragging a body, holding a door shut against the people on the other side.',

            // Mental
            'intellect' => 'Analysis and problem solving. Reading the shape of a system fast enough to act on it while it is still true.',
            'memory' => 'Recall and retention. How much you carry without a deck, and how much of it survives a bad night.',
            'perception' => 'Noticing what other people miss. The tell, the tail, the one detail in the room that does not belong.',
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
    ],

    /*
    | Not currently rendered anywhere — the landing page's demo of these went
    | with the translation layer. Kept because it is real game copy and the
    | combat log will want it.
    */
    /*
    | Money. One number on the character row, and no transaction anywhere yet:
    | the market has a supply side and no storefront, so the only thing that
    | writes it is the archetype grant at creation.
    */
    'creds' => [
        'name' => 'Creds',
        'amount' => ':amount cr',
        'hint' => 'What you have to spend. Nothing sells anything yet.',
    ],

    'combat' => [
        'hits' => ['zero' => 'No hits', 'one' => ':count hit', 'many' => ':count hits'],
        'damage' => ':count damage',
        'enemies' => ['zero' => 'The street is empty', 'one' => 'One hostile', 'many' => ':count hostiles'],
        'log' => ':attacker hits :target for :damage.',
    ],

    'skills' => [

        'title' => 'Skill tree',
        'lede' => 'Every skill in the game, grouped by category. Each one names what it needs first.',

        // The multiplication sign, matching inventory.quantity: a rank is a
        // training-time multiplier, not an ordinal.
        'rank' => '×:rank',
        'governed_by' => ':primary / :secondary',
        // Every requirement reads the same way, whether the tree could draw
        // the edge or not.
        'requires' => ':skill :level',

        // A skill inside a prerequisite cycle: nothing can ever learn it, and
        // skills:validate is what explains why.
        'unreachable' => 'unreachable',
        'unreachable_hint' => 'Inside a prerequisite cycle — run skills:validate.',

        // A prerequisite in another category cannot be a parent without the
        // skill hanging in two lanes at once, so it is named instead.
        'from_elsewhere' => ':skill :level · :category',

        'empty' => 'No skills in the database yet.',
        'count' => ['zero' => 'empty', 'one' => ':count skill', 'many' => ':count skills'],
        'deepest' => 'depth :levels',

        // The overlay a signed-in player sees on top of the catalogue.
        'injected' => 'Skill injected',
        'level_meter' => 'Level :level of :max · :points SP',
    ],

    'errors' => [
        'throttle' => [
            'title' => 'Slow down',
            'body' => 'Too many requests. Try again in :seconds seconds.',
        ],
    ],

    'inventory' => [

        'title' => 'Stash',

        // The two containers. Named for what they are, not for the column value:
        // a player has never heard the word "container".
        'physical' => 'Carried',
        'physical_hint' => 'What is on you. Gear, chrome, salvage.',
        'data' => 'Deck',
        'data_hint' => 'What is on your deck. Software, files, skillbooks.',

        'empty' => 'Nothing here.',

        // The equipped deck is not in either inventory — it is on you. This is
        // the one place it appears, and the only way through to its board.
        'in_use' => 'In use',

        /*
        | What you can do to an item, on the card you already have open.
        |
        | These were a right-click menu once. A right-click has no mobile
        | story, nothing on screen says it exists, and an extension can eat the
        | event before the page ever sees it — three ways for a feature to be
        | invisible. The card was already the place you look at a thing.
        |
        | The ones that are not built say so with a tag rather than being
        | absent: a card that grows buttons as features land is a card nobody
        | learns, which is the promise the archetype roster already makes.
        */
        'actions' => [
            'details' => 'Details',
            'market' => 'Show on market',
            'fit' => 'Fit to deck',
            'unfit' => 'Take out of deck',
            'disassemble' => 'Disassemble',
            'destroy' => 'Destroy…',
            'not_yet' => 'Not yet',
        ],

        // Destroying something. Real, irreversible, and confirmed once.
        'destroy' => [
            'title' => 'Destroy :item',
            'warning' => 'This cannot be undone. :item is gone for good.',
            'contents' => 'Everything on it goes too — :count of them.',
            'fitted' => 'The modules in it come out first and stay in your stash.',
            'equipped' => 'It is the deck you have equipped. Destroying it leaves you with none.',
            'confirm' => 'Destroy it',
            'cancel' => 'Keep it',
        ],

        'destroyed' => ':item is gone.',

        // Counted forms, picked by Text::plural(). English has two; that is now
        // the only case there is.
        'count' => ['zero' => 'empty', 'one' => ':count item', 'many' => ':count items'],
        'quantity' => '×:count',

        'inject' => 'Inject',
        'inject_confirm' => 'Injecting :skill destroys the book. It cannot be undone.',
        // The same sentence with no skill named, for a list of books rather
        // than one: the contents of a card say it once, not once each.
        'inject_confirm_any' => 'Injecting a book destroys it. It cannot be undone.',
        'injected' => ':skill is on your sheet. Queue it to start training.',

        'teaches' => 'Teaches :skill',

        // The detail card behind every slot. Pointing at one floats it;
        // tapping one opens the same card as a modal, which is the only
        // place the actions appear — see .inspector in ui-kit.css.
        'inspect' => 'Details',
        'inspect_hint' => 'Point at a slot for details, or tap it.',
        'close' => 'Close',

        'stats' => [
            'quantity' => 'Quantity',
            'weight' => 'Weight',
            'size' => 'Size',
            'capacity' => 'Capacity',
        ],

        // Two of a container's numbers, said two ways: the card wants what is
        // spent, the contents header wants what is left.
        'capacity_used' => ':used / :capacity',

        // What is inside a container, and why something would not go in.
        'contents' => 'On this',
        'contents_free' => ':free of :capacity free',
        'store' => 'Store',
        'store_in' => 'Store on :container',
        'eject' => 'Take out',
        'stored' => ':item is on :container.',
        'ejected' => ':item is loose again.',

        'placement' => [
            'not_owned' => 'You are not carrying that.',
            'not_a_container' => 'That does not hold anything.',
            'wrong_kind' => 'That will not go on there.',
            'no_room' => 'There is no room left on it.',
            'containers_do_not_nest' => 'That already holds things. It cannot go inside something else.',
        ],

        'refused' => [
            'not_a_skillbook' => 'That is not something you can inject.',
            'not_owned' => 'You are not carrying that.',
            'already_known' => 'You already know that skill. The book is no use to you.',
            'skill_missing' => 'That book teaches a skill that no longer exists.',
        ],
    ],

    /*
    | Item NAMES and flavour are columns on the items table, not keys here.
    | What is left is the vocabulary shared across every item.
    */
    'items' => [
        /*
        | What kind of thing an item is, as its card says it. Not the inventory
        | names ("Carried", "Deck"): those are places, and this is the item.
        | Deck hardware answers the same question with its own word — see
        | App\Models\Item::kindName() and strings.decks.hardware.
        */
        'kinds' => [
            'physical' => 'Physical',
            'data' => 'Data',
        ],

        /*
        | Two tiers, and they say only which step of the scale a thing is on.
        | The old five carried flavour names — Standard through Anomalous —
        | for a hierarchy nothing mechanical ever read.
        */
        'tiers' => [
            1 => 'Tier One',
            2 => 'Tier Two',
        ],

        /*
        | How hard a thing is to come by, which is NOT the tier above. Tier is
        | what the thing is; rarity is how often the market has one.
        */
        'rarities' => [
            'common' => 'Common',
            'uncommon' => 'Uncommon',
            'rare' => 'Rare',
            'ultra_rare' => 'Ultra-Rare',
        ],

        'skillbook' => [
            'name' => 'Skillbook: :skill',
            'flavor' => 'Compressed instruction set. One read, then it is gone.',
        ],

        'stat' => [
            'weight' => 'Weight',
            'cost' => 'Cost',
        ],

        'catalogue_empty' => 'No items in the catalogue yet.',
    ],

    /*
    | Cyberdecks, the modules that go in them, and the sockets in between.
    |
    | The vocabulary only — what a socket is called, what unit a number is in.
    | Which sockets a particular deck HAS is rows in `deck_slots`, and what a
    | particular module is worth is a row in `deck_modules`: this is the half
    | that is shared by every deck in the game, so it is the half that has no
    | row of its own.
    */
    'decks' => [

        // What an item IS, hardware-wise. `items.hardware` holds these.
        'hardware' => [
            'deck' => 'Cyberdeck',
            'cpu' => 'CPU',
            'npu' => 'NPU',
            'ram' => 'RAM',
            'nic' => 'NIC',
            'expansion' => 'Expansion card',
            'psu' => 'Power supply',
            'thermal' => 'Radiator',
        ],

        // What a deck PRESENTS. Six seat a module; the IO ports seat a person.
        'slots' => [
            'cpu' => 'CPU',
            'npu' => 'NPU',
            'ram' => 'RAM',
            'nic' => 'NIC',
            'expansion' => 'Expansion',
            // An initialism like its siblings, and deliberately not "Power":
            // every field on a deck is a socket, and a lone noun among the
            // initialisms reads as an attribute the deck has rather than as a
            // socket it presents. The module that goes in it is a Power supply.
            'psu' => 'PSU',
            'thermal' => 'Thermal exhaust',
            'io' => 'IO port',
        ],

        // The sub-types, short enough to sit in a spec line.
        'variants' => [
            'socket_g' => 'Socket G',
            'socket_k' => 'Socket K',
            'socket_z' => 'Socket Z',
            'opto' => 'Opto-electrical',
            'qdram' => 'QDRAM',
            'universal' => 'Universal',
            'xgate' => 'XGate',
            'pei' => 'PEI',
            'horizon' => 'Horizon',
            'electrode' => 'Electrode',
            'neuralink' => 'NeuraLink',
        ],

        /*
        | The half-sentence the editor shows under a socket's name. Optional
        | by design — Variant::note() renders nothing for a variant with no
        | entry here, so adding one is never a requirement.
        */
        'notes' => [
            'socket_g' => '448-pin',
            'socket_k' => '624-pin',
            'socket_z' => '962-pin',
            'opto' => 'Opto-electrical',
            'qdram' => 'Quantum-entangled dynamic RAM',
            'universal' => 'Takes either kind of stick',
            'pei' => 'Peripheral Extension Interface',
            'npu' => 'Neural processing — required by some AI programs',
            'thermal' => 'Where the load goes. A deck may present none at all.',
        ],

        // The board: a deck's sockets seen as the thing rather than as a
        // spec line. Nothing is fitted into any of them yet, and the screen
        // says so rather than implying a loadout that does not exist.
        'board' => [
            'label' => 'Board',
            'title' => ':deck',
            'lede' => 'Every socket this deck presents. Open one to fit a module, or to take one back out.',
            'empty' => 'No sockets',
            'count' => '×:count',
            'aria' => ':deck — :sockets',
            'spec' => 'Specification',
            'open' => 'Board',
            'close' => 'Close',
        ],

        // Putting a module in, and taking it back out.
        'fitting' => [
            'fit' => 'Fit',
            'unfit' => 'Take out',
            'empty_socket' => 'Empty',
            'choose' => 'What goes in the :socket socket',
            'nothing_fits' => 'You are carrying nothing that fits this socket.',
            // An IO port seats nothing at all, which is a different sentence
            // from "you happen to own nothing that fits".
            'seats_nothing' => 'This is where the deck reaches you. Nothing is fitted into it.',
            'fitted' => ':module is in the :socket socket.',
            'removed' => 'Taken out. It is back in your stash.',

            // Emptying every socket at once. Counted, because "3 modules" and
            // "1 module" are the difference between a mistake and a decision.
            'strip' => 'Strip modules',
            'strip_hint' => 'Takes everything out and back into your stash. Nothing is destroyed.',
            'stripped' => [
                'zero' => 'That deck was already empty.',
                'one' => ':count module is back in your stash.',
                'many' => ':count modules are back in your stash.',
            ],
            'draw' => 'Draw',
            'supply' => 'Supply',
            'budget' => ':draw of :supply W',
            'over_budget' => 'Drawing more than the supplies deliver. Nothing enforces it yet — see MECHANICS.',

            // Refusals. Every one is an ordinary thing to try.
            'not_owned' => 'That is not yours.',
            'not_a_deck' => 'That is not a cyberdeck.',
            'not_a_module' => 'That does not go in a deck.',
            'wrong_deck' => 'That socket is on a different deck.',
            'wrong_socket' => 'That will not go in this socket.',
            'socket_occupied' => 'Something is already in that socket.',
            'already_fitted' => 'That module is already in a deck.',
            'not_fitted' => 'There is nothing in that socket.',
            'not_equipped' => 'You have no deck equipped.',
            'deck_has_no_socket' => 'Your deck has no socket that takes this.',
            'no_free_socket' => 'Every socket that takes this is already full.',
            'stowed' => 'That is packed away. Take it out of its container first.',
        ],

        /*
        | What a deck adds up to, and whether it will run.
        |
        | The three readouts are the three measures a fitted module can carry,
        | labelled by the MEASURE and not by the socket: qFLOPS comes from a
        | CPU or an NPU, and a deck with one of each has one compute figure
        | rather than two halves of one.
        */
        'ready' => [
            'section' => 'Readiness',
            'yes' => 'Ready to run',
            'no' => 'Not ready',
            'needs' => 'Still needs:',

            'compute' => 'Compute',
            'memory' => 'Memory',
            'link' => 'Link',
            'thermal' => 'Thermal load',

            'missing' => [
                'compute' => 'A CPU or an NPU',
                'memory' => 'A stick of RAM',
                'link' => 'A NIC',
                'power' => 'A power supply',
                // Short on purpose: .tag is a fixed-height chip and cannot
                // wrap, so a long one overflows its callout on a phone.
                'budget' => 'Enough power',
            ],
        ],

        /*
        | Thermal load: the second budget, and not the same shape as the first.
        |
        | A deck over its POWER budget will not run, and readiness says so. A
        | deck over its THERMAL budget runs perfectly well and risks cooking
        | what is in it. So this warns rather than refusing, and it is not one
        | of the readiness requirements.
        */
        'thermal' => [
            // No deck-level budget: a deck has no thermal rating and thermal
            // load does not happen TO it. What the number means is decided per
            // module.
            'cooled' => 'Cooled by :value TU',
            'risk_not_built' => 'Nothing is destroyed yet — the risk is specified, not built.',

            /*
            | What the load is doing to each thing fitted in it. A module's two
            | thresholds are compared against the deck's NET load, so the same
            | part is safe in a cold deck and dying in a hot one.
            */
            'verdict' => [
                'safe' => 'Within tolerance',
                'at_risk' => 'Over its warning level',
                'over_limit' => 'Past its limit',
            ],

            'at_risk' => 'Running over their warning level:',
            'at_risk_note' => 'They can be destroyed while the deck runs this hot.',

            // The hard limit. Not a risk — a certainty, and the loudest thing
            // this screen has to say.
            'over_limit_code' => 'THERMAL',
            'over_limit' => 'This loadout destroys :modules the moment the deck runs.',
            'over_limit_fix' => 'Fit a radiator, cut the load, or use parts that tolerate more.',
        ],

        /*
        | Which deck is in use. NOT jacking in and out — that is entering and
        | leaving cyberspace, a session you are in rather than a thing you are
        | carrying, and an equipped deck stays equipped through it. Equipping
        | takes the deck out of the stash; unequipping puts it back.
        */
        'equip' => [
            'equip' => 'Equip',
            'unequip' => 'Unequip',
            'equipped' => ':deck is equipped.',
            'is_equipped' => 'Equipped',
            'nothing_yet' => 'No number a deck holds is read by anything yet. Equipping decides which deck that will be.',
            'unequipped' => 'Unequipped. It is back in your stash, still built.',
            'unequipped_stripped' => [
                'zero' => 'Unequipped. It is back in your stash.',
                'one' => 'Unequipped, and :count module came out. Both are in your stash.',
                'many' => 'Unequipped, and :count modules came out. All of it is in your stash.',
            ],

            // The choice on the way out. Both are ordinary, so neither is the
            // dangerous one and neither gets the ember.
            'confirm' => 'Unequipping',
            'question' => 'Take the modules out, or leave the deck built?',
            'leave' => 'Leave them in',
            'leave_hint' => 'The deck goes back to your stash exactly as it is.',
            'take' => 'Strip and unequip',
            'take_hint' => 'Every module comes out, and the deck follows it into your stash.',
        ],

        // Made-up units, spelled one way. :value arrives already grouped.
        'units' => [
            'qflops' => ':value qFLOPS',
            'quantabits' => ':value QB',
            'bandwidth' => ':value QB/s',
            'watts' => ':value W',
            'draw' => ':value W draw',
            // Made-up units like the rest. Thermal units, not degrees:
            // what a module makes and what a radiator takes away are the
            // same quantity, and neither is a temperature.
            'heat' => ':value TU',
            'cooling' => ':value TU cooling',
        ],

        'summary' => [
            'times' => ':count× :label',
            // A middle dot, so a spec line reads as one line rather than a list.
            'separator' => ' · ',
        ],

        'no_slots' => 'No sockets yet.',
    ],

    /*
    | Merged over the framework's own validation messages by the validator, so
    | only the custom rules belong here.
    */
    'validation' => [
        'handle_characters' => 'Handles may use letters and digits from any alphabet, plus: :symbols',
        'handle_length' => 'Handles may be at most :max characters long.',
    ],

    /*
    | The admin tools. Not player-visible, but the same rule applies: a string
    | that is not attached to a row lives here, so the Blade stays markup.
    |
    | Item and skill NAMES are still columns — these are the words around them.
    */
    'admin' => [

        'nav' => 'Admin',
        'title' => 'Admin tools',
        'lede' => 'Content lives in the database, so it gets none of the protection config content gets from tests and review. These screens are that protection.',

        'actions' => [
            'save' => 'Save changes',
            'create' => 'Create',
            'cancel' => 'Cancel',
            'delete' => 'Delete',
            'search' => 'Search',
            'apply' => 'Apply',
            'clear' => 'Clear',
            'new_item' => 'New item',
            'new_skill' => 'New skill',
            'add_requirement' => 'Add requirement',
            'add_skill' => 'Add starting skill',
            'add_item' => 'Add starting item',
            'remove' => 'Remove',
        ],

        'items' => [
            'title' => 'Items',
            'lede' => 'Every item definition. A slug is permanent once it exists — retire an item with :off, never by renaming it.',
            'new' => 'New item',
            'editing' => 'Editing :item',
            'created' => ':item created.',
            'updated' => ':item saved.',
            'deleted' => ':item deleted, and :rows related rows with it.',
            'empty' => 'No items match.',
            'search_hint' => 'Slug or name',

            'containers' => [
                'any' => 'Both inventories',
                'physical' => 'Physical — carried',
                'data' => 'Data — on the deck',
            ],

            'market' => [
                'any' => 'Listed and unlisted',
                'on' => 'Listed only',
                'off' => 'Unlisted only',
            ],

            'fields' => [
                'slug' => 'Slug',
                'slug_hint' => 'Permanent. Lower case, digits and underscores.',
                'slug_locked' => 'Permanent — inventory rows reference it.',
                'name' => 'Name',
                'name_book' => 'A skillbook has no name of its own: it reads from the skill it teaches.',
                'flavor' => 'Flavour',
                'flavor_hint' => 'One or two lines. Shown under the name on the card.',
                'container' => 'Kind',
                'container_hint' => 'Which of the two inventories it belongs to. A property of the item, never a player choice.',
                'tier' => 'Tier',
                'tier_hint' => 'What the thing is. Colours the card and carries no mechanics.',
                'rarity' => 'Rarity',
                'rarity_hint' => 'How often the market has one. Not the tier — the two are free to disagree.',
                'stackable' => 'Stackable',
                'stackable_hint' => 'Whether two of them share one inventory row.',
                'use_op' => 'Use',
                'use_op_hint' => 'What using it does. Most items do nothing.',
                'use_op_none' => 'Nothing',
                'use_skill' => 'Teaches',
                'use_skill_hint' => 'The skill this book injects. One book per skill.',
                'weight' => 'Weight',
                'weight_hint' => 'Stored and not yet enforced.',
                'size' => 'Size',
                'size_hint' => 'What one costs against a container it is stored in.',
                'capacity' => 'Capacity',
                'capacity_hint' => 'Above zero makes it a container. Zero is almost everything.',
                'accepts' => 'Accepts',
                'accepts_hint' => 'Which kind it holds. Required exactly when capacity is above zero.',
                'accepts_none' => 'Nothing',
                'base_cost' => 'Cost',
                'on_market' => 'Listed on the market',
                'on_market_hint' => 'Unlist to retire an item without deleting it.',
                'seed_quantity' => 'Full shelf',
                'seed_quantity_hint' => 'How many a restock tops up to. Zero means the market never stocks it.',

                'hardware' => 'Deck hardware',
                'hardware_hint' => 'Whether this is a cyberdeck, a module that goes in one, or neither. Neither is most of the catalogue.',
                'hardware_none' => 'Not deck hardware',
                'socket' => 'Socket',
                'socket_hint' => 'Which socket it fits. A deck with no socket of that type cannot take it.',
                'power_draw' => 'Power draw',
                'power_draw_hint' => 'Watts off the deck\'s supply. A power supply draws none.',
                'heat' => 'Heat',
                'heat_hint' => 'What it makes by running, in thermal units. Zero is fine; a radiator makes none.',
                'cooling' => 'Cooling',
                'cooling_hint' => 'What a radiator takes away, in thermal units.',
                'heat_level_warning' => 'Warns at',
                'heat_level_warning_hint' => 'Deck heat at which this part starts to be at risk. Leave both empty for a part that does not care.',
                'heat_level_limit' => 'Destroyed at',
                'heat_level_limit_hint' => 'Deck heat at which this part is gone. A hard limit, not a roll. Must not be below the warning.',
                'qflops' => 'Compute',
                'qflops_hint' => 'Raw throughput, in qFLOPS.',
                'quantabits' => 'Memory',
                'quantabits_hint' => 'Capacity, in quantabits.',
                'bandwidth' => 'Bandwidth',
                'bandwidth_hint' => 'Cyberspace throughput, in QB per second.',
                'watts' => 'Rating',
                'watts_hint' => 'What the supply delivers, in watts.',
            ],

            'sections' => [
                'identity' => 'Identity',
                'classification' => 'Classification',
                'use' => 'Use',
                'physical' => 'Bulk and containment',
                'market' => 'Market',
                'hardware' => 'Deck hardware',
                'sockets' => 'Sockets',
                'board' => 'Board arrangement',
                'module' => 'Module specification',
            ],

            'columns' => [
                'slug' => 'Slug',
                'name' => 'Name',
                'kind' => 'Kind',
                'tier' => 'Tier',
                'rarity' => 'Rarity',
                'cost' => 'Cost',
                'stock' => 'Stock',
            ],

            'teaches' => 'teaches :skill',
            'unlisted' => 'Unlisted',
            'container_of' => 'holds :accepts',

            'hardware' => [
                'any' => 'Any hardware, and none',
                'none' => 'Not deck hardware',
                'filter' => 'Hardware',
            ],

            'board' => [
                'help' => 'Drag the boxes, or focus one and use the arrow keys. Positions are percentages of the frame, so the board reads the same at any width. Every wire runs to the CPU.',
                'note' => 'Arranged for the sockets as SAVED. Change the counts above, save, and come back to place the new ones.',
                'reset' => 'Reset to automatic',
                'reset_hint' => 'Clears the arrangement. The board falls back to a ring around the CPU.',
                'automatic' => 'Not arranged — showing the automatic ring.',
            ],

            'sockets' => [
                'help' => 'How many of each socket this deck presents. A deck needs somewhere to put a CPU and somewhere to draw power; everything else is optional. Sockets are what a module is fitted into, so the numbers here are the deck.',
                'none' => 'No sockets. This deck cannot be built yet.',
                'total' => ['one' => ':count socket', 'many' => ':count sockets'],
            ],
        ],

        'skills' => [
            'title' => 'Skills',
            'lede' => 'Every skill, and what it requires first. Creating one also creates the book that teaches it — there is exactly one book per skill, and nothing can be learned without it.',
            'new' => 'New skill',
            'editing' => 'Editing :skill',
            'created' => ':skill created, along with its book :book.',
            'updated' => ':skill saved.',
            'deleted' => ':skill deleted, and :rows related rows with it.',
            'empty' => 'No skills match.',
            'search_hint' => 'Slug or title',
            'all_categories' => 'Every category',
            'without_books' => [
                'one' => ':count skill has no book.',
                'many' => ':count skills have no book.',
            ],

            'fields' => [
                'slug' => 'Slug',
                'slug_hint' => 'Permanent. Lower case, digits and underscores. The book is named from it.',
                'slug_locked' => 'Permanent — saved progress references it.',
                'title' => 'Title',
                'title_hint' => 'What a player reads. The book reads from it too.',
                'description' => 'Description',
                'category' => 'Category',
                'category_hint' => 'The lane it hangs in on the skill tree. Reuse an existing one to group it.',
                'rank' => 'Rank',
                'rank_hint' => 'A training-time multiplier, not a cost: rank 2 needs twice the points for the same level.',
                'primary_attribute' => 'Primary attribute',
                'secondary_attribute' => 'Secondary attribute',
                'attribute_hint' => 'Together these decide skill points per minute. They should differ.',
                'base_cost' => 'Cost',
                'base_cost_hint' => 'What the book sells for. Kept in step with it on every save.',
                'on_market' => 'Book listed on the market',
            ],

            'sections' => [
                'identity' => 'Identity',
                'training' => 'Training',
                'market' => 'Market',
                'prerequisites' => 'Requirements',
                'book' => 'Skillbook',
            ],

            'columns' => [
                'slug' => 'Slug',
                'title' => 'Title',
                'category' => 'Category',
                'rank' => 'Rank',
                'requires' => 'Requires',
                'unlocks' => 'Unlocks',
                'book' => 'Book',
            ],

            'prerequisites' => [
                'help' => 'What must be trained first. A requirement that closes a loop is refused — nobody could ever learn either skill.',
                'skill' => 'Skill',
                'level' => 'At level',
                'none' => 'No requirements. This skill can be trained from the start.',
            ],

            'book' => [
                'present' => 'Its book is :book.',
                'edit' => 'Edit the book',
                'missing' => 'This skill has no book, so nobody can learn it. It was deleted, or the skill predates the editor.',
                'derived' => 'Cost and listing follow the skill. Tier, rarity and shelf size were set from its rank when the book was made, and are the item editor\'s from then on.',
                'yes' => 'Yes',
                'no' => 'Missing',
            ],
        ],

        'archetypes' => [
            'title' => 'Archetypes',
            'lede' => 'The seven starting roles, and what each one hands a character on the way in. An archetype cannot be created or deleted here: ids are permanent because every character row stores one.',
            'editing' => 'Editing :archetype',
            'updated' => ':archetype saved.',
            'empty' => 'No archetypes.',
            'without_loadout' => [
                'one' => ':count hands out nothing',
                'many' => ':count hand out nothing',
            ],

            'fields' => [
                'slug' => 'Slug',
                'slug_locked' => 'Permanent — character rows reference the id, and the slug names it.',
                'name' => 'Name',
                'name_hint' => 'What a player picks off the creation screen.',
                'role' => 'Role',
                'role_hint' => 'The line under the name. A few words, not a sentence.',
                'description' => 'Description',
                'description_hint' => 'Second person, present tense. This is the pitch.',
                'starting_creds' => 'Starting creds',
                'starting_creds_hint' => 'Cash in hand at creation. Nothing spends it yet.',
                'playable' => 'Offered to new characters',
                'playable_hint' => 'Off keeps the archetype on the creation screen but greys it out. It is how a role is withdrawn — never by deleting it, since every character who chose it points at the id.',
            ],

            'sections' => [
                'identity' => 'Identity',
                'loadout' => 'Starting loadout',
                'skills' => 'Starting skills',
                'items' => 'Starting items',
                'perks' => 'Perks',
            ],

            'columns' => [
                'slug' => 'Slug',
                'name' => 'Name',
                'role' => 'Role',
                'creds' => 'Creds',
                'skills' => 'Skills',
                'items' => 'Items',
                'perks' => 'Perks',
                'playable' => 'Offered',
            ],

            'offered' => [
                'yes' => 'Yes',
                'no' => 'Withdrawn',
            ],

            'skills' => [
                'help' => 'Skills the character already has. Level 0 is what a skillbook buys — on the sheet, untrained; anything above it is training that happened before the game started. A skill granted here is granted whole: no book is spent, and the points are seeded to exactly that level.',
                'skill' => 'Skill',
                'level' => 'At level',
                'level_zero' => 'Injected, untrained',
                'none' => 'No starting skills. This archetype begins with nothing on the sheet.',
                'empty_catalogue' => 'There are no skills to grant yet.',
            ],

            'items' => [
                'help' => 'Items in the character\'s inventory at creation, loose rather than inside anything. Which inventory each lands in comes from the item itself.',
                'item' => 'Item',
                'quantity' => 'How many',
                'none' => 'No starting items.',
                'empty_catalogue' => 'There are no items to grant yet.',

                /*
                | The optgroups the item list is offered in. Deck hardware
                | names itself through App\Decks\Hardware::label(); these are
                | the buckets for everything else, and the books get their own
                | because there is one per skill.
                */
                'groups' => [
                    'physical' => 'Carried',
                    'data' => 'On the deck',
                    'skillbooks' => 'Skillbooks',
                ],
            ],

            'perks' => [
                'help' => 'What the archetype CHANGES, as opposed to what it gives. Perks are not edited here yet — a live perk is a list of modifiers, which is an editor of its own.',
                'none' => 'No perks.',
                'live' => 'Live',
                'display_only' => 'Display only',
            ],
        ],

        /*
        | What a delete would destroy, counted at the moment it is asked. Every
        | one of these is a cascade the schema performs on its own, except a
        | skill's book, which the controller deletes first because the foreign
        | key restricts rather than cascades.
        */
        'impact' => [
            'title' => 'This also destroys',
            'nothing' => 'Nothing else points at it.',
            'irreversible' => 'There is no undo, and no soft delete. To retire content instead, unlist it.',
            'confirm' => 'Delete :name?',
            'keep' => 'Keep it',
            'destroy' => 'Delete for good',

            'inventory_rows' => ['one' => ':count inventory row', 'many' => ':count inventory rows'],
            'copies' => ['one' => ':count copy players are carrying', 'many' => ':count copies players are carrying'],
            'shelf' => ['one' => ':count on the market shelf', 'many' => ':count on the market shelf'],
            'purchases' => ['one' => ':count purchase record', 'many' => ':count purchase records'],
            'contents' => ['one' => ':count row stored inside one', 'many' => ':count rows stored inside them'],
            'orphans_skill' => ['one' => 'the skill it teaches becomes unlearnable', 'many' => 'the skill it teaches becomes unlearnable'],

            'characters' => ['one' => ':count character\'s progress in it', 'many' => ':count characters\' progress in it'],
            'queue_entries' => ['one' => ':count queued training entry', 'many' => ':count queued training entries'],
            'dependents' => ['one' => ':count skill that requires it', 'many' => ':count skills that require it'],
            'own_prerequisites' => ['one' => ':count requirement of its own', 'many' => ':count requirements of its own'],
            'books' => ['one' => 'its skillbook', 'many' => 'its skillbook'],
            'book_copies' => ['one' => ':count copy of the book players hold', 'many' => ':count copies of the book players hold'],
            'archetype_loadouts' => [
                'one' => ':count archetype stops handing it to new characters',
                'many' => ':count archetypes stop handing it to new characters',
            ],
        ],

        /*
        | Only the rules the framework would explain badly. Everything else
        | keeps Laravel's own message, which is already about the right field.
        */
        'validation' => [
            'slug' => 'Use lower case letters, digits and underscores only.',
            'slug_immutable' => 'A slug is permanent and cannot be changed.',
            'name_required' => 'Give it a name. Only a skillbook may go without one.',
            'container_stacks' => 'A container cannot stack: two of them would merge into one row and the contents of the second would be lost. Untick stackable.',
            'book_needs_skill' => 'A book that teaches nothing is not a book. Choose the skill it injects.',
            'skill_without_book' => 'Only an injecting item may name a skill.',
            'skill_has_a_book' => 'That skill already has a book. There is exactly one per skill.',
            'capacity_needs_kind' => 'A container has to say what it holds.',
            'kind_needs_capacity' => 'This holds nothing, so it cannot name a kind. Give it a capacity first.',
            'deck_stacks' => 'A cyberdeck cannot stack: two of them would merge into one row, and only one of them could carry what is fitted in it. Untick stackable.',
            'hardware_is_physical' => 'Deck hardware is matter. A cyberdeck and every module that goes in one is carried, never stored on a deck.',
            'deck_needs_slots' => 'A cyberdeck with no sockets is a case. Give it at least a CPU socket and a power socket.',
            'slots_need_a_deck' => 'Only a cyberdeck has sockets. Set the hardware to Cyberdeck first.',
            'heat_levels_pair' => 'Give both heat levels or neither — a part with one and not the other has no meaning.',
            'heat_levels_order' => 'A part cannot be destroyed before it is warned about. The limit must be at or above the warning.',
            'no_heat' => 'A radiator takes heat away rather than making it. Leave this empty.',
            'module_needs_heat' => 'Say how much heat this makes. Zero is a fine answer.',
            'board_needs_a_deck' => 'Only a cyberdeck has a board. Set the hardware to Cyberdeck first.',
            'board_malformed' => 'The board arrangement could not be read. Drag the boxes again, or reset it.',
            'board_unknown_socket' => 'The board arrangement names a socket that does not exist.',
            'deck_needs_kind' => 'A cyberdeck needs at least one :kind socket.',
            'too_many_slots' => 'A cyberdeck may present at most :max :kind sockets, counting every type of them together.',
            'module_needs_variant' => 'Say which socket it fits. A module that fits nothing cannot be installed.',
            'variant_needs_a_kind' => 'This kind of item has no socket to name.',
            'module_needs_power' => 'Say what it draws. Zero is a valid answer; blank is not.',
            'no_power_draw' => 'This draws no power. A supply gives it, and nothing else here has a budget to spend.',
            'same_attribute' => 'Pick two different attributes, or the skill earns points from one of them twice.',
            'requires_itself' => 'A skill cannot require itself.',
            'requires_twice' => 'That skill is already named. One requirement per skill.',
            'cycle' => 'That closes a loop — :path — and nobody could ever learn either skill.',

            'granted_twice' => 'That is already in the loadout. One grant per skill or item.',
            'grant_needs_prerequisite' => 'Starting at level :level in :skill is a state training could never produce: it requires :requires at level :required, which this archetype does not grant. Grant that too, or start the skill at level 0.',
        ],
    ],

];
