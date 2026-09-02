<?php

declare(strict_types=1);

// Cap's widget. The 'widget' keys are NOT free-form: each one is the suffix of
// a data-cap-i18n-* attribute on <cap-widget>, and public/assets/js/ui-kit.js
// copies them across verbatim. Renaming a key silently drops that label back to
// Cap's English default, so translate the values and leave the keys alone.

return [

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

];
