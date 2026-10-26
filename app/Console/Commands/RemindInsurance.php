<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Insurance\Insurance;
use Illuminate\Console\Command;

/** Send the two messages cover owes: two days left, and lapsed. Each once, and never for a renewed term. */
final class RemindInsurance extends Command
{
    protected $signature = 'insurance:remind {--quiet-when-idle : Print nothing when no message went out}';

    protected $description = 'Remind people their insurance cover is ending, or has ended';

    public function handle(Insurance $insurance): int
    {
        $sent = $insurance->remind();

        if ($sent > 0 || ! $this->option('quiet-when-idle')) {
            $this->info("Sent {$sent} insurance message(s).");
        }

        return self::SUCCESS;
    }
}
