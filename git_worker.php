<?php
// /home/dedlok/scripts/git_poller.php
set_time_limit(0);
ini_set('memory_limit', '128M');
gc_enable();

$repoPath = '/var/www/dedlok';
$branch = 'main';

while (true) {
    // 1. Fetch remote updates without touching working directory
    exec("git -C " . escapeshellarg($repoPath) . " fetch origin {$branch} 2>&1", $fetchOutput, $fetchCode);

    if ($fetchCode === 0) {
        // 2. Get local vs remote commit hashes
        $localHash = trim(shell_exec("git -C " . escapeshellarg($repoPath) . " rev-parse HEAD"));
        $remoteHash = trim(shell_exec("git -C " . escapeshellarg($repoPath) . " rev-parse origin/{$branch}"));

        // 3. Only pull if local is behind remote
        if ($localHash !== '' && $remoteHash !== '' && $localHash !== $remoteHash) {
            echo "[" . date('Y-m-d H:i:s') . "] New commits detected ({$remoteHash}). Pulling...\n";
            
            $pullOutput = [];
            $pullCode = 0;
            exec("git -C " . escapeshellarg($repoPath) . " pull origin {$branch} 2>&1", $pullOutput, $pullCode);

            if ($pullCode === 0) {
                echo "[" . date('Y-m-d H:i:s') . "] Successfully pulled changes.\n";
                // Optional: Run build commands here (e.g., composer install, cache clear)
            } else {
                echo "[" . date('Y-m-d H:i:s') . "] Git pull failed:\n" . implode("\n", $pullOutput) . "\n";
            }
            unset($pullOutput);
        }
    }

    // Free memory & wait
    unset($fetchOutput);
    gc_collect_cycles();
    sleep(60);
}