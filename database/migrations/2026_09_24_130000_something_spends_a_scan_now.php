<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `system_scans.used_at` has stopped being hypothetical.
 *
 * It was created carrying *"Nothing spends one yet"*, which was true for nine
 * days and is the sort of sentence that quietly stops being true. Exporting a
 * read is what spends one now — see `App\Cyberspace\ScanExporter` — and a
 * comment describing the opposite is worse than none at all, because the
 * schema is supposed to explain itself in MySQL rather than only in the
 * migrations.
 *
 * ## A migration rather than an edit
 *
 * The creating migration is history: editing it would change nothing on any
 * database that has already run it, which is every database there is. So the
 * correction arrives the same way the column did.
 *
 * ## MySQL only, and the column is restated exactly
 *
 * A comment is a MySQL concept — `->comment()` is a no-op on SQLite, so the
 * suite has nothing to gain and a table rebuild to lose. `change()` drops
 * whatever is not restated, so both halves of the original definition are
 * repeated here: `timestamp`, and `nullable`. It carries no default and no
 * index, and no CHECK names it (`chk_system_scans_passes` and
 * `chk_system_scans_grade` name the other two).
 */
return new class extends Migration
{
    private const NOW = 'When it was spent. A scan is consumed the first time it is used; NULL is unspent. Exporting it to a Scan File is what spends one - App\Cyberspace\ScanExporter, the only writer - and that is what makes one read exactly one sellable object.';

    private const BEFORE = 'When it was spent. A scan is consumed the first time it is used; NULL is unspent. Nothing spends one yet.';

    public function up(): void
    {
        $this->comment(self::NOW);
    }

    public function down(): void
    {
        $this->comment(self::BEFORE);
    }

    private function comment(string $comment): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('system_scans', function (Blueprint $table) use ($comment): void {
            $table->timestamp('used_at')->nullable()->comment($comment)->change();
        });
    }
};
