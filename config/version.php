<?php

declare(strict_types=1);

/*
| The running application's version.
|
| Git tags are the source of truth for what a release IS; this is the copy the
| application itself can read, because a deployed container has no tags. The
| two are kept in step by hand — see the release checklist in CLAUDE.md.
|
| SemVer, and pre-1.0 is meant literally: while the major is 0, a minor bump
| may change the schema in ways that need a migration.
*/

return [

    'app' => '0.56.1',

];
