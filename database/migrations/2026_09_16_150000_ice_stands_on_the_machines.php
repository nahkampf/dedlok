<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ICE: the third countermeasure, and the only one that fights back.
 *
 * A CATALOGUE AND A POINTER, like corps and like the operating systems. An ICE
 * id ends up in thousands of system rows, which is the same reason the corp
 * roster arrives by migration rather than by seeder: content whose ids are
 * written into the world has to exist before the world references it, and it
 * has to leave the same way.
 *
 * ICE carries what an IDS and a tracer do not: numbers for a fight. Integrity
 * is what an icebreaker grinds down, attack is what it costs to be in the room,
 * and skill is how often it lands — a miss does no damage at all, which is what
 * makes a low-grade guard survivable rather than merely slow.
 *
 * WHAT A SCAN REMEMBERS IS THE LEVEL, NOT THE ROW. `system_scans.ice_level` is
 * a sighting and carries no foreign key, the same rule `system_contacts` follows
 * by storing an owner's NAME rather than a pointer: a scan has to outlive the
 * machine it describes, and a machine that burned takes its row with it. The
 * level is the stable identity — renaming a piece of ICE renames it everywhere,
 * which is the behaviour renaming a corp already has.
 */
return new class extends Migration
{
    /**
     * Twelve pieces, three per grade band, as PLACEHOLDERS to be edited.
     *
     * [level, integrity, attack, skill, detection, grade_min, grade_max]
     *
     * The two ends are what the middle is interpolated between, and both are
     * checkable. ICE I lands 15% of the time for 7, which is about one point of
     * Synaptic Integrity a turn against a starting character's 31 — dangerous
     * over a long fight and survivable, which is what the bottom of the grid is
     * for. ICE XII lands 95% of the time for 57, so it takes somebody at 200 SI
     * down in four turns, which is CYBERSPACE.md's "a grade 9 can flatline
     * someone at 200 SI" arriving exactly.
     *
     * INTEGRITY IS THE NUMBER TO EXPECT TO MOVE. A fight is meant to run 2-10
     * turns and that depends on icebreaker throughput, which does not exist
     * yet; these climb with attack so the ratio survives being recalibrated.
     *
     * Detection follows the tracer's curve (2 to 40), so "much lower than an
     * IDS" stays one idea rather than two.
     */
    private const ICE = [
        [1, 30, 7, 15, 2, 1, 3],
        [2, 40, 9, 22, 3, 1, 3],
        [3, 52, 11, 29, 5, 1, 3],
        [4, 68, 14, 36, 7, 4, 5],
        [5, 88, 17, 44, 9, 4, 5],
        [6, 112, 21, 51, 12, 4, 5],
        [7, 142, 25, 58, 15, 6, 7],
        [8, 178, 30, 66, 19, 6, 7],
        [9, 222, 36, 73, 24, 6, 7],
        [10, 274, 42, 80, 29, 8, 9],
        [11, 336, 49, 88, 34, 8, 9],
        [12, 410, 57, 95, 40, 8, 9],
    ];

    private const NUMERALS = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    public function up(): void
    {
        Schema::create('ice', function (Blueprint $table): void {
            $table->comment('The catalogue of intrusion countermeasure electronics — the guard on a machine, and the only defence that fights back.');

            $table->id()->comment('Primary key. Written into systems.ice_id, so it is permanent.');

            $table->unsignedTinyInteger('level')->unique()
                ->comment('Where this sits on the ladder, 1-12. THE STABLE IDENTITY: a scan remembers the level rather than the row, so renaming a piece of ICE does not orphan anybody\'s file.');

            $table->string('name', 64)
                ->comment('What it calls itself. Placeholder names today — ICE I through ICE XII — meant to be edited into something with character.');

            $table->unsignedSmallInteger('integrity')
                ->comment('Its hit points: what an icebreaker grinds down. A BASE like every deck measure, resolved rather than read, and the number most likely to move once something can actually fight.');

            $table->unsignedSmallInteger('attack')
                ->comment('Damage to Synaptic Integrity on a landed hit. A base. Resolves through App\Stats\DamageResolver when anything fights, so a resistance is a modifier rather than code.');

            $table->unsignedTinyInteger('skill')
                ->comment('How often it lands, as a percentage from 1 to 100. A MISS DOES NOTHING AT ALL, which is what makes low-grade ICE survivable rather than merely slow.');

            $table->unsignedTinyInteger('detection')
                ->comment('What the ICE itself notices, as a difficulty. Much lower than an IDS: everything hostile can notice you, and a machine with no IDS is quieter rather than safe.');

            $table->json('defeat_tags')->nullable()
                ->comment('What happens to somebody this beats, as a list of tags — Flatline, Surge, Drain and the rest. NULL is ordinary and means the defeat carries nothing beyond the waiting screen. A program may carry SEVERAL.');

            $table->unsignedTinyInteger('grade_min')
                ->comment('The lowest system grade this turns up on. Three pieces share each band.');

            $table->unsignedTinyInteger('grade_max')
                ->comment('The highest system grade this turns up on.');

            $table->timestamps();
        });

        $this->comment('ice', 'created_at', 'When this row was written.');
        $this->comment('ice', 'updated_at', 'When this row was last changed.');

        $now = now();

        DB::table('ice')->insert(array_map(
            fn (array $row): array => [
                'level' => $row[0],
                'name' => 'ICE '.self::NUMERALS[$row[0] - 1],
                'integrity' => $row[1],
                'attack' => $row[2],
                'skill' => $row[3],
                'detection' => $row[4],
                'defeat_tags' => null,
                'grade_min' => $row[5],
                'grade_max' => $row[6],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            self::ICE,
        ));

        Schema::table('systems', function (Blueprint $table): void {
            $table->foreignId('ice_id')->nullable()->after('tracer_rate')
                ->comment('Which piece of ICE guards this machine. NULL means none, which is ordinary: a relay rolls for its ICE and the portal has nothing. Decided once at creation like every other defence.')
                ->constrained('ice')->nullOnDelete();
        });

        Schema::table('system_scans', function (Blueprint $table): void {
            $table->unsignedTinyInteger('ice_level')->nullable()->after('tracer_rate')
                ->comment('What the scan found out about the ICE, as its LEVEL rather than its id. NULL is not found out; 0 is found out and there is none. No foreign key on purpose — a scan is a sighting and has to outlive the machine.');
        });
    }

    public function down(): void
    {
        Schema::table('system_scans', function (Blueprint $table): void {
            $table->dropColumn('ice_level');
        });

        Schema::table('systems', function (Blueprint $table): void {
            $table->dropForeign(['ice_id']);
            $table->dropColumn('ice_id');
        });

        Schema::dropIfExists('ice');
    }

    /** timestamps() returns void, so its comments need a statement of their own. */
    private function comment(string $table, string $column, string $comment): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY `%s` TIMESTAMP NULL DEFAULT NULL COMMENT %s',
            $table,
            $column,
            DB::getPdo()->quote($comment),
        ));
    }
};
