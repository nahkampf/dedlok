<?php

declare(strict_types=1);

/*
| App-specific validation messages. Laravel merges this over the framework's
| own validation.php rather than replacing it, so only the custom keys belong
| here — see the MERGE CHECK test in tests/Feature/HandleValidationTest.php,
| which fails if that ever stops being true.
*/

return [
    'handle_characters' => 'Handles may use letters and digits from any alphabet, plus: :symbols',
    'handle_length' => 'Handles may be at most :max characters long.',
];
