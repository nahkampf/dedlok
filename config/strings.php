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
            'character' => 'Character',
            'stash' => 'Stash',
            'deck' => 'Deck',
            // The grid. Never "jack in" here: the rail names a PLACE, and the
            // act of entering it is a button on the screen it leads to.
            'implants' => 'Implants',
            'cyberspace' => 'Cyberspace',
            'skills' => 'Skills',
            'market' => 'Market',
            'mission' => 'Work',
            'achievements' => 'Achievements',
            // The inbox. "Messages" rather than "Notifications" because the
            // rail is 220px open and one word has to fit beside a glyph.
            'messages' => 'Messages',
            'wallet' => 'Wallet',
            'city' => 'City',
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
        'login' => 'Sign in',
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
        'remember' => 'Stay signed in',
        'forgot' => 'Lost your key?',
        'start_playing' => 'New account',
        'have_account' => 'Already have an identity?',
        'logout' => 'Sign out',
    ],

    /*
    |--------------------------------------------------------------------------
    | Programs
    |--------------------------------------------------------------------------
    |
    | Software: the first items in the game that change a number. A program is
    | running or it is not — there is no loading — and running splits into
    | background, which happens while the owner is elsewhere, and active, which
    | only means during a cyberspace run.
    |
    */
    'programs' => [

        'kinds' => [
            'icebreaker' => 'Icebreaker',
            'decryptor' => 'Decryptor',
            'evasion' => 'Evasion',
            'analyser' => 'Analyser',
            'daemon' => 'Daemon',
        ],

        // The status readout. A count, because the number is the whole of what
        // the bar has room to say; the modal says the rest.
        'running' => 'Running',
        'started' => ':program is running.',
        'stopped' => ':program has stopped.',

        // The deck screen's two tabs. Storage is split because a program and a
        // file are two different things sharing an inventory: one is something
        // you RUN, the other something you HOLD.
        'tabs' => [
            'running' => 'Running',
            'storage' => 'Storage',
        ],
        'storage' => [
            'programs' => 'Programs',
            'data' => 'Data',
            'programs_empty' => 'No software.',
            'data_empty' => 'Nothing written.',
        ],
        'budget' => ':used / :total',
        // The bare unit, for a readout that has already said the number.
        'units' => ['compute' => 'qFLOPS', 'memory' => 'QB'],
        // Only the deck you are carrying runs anything: putting one down stops
        // everything on it, so an unequipped deck's list is empty by rule
        // rather than by accident, and the screen says which.
        'not_equipped' => 'Nothing runs on a deck you are not carrying.',
        'open_deck' => 'Open the deck',
        'running_none' => 'Nothing running',
        'processes' => ['zero' => 'No processes', 'one' => ':count process', 'many' => ':count processes'],

        'costs' => [
            'compute' => ':value qFLOPS',
            'memory' => ':value QB',
            'storage' => ':value QB stored',
        ],

        'background' => 'Runs in the background',
        'run_only' => 'Runs only during an intrusion',

        'action' => [
            'start' => 'Run',
            'stop' => 'Stop',
        ],

        'refused' => [
            'not_owned' => 'That is not yours to run.',
            'not_a_program' => 'That is not software.',
            'already_running' => 'It is already running.',
            'no_free_thread' => 'You are already running as many copies of this as you can at once.',
            'not_running' => 'It was not running.',
            'no_deck_equipped' => 'Equip a deck first. Software runs on the machine you are carrying.',
            'deck_not_ready' => 'That deck will not run. Finish building it first.',
            'not_enough_compute' => 'Not enough compute left. Stop something, or fit a better processor.',
            'not_enough_memory' => 'Not enough memory left. Stop something, or fit more RAM.',
            'run_only' => 'This only does anything during an intrusion. Choose it on the briefing and the run starts it.',
            'one_icebreaker' => 'Only one icebreaker runs at a time.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Images
    |--------------------------------------------------------------------------
    |
    | Pictures are found by convention rather than stored on a row — see
    | App\Support\Image. These are the words around them.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Achievements
    |--------------------------------------------------------------------------
    |
    | The catalogue itself is `config/achievements.php` — a metric and a number
    | each. What is here is what a player READS, and today that is one
    | placeholder name composed from the id. When the real names are written
    | this becomes a map keyed by the same id, and nothing else changes.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | The inbox
    |--------------------------------------------------------------------------
    |
    | WHAT HAPPENED WHILE NOBODY WAS LOOKING, which is the whole test for
    | whether a sentence belongs down here rather than in a flash message. A
    | flash covers what the player just did; these are the things that happened
    | to them while the tab was shut.
    |
    | Every message under `messages` is RENDERED WHEN IT IS WRITTEN and stored
    | as a sentence — App\Notifications\Notifier is the only reader of these
    | keys. So rewording one changes what the next message says and never what
    | an old one said, which is correct: an old message described a moment that
    | has already passed.
    |
    | They are written in the PAST TENSE and name the thing they are about,
    | because a notification is read out of context — hours later, in a list of
    | other things, by somebody who has forgotten what they left running.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Drugs
    |--------------------------------------------------------------------------
    |
    | Take one to get the effect; the two books move. Tolerance costs potency,
    | addiction costs the option of stopping, and both come off slowly if left
    | alone. The words are deliberately plain — the mechanic is legible enough
    | without the game being pleased with itself about it.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | The market
    |--------------------------------------------------------------------------
    |
    | The first thing in the game that spends money. What is on the shelf is
    | what the restock pass left — never the catalogue with stock counts laid
    | over it, because an item nobody can buy today is not something a shop is
    | holding.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | The character sheet
    |--------------------------------------------------------------------------
    |
    | Who you are rather than what you are carrying. Three screens that used to
    | have rail items of their own are tabs of this window now — they kept
    | their URLs, so the strip is a nav of links rather than a panel switch.
    |
    */

    'city' => [
        'title' => 'City',
        'lede' => 'Your body is out here, in one district at a time.',
        'map' => 'The city',
        'district_map' => 'District map',
        'here' => 'You are here',
        'in_district' => 'You are in :district.',
        'back' => '← City',
        'jack_out_first' => 'Jack out before you do anything in the City.',
        'districts' => 'Districts',
        'locations' => 'Places you know here',
        'no_locations' => 'Nothing you know of yet.',
        'map_empty' => 'Nothing to show.',
    ],

    'standing' => [
        'title' => 'Standing',
        'heading' => 'Who thinks what of you',
        'empty' => 'Nobody has an opinion of you yet.',
        'entity' => 'Entity',
        'band' => 'Opinion',
        'value' => 'Standing',
        'log' => 'Changes',
        'log_empty' => 'Nothing has changed yet.',
    ],

    'wallet' => [
        'title' => 'Wallet',
        'lede' => 'Every credit that came in or went out, and a way to send some to somebody else.',
        'balance' => 'Balance',
        'transfer' => 'Transfer cash',
        'handle' => 'To (handle)',
        'amount' => 'Amount',
        'send' => 'Send',
        'sent' => 'Sent :amount ¤ to :handle.',
        'ledger' => 'Transactions',
        'empty' => 'Nothing has moved yet.',
        'when' => 'When',
        'what' => 'What',
        'change' => 'Change',
        'after' => 'Balance',
        'notes' => [
            'sent' => 'To :handle',
            'received' => 'From :handle',
        ],
        'reasons' => [
            'starting' => 'Starting funds',
            'market' => 'Market purchase',
            'player_market' => 'Player market',
            'mission' => 'Mission reward',
            'siphon' => 'Siphon payout',
            'extraction' => 'Taken from a system',
            'hunt' => 'Hunter bounty',
            'skim' => 'Skimmed by ICE',
            'transfer_out' => 'Transfer sent',
            'transfer_in' => 'Transfer received',
        ],
        'refused' => [
            'amount' => 'Send at least 1 ¤.',
            'no_such_character' => 'Nobody goes by that handle.',
            'yourself' => 'You cannot send cash to yourself.',
            'cannot_afford' => 'You do not have that much.',
        ],
    ],

    'market' => [
        'title' => 'Market',
        'lede' => 'A glowing web of pulsating transactional threads, billions of credits changing hands every second.',

        'shelf' => 'On the shelf',
        'empty' => 'Nothing for sale right now. Come back — the shelves refill on their own.',

        'price' => ':price ¤',
        'quantity' => 'How many',
        'buy' => 'Buy',
        'too_dear' => 'Not enough',
        'on_shelf' => ['zero' => 'none left', 'one' => ':count left', 'many' => ':count left'],
        'search' => 'Search the market',
        'search_placeholder' => 'Name, kind or skill',
        'no_match' => 'Nothing on the shelves matches that.',
        // The corner tick's title, on hover.
        'tick' => [
            'owned' => 'You have one of these',
            'injected' => 'Skill already injected',
        ],

        'bought' => ':quantity × :item, for :paid ¤.',

        /*
        | Why nothing was bought. "Not enough left" is deliberately separate
        | from "not stocked": somebody who asked for five and could have had
        | two did not decide that, and quietly selling them two would be the
        | shop making the decision.
        */
        'refused' => [
            'not_stocked' => 'Nobody is holding that today.',
            'not_enough_left' => 'There are not that many left.',
            'cannot_afford' => 'You cannot afford that.',
            'no_storage' => 'Your deck has no room for it. Make room in its storage first.',
        ],

        /*
        | THE OTHER MARKET. The shelf sells a KIND of thing and conjures a copy
        | on payment; an offer sells one particular row out of one particular
        | stash, which is why everything here names the object rather than the
        | catalogue entry behind it.
        */
        'offers' => [
            'heading' => 'Sales orders',
            'empty' => 'Nobody is selling anything right now.',
            'seller' => 'Sold by :name',
            'yours' => 'Yours',
            'buy' => 'Buy',
            'list' => 'Show on market',
            'price_label' => 'Asking price, each',
            'quantity' => 'How many',
            'available' => ['zero' => 'none left', 'one' => ':count on offer', 'many' => ':count on offer'],
            'withdraw' => 'Take off the market',
            'listed' => ':item is on the market at :price ¤ each.',
            'withdrawn' => ':item is back in your stash.',
            'sold' => 'You bought :quantity × :item for :price ¤.',
            // A listed row is still yours and still in the stash — it simply
            // cannot be used while somebody might be about to buy it.
            'on_market' => 'On the market at :price ¤ each',

            'refused' => [
                'not_yours' => 'That is not yours to sell.',
                'not_to_hand' => 'That item is occupied. Take it out of whatever it is in first.',
                'equipped' => 'You are carrying that deck. Put it down before you sell it.',
                'gone' => 'That one has gone. Somebody got there first.',
                'your_own' => 'You are already holding that one.',
                'not_enough_left' => 'They are not selling that many.',
                'cannot_afford' => 'You cannot afford that.',
                'no_storage' => 'Your deck has no room for it. Make room in its storage first.',
            ],
        ],
    ],

    'drugs' => [

        'families' => [
            'stimulant' => 'Stimulant',
            'opiate' => 'Opiate',
            'sedative' => 'Sedative',
            'psychedelic' => 'Psychedelic',
            'nootropic' => 'Nootropic',
        ],

        // On the item card, so what a dose costs is readable before it is
        // taken rather than after.
        'summary' => ':family · :minutes min',
        'take' => 'Take',
        'in_effect' => 'In effect for :when',

        'taken' => ':name taken.',
        'taken_weakened' => ':name taken. It is worth :percent% of what it was.',

        // Said out loud, always. Losing nerve tissue is the one outcome
        // nobody should have to infer from a number moving somewhere else.
        'overdosed' => 'Too much at once. :hp hitpoints, and :points points of neural damage that will not come back on its own.',
        'overdosed_flatline' => 'Too much. Your heart stops. :points points of neural damage, and everything else went with it.',

        // On the waiting screen, where DownedCause renders what finished you.
        'downed' => 'You took too much :drug.',
        'downed_flatline' => ':drug stopped your heart.',

        'refused' => [
            'not_a_drug' => 'That is not something you can take.',
            'not_owned' => 'You do not have that.',
        ],
    ],

    'notifications' => [
        'title' => 'Messages',
        'lede' => 'Notifications and messages.',

        'inbox' => 'Inbox',
        'empty' => 'Nothing yet. This fills up while you are away.',
        'new' => 'New',
        'mark_read' => 'Mark all read',
        // Pieces of a line in a message's list (the siphon report's).
        'details' => [
            'grade' => 'grade :grade',
        ],
        'unread' => ['zero' => '', 'one' => ':count unread', 'many' => ':count unread'],
        'marked' => [
            'zero' => 'Nothing was unread.',
            'one' => 'One message marked read.',
            'many' => ':count messages marked read.',
        ],

        // Where a message came from, never how loud it is. One label per
        // App\Notifications\Category case.
        'categories' => [
            'cyberspace' => 'Cyberspace',
            'training' => 'Training',
            'achievement' => 'Achievement',
            'market' => 'Market',
            'mission' => 'Work',
            'city' => 'City',
            'wallet' => 'Wallet',
        ],

        'messages' => [
            'scan_complete' => 'Your scan of :system completed. The specifications are saved to a file.',
            'backdoor_found' => 'Your backdoor into :system was detected. The system has been purged and relocated.',
            // SOMEBODY ELSE'S BAD NIGHT. One sentence for a lockdown, a move and
            // a burn: the holder was not there, and naming the cause would tell
            // them about a stranger's run they could not otherwise know of.
            // THE NIGHT'S TAKE, one report per character. The systems it came
            // from are the message's list, drawn under this line.
            'siphons_ran' => 'Siphons ran: :creds ¤',
            'backdoor_lost' => 'Your backdoor into :system is gone. The system shut its doors while you were elsewhere.',
            // A FILE DIES WITH NOBODY LOOKING AT IT. Says it is still there,
            // because nothing deletes one - destroying it is the player's call
            // from the card, like every other row they own.
            'file_stale' => ':file is stale. Nobody will pay for a scan of :system now.',
            // WHAT SOMEBODY IS NOT THERE TO SEE. A listing is left up and
            // walked away from, so the sale lands on nobody's screen - and
            // creds arriving with nothing to explain them is the gap.
            'offer_sold' => 'Sold: :quantity × :item, for :price ¤.',
            // WORK THAT ENDED WITHOUT YOU THERE. Completing is not here:
            // that happens at the desk with somebody pressing a button.
            'mission_failed' => 'Your :action job for :entity ran out of time.',
            'mission_abandoned' => 'You walked away from the :action job for :entity.',
            'mission_voided' => 'The :action job for :entity is off: something it named is not there any more.',
            'skill_level' => ':skill reached level :level.',
            'achievement_unlocked' => 'Achievement unlocked: :name.',
            'withdrawal' => 'You need it. :family, and going without is starting to show.',
            'transfer_received' => ':handle sent you :amount ¤.',
            'bounty_word' => "You've made enemies out there. Word is being put out on the street. There's a price on your head now: ¤:bounty.",
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Work done for an entity
    |--------------------------------------------------------------------------
    |
    | A mission is a designated set of systems, an action required of each, and
    | a clock. The vocabulary is deliberately plain: an agent hands out work,
    | and the screen should read like a job board rather than a quest log.
    |
    */

    'missions' => [
        'heading' => 'Work',
        'board' => 'On offer',
        'board_empty' => 'Nothing on the board. Come back on the hour.',
        'active' => 'What you are doing',
        'active_none' => 'You are not working for this desk.',
        'refresh' => 'The board turns over every hour.',

        'accept' => 'Take it',
        'decline' => 'Turn it down',
        'abandon' => 'Give it up',
        'complete' => 'Hand it in',

        'pays' => 'Pays :creds ¤',
        'standing' => ':amount standing',
        'loyalty' => ':amount loyalty',
        'penalty' => 'Failing costs :amount standing',
        'window' => 'Time left: :time',
        'expired' => 'Out of time',
        'targets' => 'Targets',
        'targets_done' => ':done of :total done',
        'quantity' => ':count complete scan files',
        'target_gone' => 'Not there any more',
        'target_done' => 'Done',

        // Said on the board and again on the row, because a player choosing
        // between two jobs is choosing between two KINDS of evening.
        'actions' => [
            'scan' => 'Survey',
            'burn' => 'Demolition',
            'plant' => 'Placement',
            'supply' => 'Supply',
        ],

        'action_hint' => [
            'scan' => 'Scan every system named and bring the files back. The safest work there is, and the slowest.',
            'burn' => 'We want these systems gone. Burned. Shut down.',
            'plant' => 'Leave a backdoor on these systems. The door is ours, not yours - you get paid, and we get the way in.',
            'supply' => 'Bring us complete scan files. We do not care which systems, only that the picture is whole.',
        ],

        'states' => [
            'offered' => 'On offer',
            'accepted' => 'In progress',
            'completed' => 'Done',
            'failed' => 'Failed',
            'voided' => 'Called off',
        ],

        // Every one of these is something a player can reasonably try, which
        // is why they are values rather than exceptions.
        'refused' => [
            'not_yours' => 'That is not your job.',
            'gone' => 'That work is not on the board any more.',
            'already_working' => 'You are already doing something for this agent. One job at a time.',
            'not_welcome' => 'They will not deal with you yet.',
            'not_done' => 'Not finished. Come back when it is.',
            'expired' => 'That job ran out of time.',
        ],

        'accepted' => 'Taken. You have :time.',
        'declined' => 'Turned down. The board turns over on the hour.',
        'abandoned' => 'You walked away from it. It cost you standing.',
        'completed' => 'Done. :creds ¤ and their goodwill.',
    ],

    'achievements' => [
        'title' => 'Achievements',
        'lede' => 'Gotta catch \'em all.',

        // The two headers. The design only names the first — but a list with
        // no header above it reads as a continuation of the one before it.
        'gained' => 'Achievements gained',
        'remaining' => 'Still to earn',

        // The name is still one template composed from the id; the
        // DESCRIPTION is already a map keyed by that same id, because there is
        // nothing generic to say about what any of them took. When the names
        // are written they become a map beside it, in the same shape.
        //
        // KEEP EACH ONE UNDER 255 CHARACTERS. Nothing here is a column, but a
        // description that will not fit one is a description that has to be
        // rewritten the day this becomes a table — AchievementTest pins it,
        // and pins that every achievement in the catalogue has one at all.
        'name' => 'ACHIEVEMENT_:id',

        'description' => [
            1 => 'A million creds extracted from runs.',
            2 => 'Ten thousand creds drained using siphons.',
            3 => 'A routing chain at its full length: ten relays between you and whoever is looking.',
            4 => 'A hundred systems burned.',
            5 => 'A hundred pieces of ICE defeated.',
            6 => 'Flatlined. The first one is the one you remember.',
            7 => 'Flatlined a hundred times, and still jacking in.',
            8 => 'Twenty skills trained to the level cap.',
            9 => 'Fifty skills trained to the level cap.',
            10 => 'A hundred systems fully scanned.',
            11 => 'A thousand systems fully scanned.',
            12 => 'A thousand positions of the grid explored.',
            13 => 'Ten thousand positions of the grid explored.',
        ],

        'none' => 'Nothing yet. Everything below is still out there.',
        'complete' => 'Everything there is to earn has been earned.',
    ],

    'images' => [
        'enlarge' => 'View :subject full size',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cyberspace
    |--------------------------------------------------------------------------
    |
    | The grid, and what a player is told about a place on it — including the
    | SHAPE of a machine's name, though none of its words. A generated system
    | is named for its type and its owner: the type's word is here, the owner's
    | is a row in `entities`, and the two patterns below are all that is left to
    | say. An AUTHORED name — the portal's, and one day an event system's —
    | still lives on the row it belongs to.
    |
    | Jack in and jack out mean cyberspace and nothing else. Signing into the
    | game is auth.login / auth.logout, and a player must never have to work out
    | which of the two a button means.
    |
    */
    'cyberspace' => [
        'types' => [
            'portal' => 'Portal',
            'silo' => 'Data silo',
            'compute' => 'Compute node',
            'relay' => 'Network relay',
            // Not a machine you break into: somebody's front desk.
            'exchange' => 'Exchange',
        ],

        /*
        | How a machine's name is spelled: "Data silo - Anansi Data Weaving
        | 041". The type, who runs it, and the serial that tells it from the
        | other machines that entity runs.
        |
        | TWO PATTERNS RATHER THAN ONE, because a system with no owner has to
        | read as a name and not as a sentence with a hole in it. The serial is
        | separate again for the same reason: an authored name may carry one
        | without being composed at all.
        */
        'name' => [
            'owned' => ':type - :owner',
            'designated' => ':name :designation',
        ],

        /*
        | The one authored system on the grid, and the only one whose name is
        | written onto its row: everything else is composed from the pair
        | above. This is the source of the words, not what anything reads at
        | display time.
        */
        'portal' => [
            'name' => 'Cyberspace Portal',
            'flavor' => 'Where everyone arrives. This system is .',
        ],

        'empty' => 'Empty space',
        'unknown' => 'Unexplored',

        /*
        | What a grade is CALLED, once something has earned the right to show
        | it. A scan is the only thing that will; nothing on the map may say
        | any of these, because the number is what the scan is for.
        */
        'grades' => [
            1 => 'Unsecured',
            2 => 'Domestic',
            3 => 'Commercial',
            4 => 'Hardened',
            5 => 'Corporate',
            6 => 'Restricted',
            7 => 'Black',
            8 => 'Sovereign',
            9 => 'Apex',
        ],

        /*
        | Why a player did not end up where they meant to.
        |
        | Read through App\Cyberspace\TravelRefusal, one per case. Every one
        | of these is an ordinary thing to try with the screen in front of
        | you, so they are worded as an answer rather than as an error — and
        | none of them says "jack in" or "jack out" about signing into the
        | game, which is auth.login / auth.logout and a different act.
        */
        /*
        | Thrown out because the deck stopped running, which is the one way of
        | leaving cyberspace the player did not ask for.
        |
        | It says WHY before it says what it cost: the damage is the smaller
        | half of the news, and a player who reads only the first clause has
        | still been told the thing they have to act on.
        */
        'dumped' => [
            'zero' => 'Your deck stopped running. You are out of cyberspace.',
            'one' => 'Your deck stopped running and dumped you out of cyberspace. :count point of Synaptic Integrity.',
            'many' => 'Your deck stopped running and dumped you out of cyberspace. :count points of Synaptic Integrity.',
        ],

        /*
        | Scanning: finding out what a machine is before deciding what to do
        | about it.
        |
        | The refusals say what to do next wherever there is something to do.
        | A read goes round by itself until it has the picture, so none of them
        | is ever "press it again".
        */
        'scan' => [
            'action' => 'Scan',
            'not_jacked_in' => 'You are not in cyberspace.',
            'nothing_there' => 'There is nothing here to scan.',
            'not_scannable' => 'The portal returns nothing. There is nothing here to scan.',
            'no_analyser' => 'You have no scanner running. Start one on the deck you are carrying.',
            'busy' => 'Your scanner is still reading this system.',
            'all_busy' => 'Every scanner you have running is reading something else. Start another instance to read two systems simultaneously.',
            'begun' => 'Scanning. The instrument will have something in :when.',
            'reading' => 'Scanning',
            'ready_in' => 'Answer in :when',
            'gone' => 'The system stopped answering before the scan finished.',
            'voided' => 'Your scanner stopped before the scan finished. That pass is gone.',
            'lost' => [
                'zero' => '',
                'one' => 'A scan was lost: the scanner stopped before it finished.',
                'many' => ':count scans were lost: their scanners stopped before they finished.',
            ],
            'duration' => 'A pass here takes about :when',
            'already_known' => 'This system is already fully scanned.',
            'found' => [
                'zero' => 'Nothing new. Probe unable to reveal anything new.',
                'one' => 'One thing more about this system.',
                'many' => ':count things more about this system.',
            ],
            'grade' => 'Grade',
            'os' => 'Operating system',
            'unknown' => 'Unscanned',
            'expires' => 'Goes stale :when',
            'chance' => 'About :percent% to scan something',
        ],

        /*
        | What a machine is holding. Nothing takes any of it yet — extraction
        | is the run — so these are the words a node's contents will be listed
        | with rather than anything a player reads today.
        */
        /*
        | What a scan asks about a machine. Everything CYBERSPACE.md says a
        | scan catalogues is here, including the four nothing can read yet —
        | see App\Cyberspace\ScanFact for why they are listed rather than
        | hidden.
        */
        /*
        | The run itself. A screen a player is LOCKED into while it lasts, so
        | every word here is read by somebody who cannot go anywhere else.
        */
        'run' => [
            'title' => 'Intrusion',
            'heading' => 'In the system',
            'turn' => 'Turn :turn',
            'begin' => 'Begin run',
            'locked' => 'You are in a run. It does not stop because you looked away.',
            'egress' => 'Egress',
            'egress_hint' => 'Leaving is always possible and never certain.',
            /*
            | THE OTHER HALF OF THE SILENT FAILURE. A core action that fails
            | says one thing for three causes and must never name the operating
            | system — so the odds are shown BEFORE the turn is spent, and a
            | streak of nothing becomes a number the player was told rather
            | than a bug they have to guess at.
            |
            | It says CORE and never OS. What is behind the number is the
            | machine's own operating system and the runner's level in it; what
            | the player is owed is the chance, not the language.
            */
            'core_chance' => 'Core access :chance%.',
            'core_guarded' => 'The ICE is awake and guarding the core. Break it before you can extract, install or burn — or egress and abort.',
            'core_hint' => 'A failed attempt spends the turn.',

            /*
            | GOING IN. Everything decided here is decided for good: the
            | purpose is fixed at the door, a backdoor's kind is permanent once
            | it lands, and the route is snapshotted the moment the run starts.
            |
            | The chain is SHOWN and not chosen — see App\Cyberspace\ChainBuilder
            | for why there is nothing to pick.
            */
            'setup' => [
                'heading' => 'Making a run',
                'title' => 'Before you go in',
                'open' => 'Plan a run',
                'purpose' => 'End goal of run',
                'kind' => 'What to leave behind',
                'chain' => 'Routed through',
                'chain_none' => 'Nothing. A trace comes straight at you.',
                'chain_untrained' => 'You cannot route a run. Routing is the skill for it.',
                'chain_cost' => 'A trace has to come through all of these. If the trace completes, it burns every one of them.',
                'hop' => 'Grade :grade',
                'begin' => 'Begin run',

                /*
                | WHAT IS KNOWN AND WHAT IS NOT. The second half is the point:
                | a machine nobody has read is a machine whose guard, tracer
                | and operating system are all surprises, and the screen that
                | commits to a run should say so plainly rather than leaving a
                | blank space to be read as nothing there.
                */
                'known' => 'What you know',
                'unknown' => 'Unknown',
                'unread' => 'This system has not been scanned. Everything in it will be a surprise.',
                'partial' => 'Your file on this system is incomplete.',
                'complete' => 'Your file is complete.',

                // The deck, and the two things about it that end runs.
                'deck' => 'Your deck',
                'deck_missing' => 'You are carrying no deck.',
                'not_ready' => 'This deck does not meet its own requirements. It will not take you in.',
                'thermal_risk' => 'Running hot: :parts will not take much more.',
                'thermal_over' => 'Past its limit: :parts are being cooked right now.',
                'thermal_safe' => 'Thermals are fine.',

                'running' => 'Running',
                'running_none' => 'Nothing is running. You cannot make a run, and you cannot scan.',
                'no_icebreaker' => 'You hold no icebreaker. If something wakes up, you cannot fight it.',

                // Run-only software, started by the run and by nothing else.
                'loadout' => 'Loadout',
                'loadout_icebreaker' => 'Icebreaker — one at a time',
                'loadout_other' => 'Other run-only software',
                'loadout_none' => 'None',
                'loadout_hint' => 'Started as you go in and stopped when you leave. It cannot be changed once you are inside. Your last loadout is chosen for you.',

                /*
                | THE INTENTIONS. Each is a separate button, because they are
                | not settings of one act — choosing
                | one decides what every turn afterwards can do, and a backdoor
                | additionally decides a permanent property of the machine.
                */
                'intent' => 'What you came to do',
                'intent_extraction' => 'Extract data',
                'intent_siphon' => 'Install a siphon',
                'intent_routing' => 'Put it in your routing chain',
                'intent_burn' => 'Burn it',
                'intent_extraction_hint' => 'Clean, it leaves no lockdown behind. Caught, it locks down like anything else.',
                'intent_siphon_hint' => 'Leeching a little every night, from a system with something worth taking.',
                'intent_routing_hint' => 'A hop in the chain a trace has to traverse. Worth holding on a system that is otherwise worth nothing.',
                'intent_burn_hint' => 'Complete system wipe. No haul, no way back.',
                'held' => 'You already hold a backdoor into this one. Security systems bypassed when you connect.',
                'held_kind' => 'It is a :kind, and that cannot be changed.',
            ],
            // The odds, quoted on the button. The same number the roll uses —
            // `Simulator::egressChance()` answers both, which is why it is
            // public and separate from the roll.
            'egress_chance' => 'Egress :chance%',
            'egress_snared' => 'Coils of machine code grab a hold of your avatar, and prevents you from egressing.',

            /*
            | THE SCORE, AND WHY IT IS WHAT IT IS.
            |
            | "Evasion" is the capability; "Egress" is the act and the roll it
            | takes. Two words for two things, which is why the panel and the
            | button do not say the same one.
            |
            | A number that collapses without saying why is indistinguishable
            | from a bug, so the card shows the pool, then what modified it,
            | then what is opposing it — the three layers in the order they
            | happen.
            */
            'evasion' => 'Evasion',
            'evasion_explain' => 'How this is worked out',
            'evasion_pool' => 'The pool',
            'evasion_pool_sum' => ':sum over :divisor',
            'evasion_modifiers' => 'What moves it',
            'evasion_unmodified' => 'Nothing you carry moves it.',
            'evasion_against' => 'What you are up against',
            'evasion_grade' => 'Grade :grade',
            'evasion_ice' => 'ICE activated, level :level',
            'evasion_ice_none' => 'Nothing activated',
            'evasion_difficulty' => 'Difficulty',
            'evasion_snare' => 'Ensnare :level',
            'evasion_snare_cost' => ':points points of chance',
            'evasion_result' => 'Chance of getting out',
            'evasion_floor' => 'No attempt is ever hopeless, or ever certain.',
            'action' => [
                'attack' => 'Cut',
                'extract' => 'Extract',
                // Same button, same RunAction — only the label changes while
                // runs.extracting_qb is set, so a player is never asked to
                // guess that pressing Extract again is what continues a pull
                // already under way.
                'extract_continue' => 'Continue pull',
                // Names the kind, because it is permanent once it lands and
                // the player chose it a screen ago.
                'install' => 'Install :kind',
                'install_done' => ':kind installed',
                // The one action that ends the run by succeeding, so it says
                // what it does rather than what it is aimed at.
                'burn' => 'Burn it',
                'hold' => 'Hold',
                'egress' => 'Egress',
            ],

            /*
            | THE LOG IS THE ACCOUNT, and a run is something a player watches
            | happen — so every turn says what it did in the order it did it.
            |
            | `attack_fails` covers three different failures on purpose: no
            | icebreaker running, an operating system the runner cannot speak,
            | and nothing awake to cut at. A player is meant to work out which
            | from a pattern, and the one that is a GATE must never announce
            | itself — a refusal naming the operating system would hand over a
            | scan fact for free.
            */
            'log' => [
                'attack' => 'Your icebreaker cuts for :damage.',
                'attack_fails' => 'Your pass finds nothing to cut.',
                'hold' => 'You wait, and the system waits with you.',
                'egress' => 'You egress.',
                // HELD, and one line for both reasons — the machine out-rolled
                // them, or something in it has hold of them. Which it was is
                // already on the screen in the chance itself.
                'egress_held' => 'You reach for the way out and the system does not let go.',
                'ice_broken' => 'The ICE comes apart.',
                'ice_hits' => 'It gets through for :damage.',
                'ice_misses' => 'It reaches for you and misses.',
                'ice_silent' => 'Whatever was fighting you is no longer there.',
                'traced' => 'The trace reaches you. Somebody knows who was in here.',
                'trace_held' => 'The route holds it up another turn.',
                'threshold' => 'You hit your set threshold, and pull out.',

                /*
                | ARRIVING, and whether anything looked up. Said first and said
                | plainly, because it is the single most consequential thing a
                | run will tell a player: an unnoticed run walks past the ICE
                | and the tracer both, and a noticed one is a different night.
                */
                'unnoticed' => 'You are in, and nothing seems to have reacted.',
                // THE BACKDOOR'S HALF OF THE RISK. Said differently from an
                // ordinary quiet arrival, because it is not luck — it is the
                // thing they went to the trouble of installing, working.
                'unnoticed_backdoor' => 'Your backdoor is still open. Nothing will detect you.',
                // INSTALLING. One line for every reason it can fail, the same
                // discipline the core touch already keeps: naming the cause
                // would hand over the operating system for free.
                'install' => 'You leave a way back in. :kind.',
                'install_already' => 'Your backdoor is already here, and it is the one you left.',
                // PLANTING. Says whose door it is, because that is the whole
                // difference: the runner leaves with the standing and without
                // a way back in.
                'planted' => 'Their door is in. It answers to them, not to you.',
                // Awake ICE blocks every core action until it is broken.
                'ice_guards' => 'The ICE stands between you and the core. Break it, or get out.',
                'install_fails' => 'You reach for the core and find nothing you can use.',
                /*
                | BURNING. The machine is named because it is the last thing
                | anybody will be able to call it — the address goes out of
                | existence with it — and `burn_fails` keeps the same silence
                | the other two do about which gate stopped you.
                */
                'burn' => ':name comes apart under you. There will be nothing here.',
                'burn_fails' => 'You reach for the core and it is not there to break.',
                'noticed' => 'Something detects you on the way in.',
                'noticed_ice' => 'Something detects you on the way in, and the ICE activates.',

                /*
                | THE SECOND CHANCE, ON TOUCHING THE CORE. The same event as
                | arriving — something notices — but a different moment, so it
                | gets its own words: this is what a machine says when it was
                | quiet on the way in and then felt the extraction.
                */
                'core_noticed' => 'Something deep withing the core of the system notices, this time.',
                'core_noticed_ice' => 'Something deep withing the core of the system notices, this time, and the ICE activates.',
                // The IDS catching a pull part-way through, on a later turn.
                'pull_noticed' => 'The IDS catches the transfer.',
                'pull_noticed_ice' => 'The IDS catches the transfer, and the ICE activates.',

                /*
                | WHAT A DEFEAT ACTUALLY COST. Said in the past tense and in
                | numbers, because "Flatline" is a word and "you lost 2 points
                | of your ceiling" is a fact — these are what DownedCause shows
                | on the waiting screen as the reason it was worth losing to.
                */
                'defeat' => [
                    'flatline' => 'Whiteout, static, and your mind goes blank for a while. Flatlined. :points points of maximum Synaptic Integrity permanently gone.',
                    'neural_shock' => 'A torrent of neuroelectrical impulses ravages your body: :points hit points.',
                    'surge' => ':count of your fitted modules burn out.',
                    'erase' => ':count file(s) or program(s) on your deck are wiped.',
                    'skim' => ':creds creds go missing from what you were carrying.',
                ],

                /*
                | TOUCHING THE CORE. Extraction is the second thing the IDS
                | gets a look at, alongside a cut. ONE LINE FOR BOTH CAUSES OF
                | FAILURE, deliberately — the same discipline 'attack_fails'
                | already keeps: an operating system the runner cannot speak
                | and a machine with nothing left to take wear the same
                | sentence, because a line that told them apart would let a
                | player learn which one they were facing by elimination,
                | which is exactly the scan fact the OS gate exists to
                | withhold.
                */
                'extract_fails' => 'Extraction failed, retrying.',
                'extract' => 'You pull :summary out.',

                /*
                | A FILE COSTS TURNS. `transfer_begins` and `transfer_continues`
                | are the countdown a multi-turn pull leaves in the log — every
                | turn it runs goes through the ordinary turn cycle, which is
                | why the log has to say so each time rather than once. When it
                | finishes, 'extract' above says so exactly as it does for a
                | haul that cost nothing to wait for.
                |
                | `transfer_gone` is its own line rather than sharing
                | `extract_fails` — losing a race is an ordinary outcome, not a
                | fact worth hiding the way a scan gate is.
                */
                'transfer_begins' => 'Downloading extracted data: :qb QB queued, at :bandwidth a turn.',
                'transfer_continues' => ':remaining QB left to pull.',
                'transfer_gone' => 'The download finishes, and finds the system already stripped bare.',
                // Past the core, so saying why gives nothing away. What does
                // not fit stays on the system; nothing already taken is lost.
                'no_room' => 'Your deck has no room for the rest. It stays on the system.',

                /*
                | THE PIECES A SUMMARY IS BUILT FROM. Assembled in the
                | simulator rather than as one templated sentence, because
                | what actually came out is zero to three of these and a
                | template cannot skip a clause a placeholder left empty.
                */
                'took' => [
                    'creds' => ':creds creds',
                    'items' => ['one' => 'a piece of hardware', 'many' => ':count pieces of hardware'],
                    'files' => ['one' => 'a file', 'many' => ':count files'],
                ],
            ],

            /*
            | THE TRACE, AND THE ONE QUESTION IT ANSWERS: is it time to leave.
            | The estimate is worked out rather than left to a player to divide
            | in their head while something is hitting them.
            */
            'trace' => 'Trace',
            'trace_none' => 'Nothing is tracing you.',
            'trace_turns' => [
                'one' => 'One turn before the trace catches up to you',
                'many' => ':count turns before the trace catches up to you',
            ],
            'trace_done' => 'The tracer has a fix on you.',
            'trace_you' => 'You',

            /*
            | The two standing instructions, set once and left alone. They ACT
            | rather than warn, so the copy says what will happen rather than
            | what might.
            */
            'thresholds' => [
                'heading' => 'When to pull out',
                'hint' => 'Set once. A run that crosses either of these threshold will attempt to egress on its own, whatever you were doing.',
                'trace' => 'Egress when the trace passes',
                'si' => 'Egress when Synaptic Integrity falls below',
                'off' => 'Zero turns one off.',
                'save' => 'Save',
                'saved' => 'Saved.',
            ],

            'ice' => 'ICE',
            'ice_none' => 'No active ICE is engaged.',

            /* THE COUNTDOWN A PULL LEAVES ON SCREEN, read off runs.extracting_qb
               directly rather than composed in the simulator — it is a status,
               not an account of what happened, and every other status line on
               this screen (the trace, the fight) is read the same way. */
            'transferring' => 'Downloading data — :qb QB left.',

            'log_heading' => 'What happened',

            'purpose' => [
                'extraction' => 'Extraction',
                'backdoor' => 'Backdoor',
                'burn' => 'Burn',
            ],

            'purpose_hint' => [
                'extraction' => 'Pilfer sensitive data from this system. Often a low risk operation.',
                'backdoor' => 'Install a backdoor, to assert covert control over this system. If successful, will erase all logs to make it seem like this never happened.',
                'burn' => 'Destroy the system. A very hostile act with no subtlety.',
            ],

            'refused' => [
                'not_jacked_in' => 'You are not jacked in.',
                'nothing_there' => 'There is nothing here to break into.',
                'not_intrudable' => 'The portal is not a system you can make an intrusion into.',
                'deck_not_ready' => 'Your deck does not meet its own requirements. Fix it before you go in.',
                'already_running' => 'You are already in a run.',
                'hunted' => 'Somebody is on you. Deal with that first.',
                'program_refused' => 'Something on your loadout would not start.',
                // Said plainly: a lockdown is a consequence of something
                // somebody did, not a scan fact being given away. And it is
                // worth knowing the system will not be there afterwards.
                'locked_down' => 'This system is locked down. System purging is in progress, and this system will relocate when it comes back online.',
            ],

            'ended' => [
                'egress' => 'You egressed the run.',
                'defeat' => 'You were defeated.',
                'burnout' => 'Your deck cooked a module and unsafely dropped you out of cyberspace.',
                // WHAT ACTUALLY HAPPENED, because a player thrown out of a run
                // they were winning, by something they could not see, with no
                // account of it, has been handed a bug rather than a
                // consequence. Until a notification centre exists, the screen
                // they land on IS the notification — which the run lock
                // guarantees they land on.
                'cancelled' => 'The system went into lockdown while you were inside. The run is over, but nothing you had already taken is lost.',
                'completed' => 'Done.',
            ],
        ],

        'facts' => [
            'grade' => 'Grade',
            'os' => 'Operating system',
            'ids' => 'Intrusion detection',
            'ice' => 'ICE',
            'tracer' => 'Tracer',
            'cloaked' => 'Cloaked systems',
        ],

        /*
        | The reading itself, as a screen you watch rather than a line of
        | status text. A pass takes minutes, so what a player wants while it
        | runs is: what am I pointed at, what with, how far in, and what has it
        | told me so far.
        */
        'reading' => [
            'title' => 'Scanning a system',
            'open' => 'Show the scan',
            'instrument' => 'Instrument',
            'target' => 'Target',
            'pass_of' => 'Pass :pass',
            'idle' => 'Idle. The file is what it is until it goes stale.',
            /*
            | NOT FOUND OUT, and it wears the same box as `not_yet` on purpose.
            | The two are different facts — nobody can read this yet, versus
            | you have not read it yet — but both are the absence of an answer,
            | and a screen that drew them differently would be asking the
            | player to tell two blanks apart.
            */
            'unread' => 'UNKNOWN',
            'not_yet' => 'NOT YET',
            /*
            | A FINDING, NOT AN ABSENCE. A scan that comes back with nothing on
            | a machine's defences has told the player the most valuable thing
            | it can tell them, and the screen must not render that as though
            | the question went unanswered. `unread` above is the other state.
            */
            'none' => 'None',
            'tracer' => ':detection, tracing :rate% a turn',

            /*
            | The guard and the reprisal, read together. A machine whose owner
            | answers for it is a different proposition from one that does not,
            | and the word is the whole of what the player needs — the mechanic
            | behind it does not exist yet.
            */
            'ice_bounty' => ':name · Bounty',
            // A finished read is a file, so the screen names it. The name is
            // composed from the address, which is the only part of a read that
            // is both stable and meaningless — see `filename`.
            'complete' => 'Scan complete. Data saved to :file',
            'filename' => ':address.scn',

            /*
            | THE TWO WAYS OUT OF A READ, and neither of them is "close".
            |
            | A scan is a background process: leaving the screen does not stop
            | it, and a button that said Close would be describing the window
            | rather than the thing in it. So one button says what carries on
            | and the other says what stops — and the screen is reachable again
            | from the running processes in the bar, which is where a
            | background process lives once you have walked away from it.
            */
            'background' => 'Run in background',
            // Once the file is whole there is nothing to abandon and nothing to
            // leave running, so both those buttons go — and what stands in
            // their place is the one thing left to decide about a finished read.
            'close' => 'Close window',
            'abandon' => 'Abandon scan',

            /*
            | EXPORTING, AND WHY IT IS A DECISION RATHER THAN SOMETHING THAT
            | HAPPENS. A file is the only form of a read anybody else can buy,
            | and making one spends the read: the machine stops being scanned
            | on your own map the moment you have something to sell. Saying so
            | on the button is the whole warning — there is no confirmation,
            | because what it costs is written on it.
            */
            'export' => 'Save to stash',
            'export_hint' => 'Files this scan into your data stash. It leaves your own map — a file IS the read, not a copy of it.',
            'exported' => 'Saved as :file. It is in your data stash, and this machine is no longer scanned.',
            'export_gone' => 'That file is gone. It was spent, or it went stale.',
            'export_incomplete' => 'An incomplete file is not intel. Finish the read first.',
            'export_no_storage' => 'Your deck has no room for the file. Make room in its storage first.',

            /*
            | READING ONE IN, which is the other half and the reason anybody
            | buys a file. It spends the file for exporting's reason: one read
            | is one object, and a copy that could be read AND sold on would
            | let a single pass supply a district.
            */
            'import' => 'Read the file',
            'imported' => ':system is on your map. The file is spent.',
            'import_not_a_file' => 'There is nothing to read in that.',
            'import_stale' => 'That file is stale. Nobody can get anything out of it now.',
            'import_gone' => 'Whatever that file describes is not there any more.',
            'import_already_known' => 'You already know everything in it, and your own file is still good.',
            'abandoned' => 'Scan abandoned. The pass it had begun is spent.',
            'abandon_nothing' => 'Nothing is scanning that system.',
            'running_label' => 'Running scan',

            /*
            | NO FILE AT THIS ADDRESS, which is not the same as a read that was
            | lost and must not borrow its sentence. It means the file was
            | spent, went stale, or was never started — nothing has gone wrong
            | with anybody's scanner, and saying one did sends a player to
            | inspect a deck that is working.
            */
            'no_file' => 'No scan data for this address. It was spent, or it went stale.',

            /*
            | An address is the one thing on these screens somebody wants OUT
            | of the game — traded, pasted into a message, kept in a note — so
            | every one of them is a click away from the clipboard. Harmless
            | by construction: an address carries no position, which is the
            | same reason a system bookmark has no gate on it.
            */
            'copy' => 'Copy this address',
            'copied' => 'Address copied.',
            'copy_failed' => 'Could not reach the clipboard. Select it by hand.',

            /*
            | Whether the reader can operate what they found. An operating
            | system is a GATE before it is a fact — the skill at level 1, or
            | nothing you do to the machine works at all — so the scan says
            | which side of it you are on rather than leaving the player to go
            | and check the tree.
            */
            'os_known' => 'You have some skill in dealing with this operating system.',
            'os_unknown' => 'You do not have the skills to use this operating system.',

            'position' => 'Position',
        ],

        'contents' => [
            'creds' => 'Creds',
            'item' => 'Hardware',
            'data' => 'Data',
        ],

        /*
        | Files have names because a thing with a name is a thing you remember
        | leaving behind, and what a player decides about a file is whether it
        | is worth the turns to carry out.
        |
        | A word list rather than rows: there is no catalogue of files and
        | there should not be one. Every file is generated, and its name is the
        | only part of it that is not a number.
        */
        'files' => [
            'fallback' => 'Unlabelled data',

            /*
            | Names by the KIND of machine the file came off, because that is
            | most of what makes a silo feel like a silo. A data silo keeps the
            | records a company is obliged to keep; a compute node keeps what
            | it was working on; a relay keeps what passed through it.
            |
            | A word list rather than rows: there is no catalogue of files and
            | there should not be one. The name is the only part of a generated
            | file that is not a number.
            */
            'words' => [
                'silo' => [
                    'Payroll ledger', 'Personnel files', 'Audit trail', 'Client register',
                    'Board minutes', 'Insurance claims', 'Medical records', 'Tax filings',
                    'Legal hold', 'Asset registry', 'Pension schedules', 'Deed archive',
                    'Debug logs', 'Transaction logs', 'Design brief', 'User database',
                    'Testing data', 'Project notes',
                ],
                'compute' => [
                    'Research data', 'Design notes', 'Simulation output', 'Model weights',
                    'Site surveys', 'Patent drafts', 'Failure analyses', 'Yield reports',
                    'Test harness logs', 'Materials trials', 'Algorithm writeup', 'Patent data',
                ],
                'relay' => [
                    'Access logs', 'Shipping manifests', 'Supplier contracts', 'Incident reports',
                    'Routing tables', 'Handshake captures', 'Courier schedules', 'Freight waybills',
                    'Switch diagnostics', 'Peering agreements', 'Topology graphs',
                ],
            ],
        ],

        /*
        | Cyberspace combat. One screen, one action — see App\Http\Middleware\
        | EnsureHuntIsResolved and App\Http\Controllers\HunterController.
        */
        'hunt' => [
            'title' => 'Hunted',
            'heading' => 'Cyberspace combat',
            'grade' => 'Grade :grade',
            'locked' => 'Somebody is on you. It does not stop because you looked away.',
            'log_heading' => 'The exchange',
            'action' => [
                'fight' => 'Fight',
            ],
            'log' => [
                'hit' => 'You land :damage.',
                'no_program' => 'Nothing is running that can touch it.',
                'miss' => 'It misses.',
                'hunter_hits' => 'It lands :damage.',
                'won' => 'It breaks. +:creds creds, heat down to :heat.',
                'program' => 'You pull :item off it on the way out.',
                'lost' => 'You go down. Heat drops to :heat.',
            ],
        ],

        'travel' => [
            'no_deck' => 'You are carrying no deck. There is nothing to jack in with.',
            'deck_not_ready' => 'That deck will not run. It is short of what it needs.',
            'already_jacked_in' => 'You are already jacked in.',
            'not_jacked_in' => 'You are not jacked in.',
            'not_a_step' => 'That is not one step from where you are standing.',
            'off_the_grid' => 'There is nothing that way. The grid ends here.',
            'not_an_address' => 'An address is eight hexadecimal characters. That is not one.',
            'not_yours' => 'That bookmark is not yours.',
            // Not a failure. The system that wore it has moved or burned, and
            // an address is a fact about right now rather than a name.
            'no_such_address' => 'Nothing answers to that address any more.',
            'hunted' => 'Somebody is on you. There is no leaving cyberspace until this is settled.',
        ],

        /*
        | Saved ways back. Two kinds, and the words keep them apart: a waypoint
        | is a PLACE and always works, a system bookmark is an ADDRESS and can
        | stop answering. A player who cannot tell which they are looking at
        | cannot tell whether "nothing there" is news.
        */
        /*
        | The map screen. Jack in and jack out mean cyberspace here and only
        | here; signing into the game is auth.login / auth.logout.
        */
        'map' => [
            'title' => 'Cyberspace',
            'lede' => 'Your view of the cyberspace matrix.',
            'nav' => 'Cyberspace',

            'out' => 'You are not jacked in.',
            'out_hint' => 'This is the grid as you last saw it. Jack in to walk it.',
            'jack_in' => 'Jack in',
            'jack_out' => 'Jack out',

            'here' => 'You are here',
            'legend' => 'Key',
            'marked' => 'Saved position',
            'backdoored' => 'Your backdoor',
            'rebooting' => 'Rebooting',
            'scanned' => 'Scanned',
            'grade_key' => 'Grade, once scanned',
            'position' => 'Position',
            'swept' => 'Explored',
            // No maximum is shown beside it on purpose: the size of the grid
            // is not a thing the player is told, and a progress bar towards
            // four million would be a promise that exploration ends.
            'swept_count' => ':count positions',

            'address' => 'Address',
            'jump' => 'Jump',
            'jump_hint' => 'Eight hexadecimal characters. Anyone can give you one.',

            'empty' => 'Empty space',
            'unswept' => 'Unexplored',
            // Not "unknown" or "undiscovered": the point is that it was not
            // here last time, which is a statement about the world changing
            // rather than about the player's ignorance.
            'new' => 'New here',
            'void' => 'Beyond the grid',
            'step' => 'Step here',
            'legend' => 'Legend',
            'keys' => 'Arrow keys move; the numpad moves in all eight directions.',
        ],

        'bookmarks' => [
            'heading' => 'Bookmarks',
            'none' => 'Nothing saved yet. Mark a position you want to come back to.',
            'mark_here' => 'Mark this position',
            'mark_address' => 'Save an address',
            'label' => 'Name',
            'label_hint' => 'Optional.',
            'recall' => 'Go',
            'forget' => 'Forget',
            'dead' => 'This is a dead address',
            'save' => 'Save',

            'kind' => [
                'waypoint' => 'Waypoint',
                'system' => 'System',
            ],

            'refused' => [
                'already_saved' => 'You have already saved that one.',
                'off_the_grid' => 'There is nothing there. The grid ends before it.',
                // The gate that paces exploration, said as a fact about the
                // player rather than as a rule they broke.
                'never_swept' => 'You have not been there. Walk it before you mark it.',
                'not_an_address' => 'An address is eight hexadecimal characters. That is not one.',
                'not_yours' => 'That bookmark is not yours.',
            ],
        ],

        /*
        | What the screen says after something happened.
        |
        | Walking says nothing at all unless it revealed something: a banner on
        | every step would be noise the moment walking becomes the thing you do
        | most. The two exceptions are the two things a player cannot see for
        | themselves - that the ground was new, and that something they
        | remembered has gone.
        */
        'status' => [
            'jacked_in' => 'You are in. The portal hums around you.',
            'jacked_out' => 'You are jacked out.',
            'saved' => 'Saved.',
            'forgotten' => 'Forgotten.',

            'swept' => [
                'zero' => '',
                'one' => ':count new position explored.',
                'many' => ':count new positions explored.',
            ],

            // The world having moved on while they were away, which is the
            // whole of why the map is a memory rather than a feed.
            'gone' => [
                'zero' => '',
                'one' => 'Something you remembered is no longer there.',
                'many' => ':count things you remembered are no longer there.',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Damage
    |--------------------------------------------------------------------------
    |
    | What hurt somebody. One line per App\Stats\DamageOrigin, and they are
    | here rather than on a row because an origin is a fixed vocabulary in the
    | code, like a body region — there is nothing in the database that could
    | carry the word.
    |
    | Nothing shows these yet. They exist because the first thing that explains
    | a hit to a player — a combat log, a breakdown on the sheet — will need to
    | name the cause, and a resolver step that says `readiness_loss` at
    | somebody is not a sentence.
    |
    */
    'damage' => [
        'origins' => [
            'readiness_loss' => 'Deck failure',
            'burnout' => 'Burnout',
            'ice' => 'ICE',
            'run_cancelled' => 'Lockdown',
            'overdose' => 'Overdose',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Implants
    |--------------------------------------------------------------------------
    |
    | What is installed in a body, and where. The regions are a fixed
    | vocabulary rather than rows, because every character has the same body -
    | see App\Implants\BodyRegion.
    |
    */
    'implants' => [
        'title' => 'Implants',
        'lede' => 'Better living through implants.',
        'nav' => 'Implants',

        'regions' => [
            'head' => 'Head',
            'body' => 'Body',
            'arms' => 'Arms',
            'legs' => 'Legs',
            'spine' => 'Spine',
        ],

        'empty_slot' => 'Empty',
        'capacity' => ':used of :total',
        'install' => 'Install',
        'remove' => 'Remove',
        'installed' => ':implant is installed.',
        'removed' => ':implant is back in your stash.',
        'none_to_install' => 'You are carrying no implants that would go here.',
        'nothing_installed' => 'Nothing is installed. Implants go in from your stash.',
        'total' => 'Slots',

        'refused' => [
            'not_owned' => 'That is not yours.',
            'not_an_implant' => 'That does not go in a body.',
            'already_installed' => 'It is already installed.',
            'not_installed' => 'That one is not installed.',
            'no_room' => 'There is no room left there. Take something out first.',
            // Refused rather than quietly pulled out: moving somebody's
            // possessions without saying so is the thing to avoid.
            'not_to_hand' => 'That one is packed away. Take it out first.',
        ],
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
        'bounty_heading' => 'Bounty',
        'bounty_none' => 'Nobody is paying for you.',
        'bounty_hint' => 'Creds on your head. It grows each time word goes out about you.',

        /*
        | THE SHEET ITSELF. Skills, implants and achievements each had a rail
        | item once and are tabs of this window now — they kept their URLs, so
        | the strip is a nav of links rather than a panel switch.
        */
        'title' => 'Character',
        'tabs' => [
            'label' => 'Character sections',
            'character' => 'Character',
            'skills' => 'Skills',
            'implants' => 'Implants',
            'standing' => 'Standing',
            'achievements' => 'Achievements',
        ],

        'attributes_heading' => 'Attributes',
        'attribute_value' => ':attribute :value of :max',
        // The two resources on the sheet, each against its resolved maximum.
        'vitals_heading' => 'Vitals',
        'vitals' => [
            'hitpoints_max' => 'Hitpoints',
            'synaptic_integrity_max' => 'Synaptic Integrity',
        ],
        'vital_value' => ':vital :current of :max',
        'vitals_modified' => 'Maximum changed by',
        'vitals_unmodified' => 'Nothing is changing the maximum.',

        // What is in them right now — one card per family they carry anything
        // for. The line is the overdose one: the dose that takes it past the
        // capacity is the dose that breaks it.
        'doses_heading' => 'What is in you',
        'doses_capacity' => ':used of :capacity',
        'doses_none' => 'Nothing of this in you',
        'doses_at_the_line' => 'One more is too much',
        'doses_wears_off' => 'wears off :when',
        'doses_potency' => ':percent% strength',
        'doses_tolerance' => 'Tolerance',
        'doses_addiction' => 'Addiction',
        'doses_hooked' => 'Hooked',
        'doses_withdrawing' => 'Withdrawal',
        // The one thing on the sheet the game never reads.
        'bio' => 'Bio',
        'bio_hint' => 'Yours, and nothing in the game reads it. Clear the box to take it down.',
        'bio_placeholder' => 'Whatever you want anyone who looks you up to find.',
        'bio_save' => 'Save bio',
        'bio_saved' => 'Bio saved.',

        /*
        | THE FLOOR UNDER EVERY DEFEAT.
        |
        | Read by somebody who has just lost and cannot go anywhere else, so it
        | says what happened rather than commiserating. The wait is not the
        | punishment — what losing cost is whatever the defeat tags carried —
        | and the copy should not pretend otherwise.
        */
        'waiting' => [
            'title' => 'Down',
            'heading' => 'You are on the floor',
            'back_in' => 'Up in :when',
            'up' => 'Get up',
            'up_hint' => 'You come back on one point. Getting up is not healing.',

            'reason' => [
                'defeat' => 'Beaten in a run.',
                'burnout' => 'Your deck cooked a module and threw you through the wall.',
                'readiness' => 'Your deck stopped running while you were inside it.',
                'hunter' => 'Somebody came looking for you, and found you.',
                'unknown' => 'Something finished you.',
            ],

            'on_system' => 'On :system',
            'by_ice' => 'Beaten by :ice',
            'took' => 'Took :damage :resource',
            'tags' => 'What it carried',
            'no_tags' => 'It carried nothing beyond this.',

            'resource' => [
                'current_synaptic_integrity' => 'Synaptic Integrity',
                'current_hitpoints' => 'hit points',
            ],
        ],

        'create' => [
            'title' => 'Create your character',
            'lede' => 'This is who you are on the street. The handle is permanent; spend your points carefully.',
            'submit' => 'Create character',
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
            'balanced' => 'Ready to create',
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
            'meat' => 'Raw bodily resilience. How much punishment the flesh absorbs before it stops doing what you tell it — and the brain is flesh too, sitting behind whatever you have had bolted to it.',
            'motor' => 'Fine control and coordination. Steady hands on a lockpick, a scalpel, or a trigger, while everything around you is going wrong.',
            'senses' => 'Acuity of sight, hearing and whatever you have had installed since. Sets how much signal reaches you before you have to guess.',
            'muscle' => 'Applied force. Lifting, breaking, dragging a body, holding a door shut against the people on the other side.',

            // Mental
            'intellect' => 'Analysis and problem solving. Reading the shape of a system fast enough to act on it while it is still true — and understanding a thing is most of not being unmade by it.',
            'memory' => 'Recall and retention. How much you carry without a deck, and how much you have already survived — a mind asked to take more falls back on what it has seen before.',
            'perception' => 'Noticing what other people miss. The tell, the tail, the one detail in the room that does not belong.',
            'focus' => 'Concentration and will. Holding a thread while the room, the feed and your own chrome all compete for it — and holding on past the point where stopping would be the sensible thing.',
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

            // The two pools. Named so a modifier targeting one can say what it
            // affects on a card — "Evasion" is the capability, and the act it
            // pays for is called egress everywhere a player sees it.
            'egress' => 'Evasion',
            'stealth' => 'Stealth',

            // What skill effects reach, named so a skill's card can say what
            // it changes. Unnamed, an effect renders as a bare amount.
            'scan_duration' => 'scan time',
            'check.systemprofiling' => 'System Profiling checks',
            'check.routing' => 'Routing checks',
            'deck_storage' => 'deck storage',
            'deck_qflops' => 'deck compute',
            'deck_bandwidth' => 'deck bandwidth',
            'deck_thermal_load' => 'deck thermal load',
            'deck_cooling' => 'deck cooling',
            'deck_power_draw' => 'deck power draw',
            'routing_hops' => 'routing hops',
            'program_threads' => 'program threads',
            'coop_slots' => 'co-op slots',
            'synaptic_regen' => 'SI regeneration',

            // Tag sets, named as a whole — see App\Stats\Target::nameKey().
            // The key is the tags in alphabetical order, because a set has no
            // order of its own, and the name is what a person would say: the
            // pair damage+hitpoints is called physical damage, never "damage
            // hitpoints".
            'damage' => 'damage taken',
            'damage+hitpoints' => 'physical damage taken',
            'damage+synaptic_integrity' => 'neural damage taken',
            'check+operating-systems' => 'operating-system access checks',
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
    /*
    | Backdoors: what somebody keeps on a machine they have been inside.
    |
    | The kind is a permanent property of the install and the words have to
    | carry that — "siphon" and "routing" are what they DO, not settings.
    */
    /*
    | The organisations that own machines, hand out missions and keep books on
    | what a runner has done to them. The CATEGORY is a fixed vocabulary read
    | as App\Entities\EntityCategory; what an entity itself is called lives on
    | its row, like every other thing with a row.
    */
    'entities' => [
        'categories' => [
            'corporation' => 'Corporation',
            'government' => 'Government',
            'military' => 'Military',
            'fringe' => 'Fringe',
        ],

        // What a standing figure means in one word. A card shows the band;
        // the number is for the entity's own screen.
        'bands' => [
            'hostile' => 'Hostile',
            'hated' => 'Hated',
            'cold' => 'Cold',
            'neutral' => 'Neutral',
            'known' => 'Known',
            'trusted' => 'Trusted',
            'allied' => 'Allied',
        ],

        // Why a number moved, composed when it is read. Written in the past
        // tense from the runner's side: this is their history, not the
        // entity's minutes.
        'reasons' => [
            'noticed' => 'Seen on their system',
            'raided' => 'Took from their system',
            'backdoor_found' => 'Your door was found',
            'traced' => 'Traced by their system',
            'burned' => 'Burned their system',
            'mission_completed' => 'Work completed',
            'store_spend' => 'Traded with them',
            'decay' => 'Time passing',
        ],

        'standing' => 'Standing',
        'loyalty' => 'Loyalty',
        // On a card, where the band carries the meaning and the figure is
        // beside it for anybody who wants it.
        'standing_with' => ':band with :entity',
    ],

    /*
    | An entity's front desk. Reached by standing on it, and the only thing
    | that happens on one: nothing may be run against, scanned or burned here.
    */
    'exchange' => [
        'title' => 'Missions & Loyalty',
        'agent' => 'Agent',
        'grade' => 'Grade :grade desk',
        'missions' => 'Work on offer',
        'store' => 'Loyalty store',
        'not_built' => 'Not yet',
        'missions_soon' => 'This desk will hand out work against grade :low to :high systems.',
        'store_soon' => 'What this entity sells to the people it owes. Loyalty is earned by working for them.',

        /*
        | THE LOYALTY STORE, which takes one currency and only one. Nothing
        | converts loyalty to creds or back, so a price here is never a number
        | a player can compare to the market's.
        */
        'store_empty' => 'This desk is holding nothing for you today.',
        'store_price' => ':price loyalty',
        'store_buy' => 'Redeem',
        'store_have' => 'You have :loyalty',
        'store_locked' => 'Wants :standing standing',
        'store_bought' => ':item is yours. :price loyalty spent.',
        'store_served' => ':item: done. :price loyalty spent.',
        'store_heat' => 'You carry :heat heat',

        // What an entity DOES for loyalty, as App\Standing\Service.
        'services' => [
            'shed_heat' => [
                'name' => 'Make it go quiet',
                'does' => 'Takes :amount heat off you. Word already out stays out.',
            ],
        ],

        'store_refused' => [
            'not_welcome' => 'They will not deal with you.',
            'not_for_you' => 'They keep that one back for people they trust.',
            'cannot_afford' => 'You have not earned that much from them.',
            'nothing_to_do' => 'There is nothing for them to clean up.',
            'gone' => 'That is not on the shelf.',
            'no_storage' => 'Your deck has no room for it. Make room in its storage first.',
        ],
        'unwelcome' => 'They will not deal with you.',
        'unwelcome_hint' => 'This desk wants :band standing — :standing — before it opens a file for somebody.',
        // On the map, where the desk is one step away.
        'visit' => 'Talk to the desk',
    ],

    'backdoor' => [
        'kind' => [
            'siphon' => 'Siphon',
            'routing' => 'Routing hop',
        ],
        'hint' => [
            'siphon' => 'A little every night, from a machine with something worth taking.',
            'routing' => 'A hop in the chain a trace has to walk. Worth holding on a machine worth nothing.',
        ],
    ],

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

    /*
    | The skills screen: what this character knows, what is training, and what
    | is lined up behind it.
    |
    | It was `skills` once, and that block described the CATALOGUE — every
    | skill in the game, the same words for everybody, signed in or not. The
    | catalogue is gone and the editor is where a list of every skill lives
    | now, so every key here is about one character.
    */
    'training' => [

        'title' => 'Skills',
        'lede' => 'What you know, what you are training, and what is lined up behind it. A skill is learned by spending its imprint from the stash, and trained by queuing it here.',

        'mine' => 'Your skills',
        'none' => 'Nothing injected yet. A skill is learned by spending its imprint from the stash.',
        'count' => ['zero' => 'none', 'one' => ':count skill', 'many' => ':count skills'],

        // What is training right now. The dot beside it is the kit's own
        // `.dot--live`, which is what ember is rationed for.
        'now' => 'Training',
        'idle' => 'Nothing is training. Queue a skill and it starts on the next minute.',
        'live' => 'Training now',
        'toward' => 'toward level :level',
        'banked' => ':points of :ceiling SP',
        'rate' => ':rate SP/min',
        'finishes' => 'Done :when',
        'stalled' => 'Not earning: every attribute this skill trains on resolves to zero.',

        'queue' => 'Queue',
        'queue_hint' => 'Up to :limit, trained in order. A skill goes in at the next level up — press it again for the one after that.',
        'queue_empty' => 'Nothing queued.',
        'position' => ':position.',
        'target' => 'Level :level',
        'add' => 'Queue it',
        'pick' => 'Skill to train',
        'remove' => 'Take out',
        'full' => 'The queue is full. Take something out to line up anything else.',
        'nothing_queueable' => 'Everything you have injected is either at level :max or already queued.',

        // Shown as ':skill → level :level' in the picker, so one option says
        // both what it is and what pressing it buys.
        'queueable' => ':skill → :level',

        // The two the catalogue used to own, kept because the list of what a
        // character knows says the same things about a row that the tree did.
        'governed_by' => ':primary / :secondary',
        // What the whole queue costs, at the rate it earns this instant.
        'queue_total' => 'The whole queue: :duration',
        'queue_total_stalled' => 'The whole queue cannot be timed while something in it earns nothing.',
        // One entry's own time, beside its target level.
        'entry_stalled' => 'earns nothing',
        // Its points are already banked; it completes when the clock next ticks.
        'entry_done' => 'any moment',

        // <x-skill-detail>: everything about one skill, on one card.
        'card' => [
            'rank' => 'Rank :rank',
            'primary' => 'Primary',
            'secondary' => 'Secondary',
            'requires' => 'Requires',
            'requires_none' => 'Nothing',
            'effects' => 'Effects',
            'effects_none' => 'Knowing it changes nothing on its own.',
            'effect_level' => 'Level :level',
            'book' => 'Imprint',
            'book_owned' => 'Owned',
            'book_not_owned' => 'Not owned',
            'injected' => 'Injected',
            'not_injected' => 'Not injected',
            'training' => 'Training level :level',
        ],
        'level_meter' => 'Level :level of :max · :points SP',

        /*
        | PLANNING BACKWARDS, from a skill somebody wants to the entries that
        | reach it. The queue holds five and a route to a deep skill is
        | routinely longer, so "as far as it got" is the ORDINARY outcome and
        | is written as one — a status rather than an error, naming what landed
        | and what stopped it.
        */
        'plan' => 'Plan a route',
        'plan_hint' => 'Name a level you want and the prerequisites, and the levels in between, are queued in order. The queue still holds :limit, so a long route arrives in instalments.',
        'plan_pick' => 'Skill to reach',
        'plan_level' => 'Level wanted',
        'plan_add' => 'Plan it',
        'plan_option' => 'Level :level',
        'plan_nothing' => 'Nothing to add: :skill is already trained or queued to level :level.',
        'plan_queued' => [
            'zero' => 'Nothing to add.',
            'one' => 'One level queued, on the way to :skill :level.',
            'many' => ':count levels queued, on the way to :skill :level.',
        ],
        'plan_stopped' => ':count of :total queued. Stopped at :skill — :reason',
        'plan_needs_imprint' => ':count of :total queued. :target needs :skill first, and you have not injected :skill — spend its imprint from the stash, then plan again.',

        'queued' => ':skill queued for level :level.',
        'dequeued' => ':skill level :level taken out of the queue.',

        /*
        | Why a skill did not go in. Prerequisites are deliberately not here —
        | a queue is allowed to describe training that cannot happen yet, and
        | the warnings below are how it says so.
        */
        'refused' => [
            'not_injected' => 'You have not injected that skill. Spend its imprint from the stash first.',
            'already_maxed' => 'Every level of that skill is either reached or already queued.',
            'queue_full' => 'The queue is full. Take something out first.',
        ],

        /*
        | What TrainingQueueValidator found. Its sentences are written for a
        | console command and read a little flat on a screen; they are exact,
        | which matters more than the tone while this is the only place a
        | player can see why a queue will stall.
        */
        'problems' => 'This queue will not train as written',
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
        'data_hint' => 'What is on your deck. Software, files, imprints.',

        'empty' => 'Nothing here.',

        /*
        | A REAL FILE, ON A GENERIC ITEM. Every "Encrypted Data Dump" is the
        | same catalogue row; these numbers are the row's own, from
        | App\Cyberspace\ExtractedFile — the same voice hardwareSummary() and
        | programSummary() already speak for a module or a program.
        */
        'files' => [
            'size' => ':qb QB',
            'encrypted_with' => 'sealed with :skill',
            'unencrypted' => 'unsealed',
            'unknown_encryption' => 'an encryption nobody names any more',
            'sealed_value' => 'worth :value sealed',
        ],

        /*
        | A SCAN FILE'S OWN LINE. Every one of them is the same catalogue row
        | and a different machine, so the card reads off the inventory row the
        | way an extracted file's does. The clock is on it because a file's
        | whole worth is that it is still true.
        */
        'scans' => [
            'stale' => 'Stale. Worth nothing to anybody.',
        ],

        // The equipped deck is not in either inventory — it is on you. This is
        // the one place it appears, and the only way through to its board.

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
        'inject_confirm' => 'Injecting :skill destroys the imprint. It cannot be undone.',
        // The same sentence with no skill named, for a list of imprints rather
        // than one: the contents of a card say it once, not once each.
        'inject_confirm_any' => 'Injecting an imprint destroys it. It cannot be undone.',
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
            'no_storage' => 'Your deck has no room for it. Make room in its storage before you take it out.',
        ],

        'refused' => [
            'not_an_imprint' => 'That is not something you can inject.',
            'not_owned' => 'You are not carrying that.',
            'already_known' => 'You already know that skill. The imprint is no use to you.',
            'skill_missing' => 'That imprint carries a skill that no longer exists.',
        ],
    ],

    /*
    | Item NAMES and flavour are columns on the items table, not keys here.
    | What is left is the vocabulary shared across every item.
    */
    'items' => [
        /*
        | THE SPEC LINE, shared by every kind of item that has one.
        |
        | A deck's sockets, a module's measures, a program's three costs and a
        | file's numbers are all one line of facts with something between them,
        | so there is one separator and it lives here. It used to live under
        | `decks` while three readers asked for it under `items`, which is the
        | key Text::get() returned verbatim: cards read
        | "4 QFLOPSITEMS.SUMMARY.SEPARATOR3 QB" until somebody looked.
        */
        'summary' => [
            // A middle dot, so a spec line reads as one line rather than a list.
            'separator' => ' · ',
        ],

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

        // EIDETIC IMPRINT in full, "imprint" everywhere it is said twice in
        // one breath. The item has no name of its own: it composes from the
        // skill it carries, so renaming the format renames every one of them.
        'imprint' => [
            'name' => 'Eidetic Imprint: :skill',
            'flavor' => 'Compressed instruction set. One read, then it is gone.',
        ],
    ],

    // The front page: NOTICE.md, and the two places there are to go.
    'home' => [
        // The accordion's summary when NOTICE.md does not open with a heading.
        'notice' => 'Notice',

        // PATCHNOTES.md in a modal. `all` is the version link's copy, `new`
        // the one that opens by itself with what a player has not read.
        'patch_notes' => [
            'all' => 'Patch notes',
            'new' => 'What changed since you were last here',
            'heading' => ':version · :date',
            'open' => 'Read the patch notes',
            'close' => 'Close',
        ],

        // Where the hosting money goes. An ordinary link rather than the
        // vendor's widget: the widget is a third-party script with its own
        // radius and its own magenta, and this page already has a wordmark
        // doing the shouting.
        'support' => 'Become a supporter',

        // What is running, for anybody reporting something odd. `v` is part
        // of the format rather than the value: config/version.php holds the
        // number and nothing else.
        'version' => 'v:version',
        'places' => [
            'city' => 'CITY',
            'cyberspace' => 'CYBERSPACE',
        ],
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
            'storage' => 'Storage',
            'io' => 'IO port',
        ],

        /*
         * What a board node's label says before the variant name. Short on
         * purpose — the prefix has to name the kind without becoming the
         * label. A kind with no variants is already its own name and takes
         * none; see SlotType::boardLabel().
         */
        'prefixes' => [
            'cpu' => 'CPU',
            'ram' => 'RAM',
            'expansion' => 'XP',
            'io' => 'I/O',
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
            'spec' => 'Specs',
            'open' => 'Board',
            'close' => 'Close',
        ],

        // Putting a module in, and taking it back out.
        'fitting' => [
            'fit' => 'Fit',
            'unfit' => 'Take out',
            // The deck lock. Says what to do about it rather than only that
            // it is refused, because there is exactly one thing to do.
            'jacked_in' => 'You are jacked in on that deck. Jack out before taking it apart.',
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
            // The deck's four bars. Short, because a vital's label column is
            // 76px and "Thermal load" overflows it.
            'compute' => 'Compute',
            'memory' => 'Memory',
            // Everything in the data inventory, against what the deck holds.
            'storage' => 'Storage',
            'thermal' => 'Thermal',
            'used' => ':used of :total',
            // One sentence per module, in the thermal bar's title: the ticks
            // are too thin to point at one by one.
            'threshold' => ':module warns at :warning, breaks at :limit',
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
                'storage' => 'Room for your data',
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
            'thermal_load' => ':value TU',
            'cooling' => ':value TU cooling',
            'storage' => ':value QB stored',
        ],

        'summary' => [
            'times' => ':count× :label',
            // The separator lives in `items.summary` — a spec line is a spec
            // line whether it is a deck's sockets, a program's costs or a
            // file's numbers, and one middle dot is one decision.
        ],

        'no_slots' => 'No sockets yet.',
    ],

    /*
    | Merged over the framework's own validation messages by the validator, so
    | only the custom rules belong here.
    */
    'validation' => [
        'modifier_target' => 'nothing in the game is called [:target], so this effect would change nothing.',
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

        /*
        | The modifier editor, shared by every screen that edits a list of
        | them: items, the software on them, skills, and perks when they get
        | one. See App\Admin\ModifierField.
        |
        | The group labels are the first tag of a stat, so a new family of
        | stats lands in a group of its own by having a tag — and the fallback
        | is the tag itself, which is ugly enough to notice and harmless.
        */
        'modifiers' => [
            'legend' => 'Effects',
            'hint' => 'What having this changes. Each row is one modifier: how it applies, by how much, and to what.',
            'empty' => 'No effects. This changes nothing about anybody.',
            'add' => 'Add effect',
            'remove' => 'Remove',
            'op' => 'How',
            'amount' => 'How much',
            'target' => 'What',
            'level' => 'From level',
            'level_hint' => 'The level at which this starts applying. Blank means from level 0.',
            'choose_target' => 'Choose a target',
            'carried' => 'This effect also carries :keys, which are kept as they are.',
            'ops' => [
                'add' => 'Add a flat amount',
                'percentage_of_base' => 'Add a percentage',
                'multiplier' => 'Multiply by',
                'override' => 'Replace with',
            ],
            'groups' => [
                'attribute' => 'Attributes',
                'derived' => 'Vitals',
                'deck' => 'Deck measures',
                'scan' => 'Scanning',
                'skill' => 'Skill levels',
                'check' => 'Skill checks',
                'damage' => 'Damage taken',
                'sets' => 'Groups',
                'other' => 'Other',
            ],
        ],

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
                'name_book' => 'An imprint has no name of its own: it reads from the skill it carries.',
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
                'use_skill_hint' => 'The skill this imprint injects. One imprint per skill.',
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
                'thermal_load' => 'Thermal load',
                'thermal_load_hint' => 'What it makes per turn while running, in thermal units. Zero is fine; a radiator makes none.',
                'storage' => 'Storage',
                'storage_hint' => 'Bulk capacity in quantabits — where files and idle programs live. Not memory.',
                'cooling' => 'Cooling',
                'cooling_hint' => 'What a radiator takes away, in thermal units.',
                'thermal_warning' => 'Warns at',
                'thermal_warning_hint' => 'Accumulated deck load at which this part starts to be at risk. Leave both empty for a part that does not care.',
                'thermal_limit' => 'Destroyed at',
                'thermal_limit_hint' => 'Accumulated deck load at which this part is gone. A hard limit, not a roll. Must not be below the warning.',
                'program_kind_none' => 'Not software',
                'program_kind' => 'Program',
                'program_kind_hint' => 'What the software is for. Leave empty for anything that is not a program. Deck hardware and imprints cannot be one.',
                'drug_family' => 'Drug family',
                'drug_family_none' => 'Not a drug',
                'drug_family_hint' => 'Giving it a family makes the item a drug. The books are kept per family, so this decides which figures a dose moves and which withdrawal it answers.',
                'duration_minutes' => 'Duration (minutes)',
                'duration_minutes_hint' => 'How long one dose is in effect.',
                'addictive' => 'Addictive',
                'tolerance_gain' => 'Tolerance',
                'addictive_hint' => 'How far one dose drives the addiction figure up. Zero is a drug nobody gets hooked on.',
                'lethal_at' => 'Lethal at (excess doses)',
                'lethal_at_hint' => 'Leave empty unless this drug disagrees with its family. Empty asks the family, which is what nearly every drug wants; it does not mean never.',
                'tolerance_gain_hint' => 'How far one dose drives tolerance up. Tolerance cuts what the NEXT dose is worth and widens how many the body holds at once.',
                'qflops_cost' => 'Compute cost',
                'qflops_cost_hint' => 'qFLOPS consumed while it runs, and only while it runs.',
                'memory_cost' => 'Memory cost',
                'memory_cost_hint' => 'Quantabits consumed while it runs.',
                'storage_size' => 'Stored size',
                'storage_size_hint' => 'Quantabits it occupies just by being owned, running or not.',
                'background_process' => 'Runs in the background',
                'background_process_hint' => 'Whether it may run outside an intrusion. A decryptor must; an icebreaker has nothing to break out there.',
                'program_modifiers' => 'What running it does',
                'program_modifiers_hint' => 'Effects the character gets while this is running. Empty is fine — a program that only gates modifies nothing.',
                'multithreaded' => 'Can be run more than once at a time',
                'multithreaded_hint' => 'Whether a character may point two copies at two things at once. How many they may run is their own skill; this is whether it is a question at all.',
                'implant_region' => 'Implant region',
                'implant_region_none' => 'Not an implant',
                'implant_region_hint' => 'Where in a body this goes, and the only thing that makes an item an implant. What it DOES is the effects above; how many fit belongs to the region.',
                // The capacity beside each region, because "how many of these can
                // I have" is the question somebody authoring one is really asking.
                'implant_slots' => ['zero' => 'no slots', 'one' => ':count slot', 'many' => ':count slots'],
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
                'mission' => 'Work',
                'hardware' => 'Deck hardware',
                'software' => 'Software',
                'drug' => 'Drug',
                'implant' => 'Implant',
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

            /*
            | Software, in the listing. A program's KIND is a gate as much as a
            | modifier, so a list that only said "Data" left every program in
            | the game looking alike — and there are seventeen of them now.
            */
            'program' => [
                'filter' => 'Program',
                'any' => 'Any program',
            ],
            'program_summary' => ':qflops qF · :memory QB · :storage QB stored',
            'run_only' => 'run only',

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

            'onboard_storage' => [
                'label' => 'Onboard storage (QB)',
                'hint' => 'What the deck holds before a storage module. Empty uses the default of :fallback QB.',
            ],
            'sockets' => [
                'help' => 'How many of each socket this deck presents. A deck needs somewhere to put a CPU and somewhere to draw power; everything else is optional. Sockets are what a module is fitted into, so the numbers here are the deck.',
                'none' => 'No sockets. This deck cannot be built yet.',
                'total' => ['one' => ':count socket', 'many' => ':count sockets'],
            ],
        ],

        'skills' => [
            'title' => 'Skills',
            'lede' => 'Every skill, and what it requires first. Creating one also creates the imprint that teaches it — there is exactly one imprint per skill, and nothing can be learned without it.',
            'new' => 'New skill',
            'editing' => 'Editing :skill',
            'created' => ':skill created, along with its imprint :book.',
            'updated' => ':skill saved.',
            'deleted' => ':skill deleted, and :rows related rows with it.',
            'empty' => 'No skills match.',
            'search_hint' => 'Slug or title',
            'all_categories' => 'Every category',
            'without_books' => [
                'one' => ':count skill has no imprint.',
                'many' => ':count skills have no imprint.',
            ],

            'fields' => [
                'modifiers' => 'What knowing it grants',
                'modifiers_hint' => 'Effects a character gets for having this skill — not the check it feeds, which exists for everybody. Each row applies from the level you give it.',
                'slug' => 'Slug',
                'slug_hint' => 'Permanent. Lower case, digits and underscores. The imprint is named from it.',
                'slug_locked' => 'Permanent — saved progress references it.',
                'title' => 'Title',
                'title_hint' => 'What a player reads. The imprint reads from it too.',
                'description' => 'Description',
                'category' => 'Category',
                'category_hint' => 'The lane it hangs in on the skill tree. Reuse an existing one to group it.',
                'rank' => 'Rank',
                // The rank as a VALUE rather than as a field label: the
                // select's options and the listing's column each show one.
                'rank_value' => 'Rank :rank',
                'rank_hint' => 'A training-time multiplier, not a cost: rank 2 needs twice the points for the same level.',
                'primary_attribute' => 'Primary attribute',
                'secondary_attribute' => 'Secondary attribute',
                'attribute_hint' => 'Together these decide skill points per minute. They should differ.',
                'base_cost' => 'Cost',
                'base_cost_hint' => 'What the imprint sells for. Kept in step with it on every save.',
                'on_market' => 'Imprint listed on the market',
            ],

            'sections' => [
                'identity' => 'Identity',
                'training' => 'Training',
                'market' => 'Market',
                'mission' => 'Work',
                'prerequisites' => 'Requirements',
                'book' => 'Imprint',
            ],

            'columns' => [
                'slug' => 'Slug',
                'title' => 'Title',
                'category' => 'Category',
                'rank' => 'Rank',
                'requires' => 'Requires',
                'unlocks' => 'Unlocks',
                'book' => 'Imprint',
            ],

            'prerequisites' => [
                'help' => 'What must be trained first. A requirement that closes a loop is refused — nobody could ever learn either skill.',
                'skill' => 'Skill',
                'level' => 'At level',
                'none' => 'No requirements. This skill can be trained from the start.',
            ],

            'book' => [
                'present' => 'Its imprint is :book.',
                'edit' => 'Edit the imprint',
                'missing' => 'This skill has no imprint, so nobody can learn it. It was deleted, or the skill predates the editor.',
                'derived' => 'Cost and listing follow the skill. Tier, rarity and shelf size were set from its rank when the imprint was made, and are the item editor\'s from then on.',
                'yes' => 'Yes',
                'no' => 'Missing',
            ],
        ],

        /*
        | THE LOYALTY SHELVES. What an entity hands over for loyalty, which is
        | content and therefore authored — the roster of entities themselves
        | arrives by migration, like the archetypes and for the same reason.
        */
        'entities' => [
            'title' => 'Entities',
            'lede' => 'Who owns the grid, and what each of them will hand over for loyalty. The roster arrives by migration; what they sell is edited here.',

            'name' => 'Entity',
            'category' => 'Category',
            'interactable' => 'Works with players',
            'scenery' => 'Scenery',
            'offers' => 'On the shelf',
            'edit' => 'Shelf',

            'shelf_title' => ":entity's shelf",
            'shelf_lede' => 'A row is kept by every desk between its two grades, inclusive. One offer per item: widen the row rather than adding a second.',
            'shelf_empty' => 'Nothing on this shelf yet.',

            'item' => 'Item',
            'no_item' => '— none (a service) —',
            'off_market' => '⊘',
            'off_market_hint' => '⊘ marks an item that is not on the market.',
            'service' => 'Or a service',
            'no_service' => '— none (an item) —',
            'service_amount' => 'Amount',
            'service_amount_hint' => 'How much one purchase of the service does. Heat: points taken off.',
            'grades' => 'Desks',
            'grade_from' => 'From',
            'grade_to' => 'To',
            'price' => 'Loyalty',
            'min_standing' => 'Standing',
            'min_standing_hint' => 'On top of the desk\'s own gate. Almost always 0.',
            'add' => 'Put it on the shelf',
            'save' => 'Save',
            'remove' => 'Take it off',

            'without_shelf' => [
                'zero' => '',
                'one' => ':count entity with nothing to sell',
                'many' => ':count entities with nothing to sell',
            ],

            'about' => 'About them',
            'business' => 'What they do',
            'business_hint' => 'One line. Shown wherever the entity is named in passing.',
            'description' => 'The long form',
            'description_hint' => 'Shown on the Exchange screen, where somebody is standing in front of them. Blank for scenery nobody can work for.',
            'describe' => 'Save',
            'described' => ':entity updated.',
            'fixed' => 'Set by migration',

            'added' => ':item is on the shelf.',
            'saved' => ':item updated.',
            'removed' => ':item is off the shelf.',
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
                'help' => 'Skills the character already has. Level 0 is what an imprint buys — on the sheet, untrained; anything above it is training that happened before the game started. A skill granted here is granted whole: no imprint is spent, and the points are seeded to exactly that level.',
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
                | the buckets for everything else, and the imprints get their own
                | because there is one per skill.
                */
                'groups' => [
                    'physical' => 'Carried',
                    'data' => 'On the deck',
                    'imprints' => 'Imprints',
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
        | skill's imprint, which the controller deletes first because the foreign
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
            'books' => ['one' => 'its imprint', 'many' => 'its imprint'],
            'book_copies' => ['one' => ':count copy of the imprint players hold', 'many' => ':count copies of the imprint players hold'],
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
            'name_required' => 'Give it a name. Only an imprint may go without one.',
            'container_stacks' => 'A container cannot stack: two of them would merge into one row and the contents of the second would be lost. Untick stackable.',
            'book_needs_skill' => 'An imprint that teaches nothing is not an imprint. Choose the skill it injects.',
            'skill_without_book' => 'Only an injecting item may name a skill.',
            'skill_has_a_book' => 'That skill already has an imprint. There is exactly one per skill.',
            'capacity_needs_kind' => 'A container has to say what it holds.',
            'kind_needs_capacity' => 'This holds nothing, so it cannot name a kind. Give it a capacity first.',
            'deck_stacks' => 'A cyberdeck cannot stack: two of them would merge into one row, and only one of them could carry what is fitted in it. Untick stackable.',
            'hardware_is_physical' => 'Deck hardware is matter. A cyberdeck and every module that goes in one is carried, never stored on a deck.',
            'deck_needs_slots' => 'A cyberdeck with no sockets is a case. Give it at least a CPU socket and a power socket.',
            'slots_need_a_deck' => 'Only a cyberdeck has sockets. Set the hardware to Cyberdeck first.',
            'modifier_shape' => 'Each modifier must be an object, not a bare value.',
            // The loyalty shelf. One offer per item per entity: two rows would
            // be two ranges, and an overlap is two prices at one desk.
            'offer_twice' => 'This entity already offers that item. Widen the row it has instead of adding a second.',
            'grades_inverted' => 'The lowest desk cannot be above the highest.',
            // The REASON slotted into modifier_invalid above. A target that
            // reaches no stat would resolve, match nothing and change nothing
            // for ever, so the editor names the row rather than saving it.
            'modifier_target' => 'nothing in the game answers to :target',
            'modifier_invalid' => 'Modifier :index is not valid: :reason',
            'thermal_levels_pair' => 'Give both thermal levels or neither — a part with one and not the other has no meaning.',
            'thermal_levels_order' => 'A part cannot be destroyed before it is warned about. The limit must be at or above the warning.',
            'no_thermal_load' => 'A radiator takes thermal load away rather than making it. Leave this empty.',
            'module_needs_thermal_load' => 'Say how much thermal load this makes per turn. Zero is a fine answer.',
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
