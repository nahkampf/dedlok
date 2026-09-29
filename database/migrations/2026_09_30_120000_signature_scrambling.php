<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Signature Scrambling — the first thing that blunts Ensnare.
 *
 * Ensnare was the one number in a fight nothing could ever reach: a plain
 * float read straight out of `cyberspace.attack_tags.ensnare`, multiplied
 * into the egress roll with no modifier in the loop at all — the last piece
 * of the "grades 7–9" finding left untouched after the trace and the
 * operating-system roll were opened up. `ensnare_reduction` is the fix
 * (`App\Cyberspace\Simulator::ensnareMultiplier()`); this is the first thing
 * that targets it.
 *
 * −5% of base per level, −25% trained out: an ICE III snare (55% off egress)
 * comes down to about 41%, so leaving is still expensive and no longer
 * unreachable by anything a player trains.
 *
 * A MIGRATION AND NOT A SEEDER, for the reason Egress Protocols gives.
 */
return new class extends Migration
{
    private const SLUG = 'signature_scrambling';

    /** What it needs, and how far trained. */
    private const REQUIRES = ['egressprotocols' => 3];

    public function up(): void
    {
        $skill = Skill::query()->updateOrCreate(['slug' => self::SLUG], [
            'title' => 'Signature Scrambling',
            'description' => 'Ensnare holds on by locking onto the shape of your own connection — the timing, '
                .'the handshake, the signature it grabbed the moment it noticed you. This keeps that signature '
                .'moving, randomised enough that the grip has to keep re-acquiring what it already caught. Each '
                .'level takes a share off however hard something currently holds you.',
            'category' => 'Evasion Techniques',
            'rank' => 16,
            'primary_attribute' => 'memory',
            'secondary_attribute' => 'intellect',
            'base_cost' => 35000,
            'on_market' => true,
            'modifiers' => array_map(
                static fn (int $level): array => [
                    'op' => 'percentage_of_base',
                    'level' => $level,
                    'amount' => -5,
                    'target' => ['id' => 'ensnare_reduction'],
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
