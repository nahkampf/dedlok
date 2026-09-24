<?php

// The deploy, such as it is. Runs daemonised on the production server as the
// user `dedlok` — which owns the tree and has no sudo, so everything below is
// something that user can do on its own.
//
// IT PULLS OVER ITSELF. This file lives in the repo root, which on the server
// IS /var/www/dedlok — so a deploy overwrites the very script doing the
// deploying. That is harmless and worth understanding: PHP reads a script once
// at startup, and this is a while(true) daemon, so the new code lands on disk
// immediately and runs NEVER until somebody restarts the process.
//
// Two things follow. A change here reaches the server on the next push and
// takes effect on the next restart, so those are separate events. And a broken
// edit cannot take the deploy down on its own — the running process carries on
// with the code it started with.
//
// ## What it does after a pull, and only when it has to
//
// A pull that touched nothing relevant runs nothing. The diff between the old
// and new HEAD decides:
//
//   composer.lock        -> composer install
//   database/migrations/ -> php artisan migrate --force
//
// and the caches are cleared on every pull regardless, because compiled Blade
// from the previous commit is the one thing that is stale on every deploy.
//
// ## Order
//
// Dependencies first: a migration may use a class that arrived with them.
// Then the caches, so Artisan reads the config that was just pulled rather
// than a compiled copy of the last one. Then the schema.
//
// ## Nothing here aborts the loop
//
// A failed step is logged and the daemon keeps polling. The alternative is a
// deploy daemon that dies on a bad release and silently stops deploying — a
// worse failure than a loud one, because nobody is watching this output.

set_time_limit(0);
ini_set('memory_limit', '128M');
gc_enable();

$repoPath = '/var/www/dedlok';
$branch = 'main';

// `composer` is not always on a daemon's PATH. An absolute path that does not
// exist is caught below and logged rather than failing silently.
$composer = '/usr/local/bin/composer';

/** Timestamped, because this output is read after the fact or not at all. */
$say = static function (string $line): void {
    echo '['.date('Y-m-d H:i:s').'] '.$line."\n";
};

/**
 * Run one command in the repo, log what it said, and report whether it worked.
 *
 * Output is echoed in full on failure and summarised on success: a deploy that
 * worked does not need forty lines of Composer, and one that did not needs all
 * of them.
 */
$run = static function (string $label, string $command) use ($repoPath, $say): bool {
    $say("  {$label}...");

    $output = [];
    $code = 0;
    exec('cd '.escapeshellarg($repoPath).' && '.$command.' 2>&1', $output, $code);

    if ($code !== 0) {
        $say("  {$label} FAILED (exit {$code}):");
        foreach ($output as $line) {
            $say('    '.$line);
        }

        return false;
    }

    $say("  {$label} ok");

    return true;
};

while (true) {
    $fetchOutput = [];
    $fetchCode = 0;
    exec('git -C '.escapeshellarg($repoPath)." fetch origin {$branch} 2>&1", $fetchOutput, $fetchCode);

    if ($fetchCode === 0) {
        $localHash = trim((string) shell_exec('git -C '.escapeshellarg($repoPath).' rev-parse HEAD'));
        $remoteHash = trim((string) shell_exec('git -C '.escapeshellarg($repoPath)." rev-parse origin/{$branch}"));

        if ($localHash !== '' && $remoteHash !== '' && $localHash !== $remoteHash) {
            $say("New commits detected ({$remoteHash}). Pulling...");

            // WHAT CHANGED, asked BEFORE the pull moves HEAD. Afterwards the
            // old hash is still valid, but reading it first keeps the two
            // questions — what is coming, and did it arrive — in order.
            $changed = [];
            exec(
                'git -C '.escapeshellarg($repoPath).' diff --name-only '
                .escapeshellarg($localHash).' '.escapeshellarg($remoteHash).' 2>&1',
                $changed,
            );

            $touched = static fn (string $needle): bool => (bool) array_filter(
                $changed,
                static fn (string $path): bool => str_contains($path, $needle),
            );

            $needsComposer = $touched('composer.lock');
            $needsMigrate = $touched('database/migrations/');

            $pullOutput = [];
            $pullCode = 0;
            exec('git -C '.escapeshellarg($repoPath)." pull origin {$branch} 2>&1", $pullOutput, $pullCode);

            if ($pullCode !== 0) {
                $say('Git pull failed:');
                foreach ($pullOutput as $line) {
                    $say('  '.$line);
                }
            } else {
                $say('Pulled. Running post-pull steps.');

                // 1. Dependencies, before anything that autoloads.
                if ($needsComposer) {
                    if (! is_file($composer)) {
                        $say("  composer.lock moved but {$composer} is not there — DEPENDENCIES ARE STALE");
                    } else {
                        $run('composer install', escapeshellarg($composer).' install --no-dev --no-interaction --optimize-autoloader');
                    }
                } else {
                    $say('  composer install skipped (composer.lock unchanged)');
                }

                // 2. Caches. Every pull, because compiled Blade from the
                //    previous commit is stale on every one of them.
                $run('cache clear', escapeshellarg(PHP_BINARY).' artisan optimize:clear');

                // 3. The schema, last, and only when a migration arrived.
                //    NOTE: production and development share one database
                //    today, so this is usually a no-op — the migration was
                //    already applied from a laptop. It is here for the day
                //    they are two databases, which is when its absence would
                //    ship code against a schema that does not exist.
                if ($needsMigrate) {
                    $run('migrate', escapeshellarg(PHP_BINARY).' artisan migrate --force');
                } else {
                    $say('  migrate skipped (no new migrations)');
                }

                $say('Deploy finished.');
            }

            unset($pullOutput, $changed);
        }
    }

    unset($fetchOutput);
    gc_collect_cycles();
    sleep(60);
}
