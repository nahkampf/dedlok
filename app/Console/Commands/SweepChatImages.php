<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Chat\ChatImages;
use Illuminate\Console\Command;

/** Delete the pictures pasted into the chat that are older than a week. */
class SweepChatImages extends Command
{
    protected $signature = 'chat:sweep-images';

    protected $description = 'Delete chat pictures older than a week';

    public function handle(ChatImages $images): int
    {
        $this->info('Deleted '.$images->sweep().' chat picture(s).');

        return self::SUCCESS;
    }
}
