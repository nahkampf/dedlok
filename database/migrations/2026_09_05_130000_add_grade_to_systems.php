<?php

declare(strict_types=1);

use App\Cyberspace\Grade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How hard each system is.
 *
 * Grade spans magnitudes — a grade 1 sits inside a starting character's
 * Synaptic Integrity and a grade 9 can flatline somebody at 200 — and that gap
 * is the whole reason scanning is a mechanic rather than flavour.
 *
 * IT IS DELIBERATELY NOT ON `system_contacts`. Sweeping a position tells a
 * player that a machine is there, what it calls itself, who runs it and what it
 * answers to; what it would COST to break into is the thing a scan exists to
 * find out. Nothing may copy this column into a sighting or onto a map cell.
 *
 * NOT NULL with a default of 1, which also gives the portal a grade it will
 * never use — the alternative is a nullable column and a null check in every
 * comparison, to describe one row that cannot be attacked anyway. The portal
 * is excluded by being the portal, not by being ungraded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->unsignedTinyInteger('grade')->default(Grade::MIN)->after('type')
                ->comment('How hard this system is, 1-9. What a scan exists to reveal, so it must never be copied into a sighting or shown on the map.');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `systems` ADD CONSTRAINT `chk_systems_grade` CHECK ('.
                '`grade` BETWEEN '.Grade::MIN.' AND '.Grade::MAX.')'
            );
        }

        $this->gradeWhatIsStanding();
    }

    /**
     * Deal a grade to the machines already on the grid.
     *
     * Weighted from config, exactly as the generator does it, so a world
     * instantiated before this migration ends up indistinguishable from one
     * instantiated after — rather than uniformly graded, which would make every
     * existing world a third lethal.
     *
     * One UPDATE per grade rather than one per system: nine statements instead
     * of several thousand, which matters because this application talks to a
     * database on the far side of the internet.
     */
    private function gradeWhatIsStanding(): void
    {
        $roll = static fn (int $min, int $max): int => random_int($min, $max);
        $buckets = [];

        DB::table('systems')->where('type', '!=', 'portal')->orderBy('id')
            ->chunkById(1000, function ($systems) use ($roll, &$buckets): void {
                foreach ($systems as $system) {
                    $buckets[Grade::roll($roll)][] = $system->id;
                }
            });

        foreach ($buckets as $grade => $systems) {
            foreach (array_chunk($systems, 1000) as $slice) {
                DB::table('systems')->whereIn('id', $slice)->update(['grade' => $grade]);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `systems` DROP CONSTRAINT `chk_systems_grade`');
        }

        Schema::table('systems', function (Blueprint $table): void {
            $table->dropColumn('grade');
        });
    }
};
