<?php

declare(strict_types=1);

// Fortify screens. Laravel's own auth.failed/password/throttle keys are
// published separately by `artisan lang:publish`.

return [
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
];
