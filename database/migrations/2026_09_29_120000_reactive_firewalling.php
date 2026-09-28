<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reactive Firewalling — the first thing in the game that makes ICE hurt less.
 *
 * Breaking ICE faster could be trained; surviving a hit could not, and from
 * about ICE IX up one landed hit is more than a starting pool of Synaptic
 * Integrity. This is the resistance `DamageResolver` was built to take: a
 * modifier on `damage.synaptic_integrity.ice`, and `Simulator` learns nothing.
 *
 * −2.5% of base per level, five grants summed and applied once: −12.5% trained
 * out. Small on purpose — it softens a hit, it does not turn one into a miss.
 *
 * A MIGRATION AND NOT A SEEDER, for the reason Egress Protocols gives.
 */
return new class extends Migration
{
    private const SLUG = 'reactive_firewalling';

    /** What it needs, and how far trained. */
    private const REQUIRES = ['cyberdecks' => 3];

    public function up(): void
    {
        $skill = Skill::query()->updateOrCreate(['slug' => self::SLUG], [
            'title' => 'Reactive Firewalling',
            'description' => 'ICE does not knock; it arrives. This is the reflex of throwing filters up in the path of '
                .'an attack already in flight — shedding load, dropping the channel it came in on, letting the deck '
                .'take what the nervous system would have. Each level takes a little more off every hit ICE lands.',
            'category' => 'Evasion Techniques',
            'rank' => 8,
            'primary_attribute' => 'intellect',
            'secondary_attribute' => 'perception',
            'base_cost' => 4500,
            'on_market' => true,
            'modifiers' => array_map(
                static fn (int $level): array => [
                    'op' => 'percentage_of_base',
                    'level' => $level,
                    'amount' => -2.5,
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
