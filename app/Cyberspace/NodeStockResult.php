<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\System;

/**
 * What a restock pass did, in enough detail for `--dry-run` to be worth running.
 *
 * The same shape `MarketSeedResult` has, and for the same reason: a pass that
 * writes thousands of rows has to be inspectable before it is trusted, and a
 * count alone does not tell anybody whether the numbers are sane.
 */
final class NodeStockResult
{
    public int $nodes = 0;

    public int $creds = 0;

    public int $files = 0;

    public int $items = 0;

    /** @var list<array{address: string, grade: int, creds: int, file: ?int, item: bool}> */
    public array $rows = [];

    /** @param  list<array<string, mixed>>  $contents */
    public function record(System $system, array $contents): void
    {
        $this->nodes++;

        $row = [
            'address' => (string) $system->address,
            'grade' => (int) $system->grade,
            'creds' => 0,
            'file' => null,
            'item' => false,
        ];

        foreach ($contents as $content) {
            match ($content['kind']) {
                ContentKind::Creds->value => [$this->creds += $content['amount'], $row['creds'] = $content['amount']],
                ContentKind::Data->value => [$this->files++, $row['file'] = $content['size_qb']],
                ContentKind::Item->value => [$this->items++, $row['item'] = true],
                default => null,
            };
        }

        $this->rows[] = $row;
    }
}
