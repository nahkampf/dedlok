<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Defensive Compartmentalization — Reactive Firewalling's rank 16 successor.
 *
 * Same stat, a larger step: −6% of base per level on
 * `damage.synaptic_integrity.ice`, −30% trained out, −42.5% with Reactive
 * Firewalling beneath it (percentages sum). A percentage and not a flat
 * amount, because a flat −15 emptied every hit from ICE I to ICE IV — a
 * resistance should soften ICE at every grade, never make a grade harmless.
 *
 * OFF THE MARKET. It is meant to be found at an Exchange, not bought off the
 * shelf; `Imprint` keeps the imprint's `on_market` in step with this.
 *
 * A MIGRATION AND NOT A SEEDER, for the reason Egress Protocols gives.
 */
return new class extends Migration
{
    private const SLUG = 'defensive_compartmentalization';

    /** What it needs, and how far trained. */
    private const REQUIRES = ['reactive_firewalling' => 3];

    public function up(): void
    {
        $skill = Skill::query()->updateOrCreate(['slug' => self::SLUG], [
            'title' => 'Defensive Compartmentalization',
            'description' => 'Partition the self before the ICE gets there. The interface is walled into cells that '
                .'fail one at a time, so an attack that lands burns through a compartment rather than straight into '
                .'the nervous system. Each level takes a larger share off every hit ICE lands.',
            'category' => 'Evasion Techniques',
            'rank' => 16,
            'primary_attribute' => 'intellect',
            'secondary_attribute' => 'perception',
            'base_cost' => 35000,
            'on_market' => false,
            'modifiers' => array_map(
                static fn (int $level): array => [
                    'op' => 'percentage_of_base',
                    'level' => $level,
                    'amount' => -6,
                    'target' => ['id' => 'damage.synaptic_integrity.ice'],
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
