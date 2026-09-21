<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The scan pass budget is gone, so the modifiers that bought another one are
 * pointing at nothing.
 *
 * A read now goes round until it has the picture or the file goes stale, and
 * `scan_passes` has left `config/stats.php` with the rule it existed for. A
 * modifier whose target no longer resolves is not an error — `Modifier` judges
 * shape, so it would sit on the row for ever, changing nothing and showing up
 * as a bad row the first time somebody opened the editor.
 *
 * The ORACLE is the one thing in the seeded catalogue that carries one, and it
 * keeps its steadier read: what a better instrument buys now is the same
 * picture sooner, through `scan_duration`. Every modifier list is swept all the
 * same, because content is authored in the editors and the database is the only
 * place that knows what is actually on a row.
 */
return new class extends Migration
{
    /** Every table whose rows carry a modifier list. */
    private const TABLES = ['items', 'programs', 'skills', 'archetype_perks'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)
                ->whereNotNull('modifiers')
                ->where('modifiers', 'like', '%scan_passes%')
                ->orderBy('id')
                ->each(function (object $row) use ($table): void {
                    $modifiers = json_decode((string) $row->modifiers, true);

                    if (! is_array($modifiers)) {
                        return;
                    }

                    $kept = array_values(array_filter(
                        $modifiers,
                        static fn ($modifier): bool => ! is_array($modifier)
                            || ($modifier['target']['id'] ?? null) !== 'scan_passes',
                    ));

                    DB::table($table)->where('id', $row->id)->update([
                        'modifiers' => $kept === [] ? null : json_encode($kept),
                        'updated_at' => now(),
                    ]);
                });
        }
    }

    /**
     * Nothing is put back.
     *
     * The stat it targeted no longer exists, so restoring the rows would
     * restore a modifier that resolves to nothing. Tuning content is what the
     * editors are for.
     */
    public function down(): void {}
};
