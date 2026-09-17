<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two columns `$table->timestamps()` will not let you comment.
 *
 * It returns void, so there is nothing to chain a comment onto, and the rule
 * that every column carries one is therefore impossible to keep inline for
 * these two. `bookmarks` hit this first and took the same follow-up; what is
 * different this time is that `db:comments` was the thing that noticed, rather
 * than somebody reading the schema months later.
 *
 * A migration rather than `db:comments --fill`, because the fill writes to the
 * database it is pointed at and leaves the next fresh install exactly as bare.
 */
return new class extends Migration
{
    private const COMMENTS = [
        'created_at' => 'When this was stocked. Restocks are nightly, so it also says which pass put it here.',
        'updated_at' => 'Last touched. Contents are written whole rather than edited, so this tracks created_at until something takes a part of a pile.',
    ];

    public function up(): void
    {
        $this->apply(self::COMMENTS);
    }

    public function down(): void
    {
        $this->apply(array_fill_keys(array_keys(self::COMMENTS), ''));
    }

    /** @param  array<string, string>  $comments */
    private function apply(array $comments): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($comments as $column => $comment) {
            DB::statement(sprintf(
                'ALTER TABLE `system_contents` MODIFY COLUMN `%s` TIMESTAMP NULL COMMENT %s',
                $column,
                DB::getPdo()->quote($comment),
            ));
        }
    }
};
