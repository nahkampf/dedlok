<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Notifications\Notifier;
use Illuminate\Console\Command;

/** Story messages an encounter held back reach the inbox when their time comes. */
class DeliverMessages extends Command
{
    protected $signature = 'messages:deliver {--quiet-when-idle : Print nothing unless a message was delivered}';

    protected $description = 'Deliver the story messages whose delay has run out';

    public function handle(Notifier $notifier): int
    {
        $count = $notifier->deliverDue();

        if ($count > 0 || ! $this->option('quiet-when-idle')) {
            $this->info("{$count} message(s) delivered");
        }

        return self::SUCCESS;
    }
}
