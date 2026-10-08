<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QB (storage, memory, file sizes) and qFLOPS (compute) are carried to one decimal place, so a lightweight
 * script can cost 0.4 QB of RAM to run. Each column keeps its nullability, default and comment: the statement is
 * built from what the column says now, because a MODIFY that forgets any of them drops it without a word.
 *
 * SQLite is untouched: an INTEGER column there stores 0.4 as it is, and the suite runs on it.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'deck_modules' => ['qflops', 'quantabits', 'storage'],
        'items' => ['size', 'capacity', 'onboard_storage'],
        'programs' => ['qflops_cost', 'memory_cost', 'storage_size'],
        'system_contents' => ['size_qb'],
        'runs' => ['extracting_qb'],
    ];

    public function up(): void
    {
        $this->retype('DECIMAL(10,1) UNSIGNED');
    }

    public function down(): void
    {
        $this->retype('INT UNSIGNED');
    }

    private function retype(string $type): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $row = DB::selectOne(
                    'select is_nullable as nullable, column_default as def, column_comment as comment from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
                    [$table, $column],
                );

                $default = $row->def === null ? '' : ' DEFAULT '.DB::getPdo()->quote((string) $row->def);
                $null = $row->nullable === 'YES' ? 'NULL' : 'NOT NULL';

                DB::statement(sprintf(
                    'ALTER TABLE `%s` MODIFY `%s` %s %s%s COMMENT %s',
                    $table, $column, $type, $null, $default, DB::getPdo()->quote($row->comment),
                ));
            }
        }
    }
};
