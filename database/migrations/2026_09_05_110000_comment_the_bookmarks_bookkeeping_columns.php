<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The four columns on `bookmarks` that the convention missed, and why.
 *
 * `bookmarks` was created AFTER `comment_every_column` had run, so it was the
 * first table to carry its own comments — and it got four of them wrong in two
 * different ways. Both are worth knowing about, because every new table will
 * meet them:
 *
 *  - **`->comment()` after `->constrained()` goes nowhere.** `constrained()`
 *    returns a ForeignKeyDefinition, not the column, so the comment is set on
 *    the key and silently never reaches the column. It has to come FIRST:
 *    `$table->foreignId('x')->comment('…')->constrained()`.
 *  - **`$table->timestamps()` returns void**, so `created_at` and `updated_at`
 *    cannot be commented inline at all. Neither can `$table->id()` in passing,
 *    though that one at least accepts `->comment()` on its own.
 *
 * Neither failure says anything. The migration runs, the table is right, and
 * the comment is simply absent — which is exactly how a convention nothing
 * enforces goes quiet.
 *
 * The definitions are read back out of `information_schema` and re-emitted
 * unchanged, for the same reason `comment_every_column` does it: MySQL has no
 * way to set a comment except by replacing the whole definition, and a
 * definition typed by hand is a definition that can be typed wrong.
 */
return new class extends Migration
{
    private const COMMENTS = [
        'id' => 'Primary key.',
        'character_id' => 'Whose bookmark this is. Bookmarks are private and are never read by another player.',
        'created_at' => 'When this row was created.',
        'updated_at' => 'When this row was last changed.',
    ];

    public function up(): void
    {
        $this->apply(fn (string $column): string => self::COMMENTS[$column]);
    }

    public function down(): void
    {
        $this->apply(static fn (string $column): string => '');
    }

    /** @param  callable(string): string  $comment */
    private function apply(callable $comment): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (array_keys(self::COMMENTS) as $column) {
            $definition = DB::selectOne(
                'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA '.
                'FROM information_schema.COLUMNS '.
                'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['bookmarks', $column]
            );

            if ($definition === null) {
                continue;
            }

            $sql = $definition->COLUMN_TYPE
                .($definition->IS_NULLABLE === 'NO' ? ' NOT NULL' : ' NULL');

            if ($definition->COLUMN_DEFAULT !== null) {
                $sql .= ' DEFAULT '.$definition->COLUMN_DEFAULT;
            }

            $extra = trim(str_ireplace('DEFAULT_GENERATED', '', $definition->EXTRA));

            DB::statement(sprintf(
                'ALTER TABLE `bookmarks` MODIFY COLUMN `%s` %s%s COMMENT %s',
                $column,
                $sql,
                $extra === '' ? '' : " {$extra}",
                DB::getPdo()->quote($comment($column)),
            ));
        }
    }
};
