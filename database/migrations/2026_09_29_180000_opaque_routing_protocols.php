<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Opaque Routing Protocols — the first thing that makes a routing chain HOLD.
 *
 * Routing and Advanced Routing make a chain longer; this makes each hop in it
 * harder to get past. −4% of base per level on `trace_hop_chance`, the trace's
 * own number, so −20% trained out: a grade 1 hop from 95 to 76, a grade 9 from
 * 20 to 16. Summed and applied once, never compounded.
 *
 * A MIGRATION AND NOT A SEEDER, for the reason Egress Protocols gives.
 */
return new class extends Migration
{
    private const SLUG = 'opaque_routing_protocols';

    /** What it needs, and how far trained. */
    private const REQUIRES = ['routing' => 3];

    public function up(): void
    {
        $skill = Skill::query()->updateOrCreate(['slug' => self::SLUG], [
            'title' => 'Opaque Routing Protocols',
            'description' => 'A trace reads a relay by what it gives away: timing, headers, the shape of what '
                .'passes through. Opaque routing gives it nothing to read, padding and reordering traffic until '
                .'every hop looks like every other. Each level makes each hop in your chain harder to get past.',
            'category' => 'Networking',
            'rank' => 8,
            'primary_attribute' => 'intellect',
            'secondary_attribute' => 'focus',
            'base_cost' => 15000,
            'on_market' => true,
            'modifiers' => array_map(
                static fn (int $level): array => [
                    'op' => 'percentage_of_base',
                    'level' => $level,
                    'amount' => -4,
                    'target' => ['id' => 'trace_hop_chance'],
                ],
                range(1, 5),
            ),
        ]);

        foreach (self::REQUIRES as $slug => $level) {
            $required = Skill::query()->where('slug', $slug)->first();

            if ($required === null) {
                continue;
            }

            DB::table('skill_prerequisites')->updateOrInsert(
                ['skill_id' => $skill->id, 'required_skill_id' => $required->id],
                ['required_level' => $level],
            );
        }

        Imprint::ensureFor($skill);
    }

    public function down(): void
    {
        $skill = Skill::query()->where('slug', self::SLUG)->first();

        if ($skill === null) {
            return;
        }

        // The imprint first: `items.use_skill_id` is restrictOnDelete.
        $skill->imprint()->delete();
        $skill->delete();
    }
};
