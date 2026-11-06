<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Messages that go to the Priority box
    |--------------------------------------------------------------------------
    |
    | Keys of `strings.notifications.messages`. Anything not listed is Standard.
    | A message a player wrote (`Notifier::sendFrom()`) and a story message
    | (`Notifier::story()`) are always Important and need no entry.
    |
    */
    'important' => [
        // The grid.
        'backdoor_found', 'tracker_found', 'leech_found', 'damage_report', 'darknet_access',
        'party_invite', 'party_started', 'party_dropped', 'party_run_report',
        'fortress_alarm', 'fortress_core', 'fortress_invite', 'fortress_moved', 'fortress_burned',
        // Jobs that went wrong.
        'mission_failed', 'mission_container_lost', 'mission_voided', 'mission_retargeted', 'mission_retargeted_coords',
        // People.
        'chat_invite', 'chat_invite_private', 'offer_contract', 'transfer_received', 'supporter_welcome',
        // The City.
        'encounter_waiting', 'withdrawal', 'bounty_word', 'njord_jsc_notice', 'surgery_failed',
        'safehouse_lost', 'safehouse_stored', 'safehouse_raided', 'safehouse_burned', 'safehouse_raid_failed',
        'insurance_ending', 'insurance_lapsed', 'study_gain', 'workout_gain',
    ],

];
