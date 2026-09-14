<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Which columns have no comment, and filling in the ones nobody needs to think
 * about.
 *
 * Every table and column in this schema is supposed to explain itself — see
 * CLAUDE.md. Nothing enforces that: a missing comment fails no test and no
 * build, and the convention has already gone quiet twice, both times on the
 * same cause. `$table->timestamps()` returns void, so `created_at` and
 * `updated_at` cannot be commented inline at all, and it is easy to forget
 * `$table->id()` in passing.
 *
 * So this is the check, packaged. Run it after adding a table.
 *
 * `--fill` writes the three BOOKKEEPING comments only — id, created_at,
 * updated_at — because those mean the same thing on every table in the world
 * and nobody should be typing them out. It deliberately will not invent a
 * comment for anything else: a column called `owner_id` has a meaning only its
 * author knows, and a generated line saying "The owner id." would be worse than
 * the silence, because it would look done.
 */
class DbComments extends Command
{
    protected $signature = 'db:comments
                            {--fill : Write the standard comment on id, created_at and updated_at}';

    protected $description = 'Report columns with no comment, and optionally fill in the bookkeeping ones';

    /** The three that mean the same thing on every table there has ever been. */
    private const GENERIC = [
        'id' => 'Primary key.',
        'created_at' => 'When this row was created.',
        'updated_at' => 'When this row was last changed.',
    ];

    public function handle(): int
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->warn('Only MySQL stores column comments. Nothing to check.');

            return self::SUCCESS;
        }

        $missing = DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA '.
            'FROM information_schema.COLUMNS '.
            'WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_COMMENT = "" '.
            'ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );

        $tables = DB::select(
            'SELECT TABLE_NAME FROM information_schema.TABLES '.
            'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE" AND TABLE_COMMENT = ""'
        );

        foreach ($tables as $table) {
            $this->warn("table has no comment: {$table->TABLE_NAME}");
        }

        $filled = 0;
        $left = [];

        foreach ($missing as $column) {
            $comment = self::GENERIC[$column->COLUMN_NAME] ?? null;

            if ($comment === null || ! $this->option('fill')) {
                $left[] = "{$column->TABLE_NAME}.{$column->COLUMN_NAME}";

                continue;
            }

            $this->write($column, $comment);
            $filled++;
        }

        foreach ($left as $column) {
            $this->line("  no comment: {$column}");
        }

        if ($filled > 0) {
            $this->info("Filled {$filled} bookkeeping comment(s).");
        }

        if ($left === [] && $tables === []) {
            $this->info('Every table and column is commented.');

            return self::SUCCESS;
        }

        // A non-zero exit so this can sit in CI one day without needing its
        // output read by a person.
        $this->warn(count($left).' column(s) and '.count($tables).' table(s) still need a comment written by hand.');

        return self::FAILURE;
    }

    /**
     * Set the comment, restating the definition exactly as it already is.
     *
     * MySQL has no way to change a comment on its own — MODIFY COLUMN replaces
     * the whole definition — so the definition is read back rather than typed,
     * for the same reason the migration that did this in bulk reads it back.
     */
    private function write(object $column, string $comment): void
    {
        $sql = $column->COLUMN_TYPE.($column->IS_NULLABLE === 'NO' ? ' NOT NULL' : ' NULL');

        if ($column->COLUMN_DEFAULT !== null) {
            $default = $column->COLUMN_DEFAULT;
            $sql .= ' DEFAULT '.($default === 'CURRENT_TIMESTAMP' || is_numeric($default)
                ? $default
                : DB::getPdo()->quote($default));
        }

        $extra = trim(str_ireplace('DEFAULT_GENERATED', '', $column->EXTRA));

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY COLUMN `%s` %s%s COMMENT %s',
            $column->TABLE_NAME,
            $column->COLUMN_NAME,
            $sql,
            $extra === '' ? '' : " {$extra}",
            DB::getPdo()->quote($comment),
        ));
    }
}
