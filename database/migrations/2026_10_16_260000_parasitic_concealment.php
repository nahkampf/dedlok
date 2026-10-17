<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Parasitic Concealment: every level lowers the chance that the nightly roll finds one of the installer's Leechers by 2 per cent
 * of its base (`leech_discovery`, summed over levels and applied once). Off the market; behind Leecher Crafting 3 and Node
 * Administration 3. A skill whose slug exists is left exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows.
        if (app()->runningUnitTests() || Skill::query()->where('slug', 'parasitic_concealment')->exists()) {
            return;
        }

        $skill = Skill::query()->create([
            'slug' => 'parasitic_concealment', 'title' => 'Parasitic Concealment', 'category' => 'Systems', 'rank' => 16,
            'description' => 'Hiding a Leecher in the system it feeds on: keeping its draw small, its traffic ordinary and its footprint out of the logs. Every level makes the nightly sweep a little less likely to find the Leechers you installed.',
            'primary_attribute' => 'focus', 'secondary_attribute' => 'intellect', 'base_cost' => 500, 'on_market' => false,
            'modifiers' => array_map(fn (int $level): array => ['op' => 'percentage_of_base', 'level' => $level, 'amount' => -2, 'target' => ['id' => 'leech_discovery']], range(1, 5)),
        ]);

        Imprint::ensureFor($skill);

        foreach (['leecher_crafting', 'node_administration'] as $required) {
            $requiredId = DB::table('skills')->where('slug', $required)->value('id');

            if ($requiredId !== null) {
                DB::table('skill_prerequisites')->insert(['skill_id' => $skill->id, 'required_skill_id' => $requiredId, 'required_level' => 3]);
            }
        }
    }

    public function down(): void
    {
        DB::table('items')->where('slug', 'imprint_parasitic_concealment')->delete();
        DB::table('skills')->where('slug', 'parasitic_concealment')->delete();
    }
};
