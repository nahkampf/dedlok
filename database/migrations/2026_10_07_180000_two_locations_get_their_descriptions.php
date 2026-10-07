<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two locations were edited in the development database; this carries the change to the live one (a migration is how
 * anything reaches production). Their descriptions only: names, places on the map and concealment were left as the starter
 * city had them.
 *
 * Applied only where the description is still the starter city's, so a description somebody has since written in the live
 * admin is never overwritten. Reversible for the same reason: it puts the old text back only where the new text is still there.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> slug => [the starter city's text, the new text] */
    private const DESCRIPTIONS = [
        'pier-nine-warehouse' => ['Crates stacked three high, and one of them is always open.', 'generic harbor location'],
        'server-farm-annex' => ['Rows of humming racks nobody seems to maintain.', 'generic location'],
    ];

    public function up(): void
    {
        foreach (self::DESCRIPTIONS as $slug => [$old, $new]) {
            DB::table('locations')->where('slug', $slug)->where('description', $old)->update(['description' => $new, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::DESCRIPTIONS as $slug => [$old, $new]) {
            DB::table('locations')->where('slug', $slug)->where('description', $new)->update(['description' => $old, 'updated_at' => now()]);
        }
    }
};
