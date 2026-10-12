<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first cyberspace encounter: an introduction, placeholder text throughout.
 *
 * Opens on a move in the grid, once per character. An opening screen with two dialogue choices; each leads to a
 * screen with Yes and No; Yes ends on `loa_intro_ending1` and No on `loa_intro_ending2`, and both endings set the
 * flag `loa_introduced`. The words are for the author to replace in the editor.
 *
 * A MIGRATION, because this is how content reaches production without a laptop writing to it. It is skipped when the
 * slug already exists, so an author who has made it in the live admin is not overwritten.
 */
return new class extends Migration
{
    private const SLUG = 'loa_intro_encounter';

    public function up(): void
    {
        if (DB::table('encounters')->where('slug', self::SLUG)->exists()) {
            return;
        }

        $now = now();

        DB::transaction(function () use ($now): void {
            $encounterId = DB::table('encounters')->insertGetId([
                'slug' => self::SLUG,
                'title' => 'Placeholder',
                'intro' => 'Placeholder',
                'starts_on' => 'grid_step',
                'weight' => 100,
                'district_id' => null,
                'location_id' => null,
                'required_flag' => null,
                'once' => true,
                'retired' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // key => [entry, ends, choices as [label, next_key]]
            $screens = [
                'loa_intro_opening' => [true, false, [['Placeholder', 'loa_intro_dialogue1'], ['Placeholder', 'loa_intro_dialogue2']]],
                'loa_intro_dialogue1' => [false, false, [['Yes', 'loa_intro_ending1'], ['No', 'loa_intro_ending2']]],
                'loa_intro_dialogue2' => [false, false, [['Yes', 'loa_intro_ending1'], ['No', 'loa_intro_ending2']]],
                'loa_intro_ending1' => [false, true, []],
                'loa_intro_ending2' => [false, true, []],
            ];

            foreach ($screens as $key => [$entry, $ends, $choices]) {
                $nodeId = DB::table('encounter_nodes')->insertGetId([
                    'encounter_id' => $encounterId,
                    'node_key' => $key,
                    'body' => 'Placeholder',
                    'entry' => $entry,
                    'ends' => $ends,
                ]);

                foreach ($choices as $position => [$label, $next]) {
                    DB::table('encounter_choices')->insert([
                        'node_id' => $nodeId,
                        'position' => $position,
                        'label' => $label,
                        'cost' => 0,
                        'next_key' => $next,
                    ]);
                }

                // Every ending sets the flag, on arrival.
                if ($ends) {
                    DB::table('encounter_outcomes')->insert([
                        'node_id' => $nodeId,
                        'branch' => 'always',
                        'position' => 0,
                        'kind' => 'flag',
                        'ref' => 'loa_introduced',
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('encounters')->where('slug', self::SLUG)->delete();
    }
};
