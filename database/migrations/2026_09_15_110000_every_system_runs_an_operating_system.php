<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every machine runs something, and which something is a skill you either have
 * or do not.
 *
 * The operating system is the first gate an intrusion meets: docs/CYBERSPACE.md
 * makes it the skill you need at level 1 to attempt a system AT ALL. Four of
 * them exist as skill rows already — AuraOS, Prism-V, IRONCLAD and Lattice, at
 * ranks 2, 4, 8 and 16 — and that ladder is the whole vocabulary.
 *
 * A FOREIGN KEY TO `skills`, NOT A WORD. The OS is a skill gate, so the thing
 * to point at is the skill: the name, the description and the book all live on
 * that row already, and a varchar here would be a second spelling of something
 * the database can enforce. It is the same decision `items.use_skill_id` takes
 * for an imprint, and it means a fifth operating system is a skill row and a
 * weight rather than an enum case, a migration and a string.
 *
 * WHICH ONE A MACHINE RUNS FOLLOWS ITS GRADE, and follows it through the
 * skill's own RANK rather than through a table of pairs. A grade 1 box runs
 * the cheap, common thing; a grade 9 runs the one almost nobody has trained.
 * Nothing had to be written down twice for that: rank already says how far up
 * the ladder a skill sits, and `config/cyberspace.php` holds the one curve that
 * maps a grade onto it.
 *
 * NULLABLE, because the portal runs nothing anybody can name and because a
 * database with no operating-system skills in it — a half-seeded test — must
 * still be able to hold a world. A system with no OS is not a system with a
 * secret; it is one nothing has decided about yet.
 */
return new class extends Migration
{
    /** Skills that are operating systems. The category IS the vocabulary. */
    private const CATEGORY = 'Operating Systems';

    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->foreignId('os_skill_id')
                ->nullable()
                ->after('grade')
                ->comment('The operating system this machine runs, as the skill that gates it. NULL is the portal, or a world seeded before the skills existed.')
                ->constrained('skills')
                ->nullOnDelete();
        });

        $this->assignByGrade();
    }

    public function down(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->dropForeign(['os_skill_id']);
            $table->dropColumn('os_skill_id');
        });
    }

    /**
     * Give the standing world an operating system apiece.
     *
     * Deterministic per grade rather than rolled: this is a backfill, and a
     * backfill that rolls is one nobody can reproduce when they ask why a
     * particular machine runs what it runs. The generator rolls for NEW
     * systems, where variety is the point.
     */
    private function assignByGrade(): void
    {
        $ladder = DB::table('skills')
            ->where('category', self::CATEGORY)
            ->orderBy('rank')
            ->pluck('id')
            ->all();

        if ($ladder === []) {
            return;
        }

        // Nine grades onto however many rungs there are, so the top grade gets
        // the top rung and the bottom gets the bottom whatever the ladder's
        // length turns out to be.
        $rungs = count($ladder);

        for ($grade = 1; $grade <= 9; $grade++) {
            $rung = (int) floor(($grade - 1) * $rungs / 9);

            DB::table('systems')
                ->where('grade', $grade)
                ->where('type', '!=', 'portal')
                ->update(['os_skill_id' => $ladder[$rung]]);
        }
    }
};
