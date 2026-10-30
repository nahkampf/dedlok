<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One "Internals" skill per operating system: each level eases that one OS's core difficulty by 1.5% of its base
 * (`os_difficulty.<slug>`, a percentage of the difficulty, never rounded).
 *
 * NOT IN THE OPERATING SYSTEMS CATEGORY, which is a ladder: `GridSeeder` gives a machine the skill of its grade out of every skill
 * there, and the fortress screen offers them as the OS to run. These are filed under Intrusion, and each asks for Cyberdecks 5 and
 * its own operating system at level 3. Not on the market.
 */
return new class extends Migration
{
    /** os slug => [title, rank, base cost] */
    private const SKILLS = [
        'auroos' => ['AuraOS', 4, 3000],
        'prismv' => ['Prism-V', 4, 7500],
        'ironclad' => ['IRONCLAD', 8, 18000],
        'lattice' => ['Lattice', 16, 60000],
    ];

    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows, and a page of admin list would otherwise start on ours.
        if (app()->runningUnitTests()) {
            return;
        }

        $this->seed();
    }

    /** The work of `up()`, public so a test can run it after making the skills it asks for. */
    public function seed(): void
    {
        foreach (self::SKILLS as $os => [$name, $rank, $cost]) {
            $skill = Skill::query()->updateOrCreate(['slug' => $os.'_internals'], [
                'title' => $name.' Internals',
                'description' => "The knowledge of - sometimes undocumented - internals deep within the {$name} operating system. Understanding the system at this very detailed level gives you a significant edge when attempting to break into the core of such systems.",
                'category' => 'Intrusion',
                'rank' => $rank,
                'primary_attribute' => 'memory',
                'secondary_attribute' => 'intellect',
                'base_cost' => $cost,
                'on_market' => false,
                'modifiers' => array_map(
                    static fn (int $level): array => [
                        'op' => 'percentage_of_base',
                        'level' => $level,
                        'amount' => -1.5,
                        'target' => ['id' => 'os_difficulty.'.$os],
                    ],
                    range(1, 5),
                ),
            ]);

            foreach (['cyberdecks' => 5, $os => 3] as $required => $level) {
                $requiredId = Skill::query()->where('slug', $required)->value('id');

                if ($requiredId !== null) {
                    DB::table('skill_prerequisites')->updateOrInsert(
                        ['skill_id' => $skill->id, 'required_skill_id' => $requiredId],
                        ['required_level' => $level],
                    );
                }
            }

            Imprint::ensureFor($skill);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::SKILLS) as $os) {
            $skill = Skill::query()->where('slug', $os.'_internals')->first();

            if ($skill === null) {
                continue;
            }

            // The imprint first: `items.use_skill_id` is restrictOnDelete.
            $skill->imprint()->delete();
            $skill->delete();
        }
    }
};
