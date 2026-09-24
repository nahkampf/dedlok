<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An offer spans a RANGE of desk grades, not one.
 *
 * `entity_offers.grade` shipped a release ago as a single number, which said
 * that a thing kept by the grade 2 desk is a different row from the same thing
 * kept by the grade 3 desk — two rows, two prices, and nothing to say they are
 * the same offer. Most of what an entity sells is available across several of
 * its desks, and the common case should not be the one that needs duplicating.
 *
 * So `min_grade` and `max_grade`, inclusive, and a desk of grade N sees
 * everything whose range contains N. A row kept by one desk alone sets both to
 * the same number, which is the old shape and still expressible.
 *
 * ## The unique key moves with it
 *
 * It was (entity, grade, item). It is now (entity, item): ONE OFFER PER ITEM
 * PER ENTITY. Two rows for one item would be two ranges, and the moment they
 * overlapped there would be two prices at one desk with nothing to say which
 * applies. An entity that wants a thing cheaper at its better desks wants a
 * discount mechanic, which is a different feature and not this one.
 *
 * ## THE NEW INDEX GOES ON BEFORE THE OLD ONE COMES OFF
 *
 * Which is the whole reason this file reads the way it does. `entity_id`
 * carries a foreign key, and MySQL refuses to drop the last index covering
 * one — error 1553. The obvious order (drop the old, add the new) gets as far
 * as dropping the unique and then fails, and because DDL auto-commits there is
 * no transaction to take that back: the table is left half-changed and the
 * migration unrecorded. That is exactly what happened on the first run of this
 * file, which is why every step below asks whether it still has work to do.
 *
 * ## Safe to do bluntly
 *
 * The table is a release old and empty — nothing has been authored into it,
 * because the admin cannot yet. So the column is dropped rather than migrated,
 * and no backfill is owed to anybody.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. The new columns first, so the new index has something to cover.
        Schema::table('entity_offers', function (Blueprint $table): void {
            if (! Schema::hasColumn('entity_offers', 'min_grade')) {
                $table->unsignedTinyInteger('min_grade')
                    ->default(1)
                    ->after('entity_id')
                    ->comment('The lowest desk grade that keeps this, 1-5. A row kept by one desk alone sets this and max_grade to the same number.');
            }

            if (! Schema::hasColumn('entity_offers', 'max_grade')) {
                $table->unsignedTinyInteger('max_grade')
                    ->default(5)
                    ->after('min_grade')
                    ->comment('The highest desk grade that keeps this, 1-5, inclusive. A desk of grade N sees every row whose range contains N.');
            }
        });

        // 2. The new keys, BEFORE anything is dropped. `entity_id` leads both,
        //    so its foreign key keeps a covering index throughout.
        Schema::table('entity_offers', function (Blueprint $table): void {
            if (! $this->hasIndex('uq_entity_offers_item')) {
                $table->unique(['entity_id', 'item_id'], 'uq_entity_offers_item');
            }

            if (! $this->hasIndex('idx_entity_offers_span')) {
                $table->index(['entity_id', 'min_grade', 'max_grade'], 'idx_entity_offers_span');
            }
        });

        // 3. Now the old ones can go. Each is asked for rather than assumed:
        //    an earlier attempt at this migration dropped two of them before
        //    failing, and DDL does not roll back.
        Schema::table('entity_offers', function (Blueprint $table): void {
            if ($this->hasIndex('uq_entity_offers_shelf')) {
                $table->dropUnique('uq_entity_offers_shelf');
            }

            if ($this->hasIndex('idx_entity_offers_shelf')) {
                $table->dropIndex('idx_entity_offers_shelf');
            }
        });

        if ($this->hasCheck('chk_entity_offers_grade')) {
            DB::statement('ALTER TABLE `entity_offers` DROP CHECK `chk_entity_offers_grade`');
        }

        if (Schema::hasColumn('entity_offers', 'grade')) {
            Schema::table('entity_offers', function (Blueprint $table): void {
                $table->dropColumn('grade');
            });
        }

        if (DB::getDriverName() !== 'mysql' || $this->hasCheck('chk_entity_offers_grades')) {
            return;
        }

        DB::statement(
            'ALTER TABLE `entity_offers` ADD CONSTRAINT `chk_entity_offers_grades` CHECK ('
            .'`min_grade` BETWEEN 1 AND 5 AND `max_grade` BETWEEN 1 AND 5 '
            .'AND `min_grade` <= `max_grade`)'
        );
    }

    public function down(): void
    {
        if ($this->hasCheck('chk_entity_offers_grades')) {
            DB::statement('ALTER TABLE `entity_offers` DROP CHECK `chk_entity_offers_grades`');
        }

        Schema::table('entity_offers', function (Blueprint $table): void {
            $table->unsignedTinyInteger('grade')->default(1)->after('entity_id')
                ->comment("Which of the entity's desks keeps it, 1-5.");
        });

        // Same ordering rule in reverse: the old keys go on before the new
        // ones come off, so `entity_id` is never left uncovered.
        Schema::table('entity_offers', function (Blueprint $table): void {
            $table->unique(['entity_id', 'grade', 'item_id'], 'uq_entity_offers_shelf');
            $table->index(['entity_id', 'grade'], 'idx_entity_offers_shelf');
        });

        Schema::table('entity_offers', function (Blueprint $table): void {
            $table->dropUnique('uq_entity_offers_item');
            $table->dropIndex('idx_entity_offers_span');
            $table->dropColumn(['min_grade', 'max_grade']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `entity_offers` ADD CONSTRAINT `chk_entity_offers_grade` CHECK (`grade` BETWEEN 1 AND 5)');
        }
    }

    /** Does this index exist? Asked rather than assumed — see the class note. */
    private function hasIndex(string $name): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            // SQLite gets a fresh table from the migration above it, so there
            // is never a half-applied state to work around in the suite.
            return $name === 'uq_entity_offers_shelf' || $name === 'idx_entity_offers_shelf';
        }

        return DB::selectOne(
            'SELECT 1 AS found FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            ['entity_offers', $name],
        ) !== null;
    }

    private function hasCheck(string $name): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::selectOne(
            'SELECT 1 AS found FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ? LIMIT 1',
            [$name],
        ) !== null;
    }
};
