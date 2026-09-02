<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Skills live in the database, definitions included — unlike archetypes, items
 * and stats, which are config. That is a deliberate exception: there will be
 * hundreds of skills, the market has to query them, and prerequisites are a
 * graph.
 *
 * The cost of that is real. In config a bad definition fails a test; in the
 * database a bad row is just a row. Everything SQL can express is a constraint
 * here, and `php artisan skills:validate` covers what it cannot — prerequisite
 * cycles, level/points disagreement, unknown targets.
 */
return new class extends Migration
{
    /** Duplicated on purpose: a migration is historical and must not follow the model. */
    private const ATTRIBUTES = [
        'intellect', 'memory', 'perception', 'reflex', 'focus',
        'social', 'meat', 'motor', 'senses', 'muscle',
    ];

    private const RANKS = [1, 2, 4, 8, 16];

    private const MAX_LEVEL = 5;

    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();

            // Permanent. Referenced by effects, requirements and saved data;
            // retire a skill, never rename or renumber its slug.
            $table->string('slug', 100)->unique();

            // Player-visible text lives here rather than in lang/, by decision.
            // Skills are the documented exception to the project's i18n rule.
            $table->string('title', 255);
            $table->text('description')->nullable();

            $table->string('category', 255)->index();

            // Training-time multiplier, not a cost: rank 2 needs twice the
            // points of rank 1 for the same level.
            $table->unsignedTinyInteger('rank')->default(1);

            // Decide skill points per minute for a character training this.
            $table->string('primary_attribute', 20);
            $table->string('secondary_attribute', 20);

            $table->unsignedInteger('base_cost')->default(0);
            $table->boolean('on_market')->default(true)->index();

            $table->timestamps();
        });

        Schema::create('skill_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('required_skill_id')->constrained('skills')->cascadeOnDelete();
            $table->unsignedTinyInteger('required_level');

            // One requirement per pair; "Cryptography 3 and Cryptography 5" is
            // a data error, not two requirements.
            $table->unique(['skill_id', 'required_skill_id']);
        });

        Schema::create('character_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();

            // Accrues; never spent. Capped at the level-5 threshold for the
            // skill's rank, so overspill past the cap is discarded.
            $table->unsignedInteger('skill_points')->default(0);

            // Denormalised on purpose. The deposit is the only writer and
            // updates both columns together, which is what makes it safe;
            // skills:validate re-derives it and fails if they disagree.
            $table->unsignedTinyInteger('attained_level')->default(0);

            // 1 is the skill currently training. Unique per character, so
            // "one at a time" is guaranteed by the index rather than by code.
            $table->unsignedTinyInteger('queue_position')->nullable();

            // Truncated to the minute; makes a repeated deposit a no-op.
            $table->timestamp('last_deposited_at')->nullable();

            // The rate in force at the last deposit. Diagnostic — catch-up
            // reconstructs the rate per segment rather than trusting this.
            $table->decimal('sp_per_minute', 8, 2)->nullable();

            $table->timestamps();

            $table->unique(['character_id', 'skill_id']);
            $table->unique(['character_id', 'queue_position']);
        });

        if (DB::getDriverName() !== 'mysql') {
            return;   // SQLite cannot add constraints via ALTER TABLE
        }

        $attributes = "'".implode("','", self::ATTRIBUTES)."'";
        $ranks = implode(',', self::RANKS);
        $max = self::MAX_LEVEL;

        foreach ([
            'skills' => [
                'chk_skills_rank' => "`rank` IN ({$ranks})",
                'chk_skills_primary_attribute' => "`primary_attribute` IN ({$attributes})",
                'chk_skills_secondary_attribute' => "`secondary_attribute` IN ({$attributes})",
            ],
            'skill_prerequisites' => [
                'chk_prereq_level' => "`required_level` BETWEEN 1 AND {$max}",
                // A skill can never be its own prerequisite.
                'chk_prereq_not_self' => '`skill_id` <> `required_skill_id`',
            ],
            'character_skills' => [
                'chk_character_skills_level' => "`attained_level` BETWEEN 0 AND {$max}",
                'chk_character_skills_queue' => '`queue_position` IS NULL OR `queue_position` >= 1',
            ],
        ] as $table => $constraints) {
            foreach ($constraints as $name => $expression) {
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('character_skills');
        Schema::dropIfExists('skill_prerequisites');
        Schema::dropIfExists('skills');
    }
};
