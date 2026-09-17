<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Some software can be pointed at two things at once. Most cannot.
 *
 * A scanner reading a machine ties up an instrument for minutes, and a runner
 * with the compute to spare should be able to point a second copy at a second
 * machine. That is a property of the SOFTWARE rather than of the person: an
 * icebreaker fighting two pieces of ICE at once is not a thing that makes
 * sense, and no amount of skill should make it one.
 *
 * So the flag is here, on `programs`, beside the costs and the kind — the row
 * that already says what a program IS. `App\Stats` decides how many threads a
 * character may run of one that allows it; this decides whether more than one
 * is a question that can be asked at all.
 *
 * FALSE BY DEFAULT, which is the conservative half: a program nobody has
 * thought about runs alone, the way everything did before this column existed.
 * The three scanners are turned on with it, because reading two machines at
 * once is the case the whole idea came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->boolean('multithreaded')->default(false)->after('background_process')
                ->comment('May a character run more than one instance of this at once? A property of the software; how MANY is the program_threads stat. False is the conservative default.');
        });

        DB::table('programs')
            ->whereIn('item_id', DB::table('items')->whereIn('slug', [
                'scanner-mk1', 'scanner-mk2', 'scanner-oracle',
            ])->select('id'))
            ->update(['multithreaded' => true]);
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->dropColumn('multithreaded');
        });
    }
};
