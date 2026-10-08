<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\ScanExporter;
use App\Cyberspace\ScanExportRefusal;
use App\Models\Character;
use App\Models\SystemScan;
use Illuminate\Console\Command;

/**
 * Make the scan file for every finished, unfiled read a character holds — what a finished read now does by itself,
 * for the ones that finished before it did. Through `ScanExporter`, so a full deck refuses and nothing is spent.
 */
class FileScans extends Command
{
    protected $signature = 'scans:file {handle : The character}';

    protected $description = "File a character's finished reads as scan files";

    public function handle(ScanExporter $exporter): int
    {
        $character = Character::query()->where('handle', $this->argument('handle'))->first();

        if ($character === null) {
            $this->error('No such character.');

            return self::FAILURE;
        }

        $made = 0;

        SystemScan::query()->where('character_id', $character->id)->live()->whereNull('filed_at')->orderBy('id')->get()
            ->filter(fn (SystemScan $scan): bool => $scan->isComplete())
            ->each(function (SystemScan $scan) use ($exporter, $character, &$made): void {
                $result = $exporter->export($character, $scan->address);

                if ($result instanceof ScanExportRefusal) {
                    $this->warn("{$scan->address}: {$result->value}");

                    return;
                }

                $made++;
                $this->line("{$scan->address}: filed");
            });

        $this->info("{$made} filed.");

        return self::SUCCESS;
    }
}
