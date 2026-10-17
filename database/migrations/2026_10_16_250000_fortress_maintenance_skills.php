<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hardening and Streamlining, the two skills a fortress maintenance pass reads: Hardening raises an installed ICE or IDS's
 * values (`maintenance_boost`, 2 per cent a level), Streamlining cuts its compute, RAM and storage demand
 * (`maintenance_efficiency`, 1 point a level). Both off the market, both behind Node Administration 3. A skill whose slug exists
 * is left exactly as it is.
 */
return new class extends Migration
{
    /** @return list<array<string, mixed>> */
    private function definitions(): array
    {
        return [
            [
                'slug' => 'hardening', 'title' => 'Hardening', 'stat' => 'maintenance_boost', 'per_level' => 2,
                'description' => 'Patching and tuning the defences of a Data Fortress. Each maintenance pass by a hardener raises the numbers of the ICE and IDS it works on, never far past what they were built as.',
            ],
            [
                'slug' => 'streamlining', 'title' => 'Streamlining', 'stat' => 'maintenance_efficiency', 'per_level' => 1,
                'description' => 'Trimming the software of a Data Fortress until it asks less of the system. Each maintenance pass by a streamliner cuts what the ICE and IDS it works on demand to run.',
            ],
        ];
    }

    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows.
        if (app()->runningUnitTests()) {
            return;
        }

        foreach ($this->definitions() as $d) {
            if (Skill::query()->where('slug', $d['slug'])->exists()) {
                continue;
            }

            $skill = Skill::query()->create([
                'slug' => $d['slug'], 'title' => $d['title'], 'description' => $d['description'], 'category' => 'Systems', 'rank' => 8,
                'primary_attribute' => 'memory', 'secondary_attribute' => 'focus', 'base_cost' => 500, 'on_market' => false,
                'modifiers' => array_map(fn (int $level): array => ['op' => 'add', 'level' => $level, 'amount' => $d['per_level'], 'target' => ['id' => $d['stat']]], range(1, 5)),
            ]);

            Imprint::ensureFor($skill);

            $requiredId = DB::table('skills')->where('slug', 'node_administration')->value('id');

            if ($requiredId !== null) {
                DB::table('skill_prerequisites')->insert(['skill_id' => $skill->id, 'required_skill_id' => $requiredId, 'required_level' => 3]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->definitions() as $d) {
            DB::table('items')->whereIn('slug', ['imprint_'.$d['slug']])->delete();
            DB::table('skills')->where('slug', $d['slug'])->delete();
        }
    }
};
