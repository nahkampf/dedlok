<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Passive hardware, implants and kernels move from the `add` layer to the new `flat` one, so a fatigue penalty or a
 * resistance no longer scales what the item says on its card. Only the `add` modifiers of the listed items change;
 * a percentage or anything else they carry is left alone, and so are the programs, drugs and Aanval, which stay scaling.
 */
return new class extends Migration
{
    private const SLUGS = [
        'nexura_coda', 'irqx_n82_katchu', 'icemate_ii', 'icemate_iii', 'hrx_paloma_4', 'hitachi_memcard3',
        'standard-kernel', 'expanded-kernel',
        'exocortex', 'mnemonic_subprocessor',
        'placeholder_implant_arms_muscle', 'placeholder_implant_body_meat', 'placeholder_implant_head_focus',
        'placeholder_implant_head_perception', 'placeholder_implant_head_senses', 'placeholder_implant_head_social',
        'placeholder_implant_legs_motor', 'placeholder_implant_spine_motor', 'placeholder_implant_spine_reflex',
    ];

    public function up(): void
    {
        $this->swap('add', 'flat');
    }

    public function down(): void
    {
        $this->swap('flat', 'add');
    }

    private function swap(string $from, string $to): void
    {
        foreach (DB::table('items')->whereIn('slug', self::SLUGS)->whereNotNull('modifiers')->get(['id', 'modifiers']) as $row) {
            $modifiers = json_decode($row->modifiers, true);

            foreach ($modifiers as &$modifier) {
                if (($modifier['op'] ?? null) === $from) {
                    $modifier['op'] = $to;
                }
            }

            DB::table('items')->where('id', $row->id)->update(['modifiers' => json_encode($modifiers)]);
        }
    }
};
