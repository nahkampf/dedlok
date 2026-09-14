<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What HAVING a skill grants, beyond the check it feeds.
 *
 * A skill has always been two things — a stat you have a level in, and the
 * check that level feeds. This is the third: a skill that makes something else
 * about the character better simply by being known.
 *
 * ON THE ROW, as JSON, because a skill is a row. The same shape and the same
 * reasoning as `archetype_perks.modifiers` and `programs.modifiers`, and the
 * reason `config/skills.php` is not where this went: a definition half in the
 * database and half in a file is two things to keep in step, and the file half
 * is the one that gets forgotten.
 *
 * EACH ENTRY CARRIES A `level`, which is what makes levelling feel like
 * anything. A grant applies from that level upward, so a skill can hand out
 * one thing at 2 and another at 5 rather than everything at once. That key is
 * stripped before the modifier is built — App\Stats\Sources\SkillEffects is
 * where the filtering and the stripping happen, and it is the only reader.
 *
 * Ships NULL on every row. Nothing grants anything until somebody writes it,
 * and the seeder that follows this migration writes the first pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table): void {
            $table->json('modifiers')->nullable()->after('secondary_attribute')
                ->comment('What having this skill grants, as JSON. Each entry adds a `level` saying from which level it applies; the rest is the ordinary modifier shape. NULL means the skill grants nothing beyond its own check.');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table): void {
            $table->dropColumn('modifiers');
        });
    }
};
