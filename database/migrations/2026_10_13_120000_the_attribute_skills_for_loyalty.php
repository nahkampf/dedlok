<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE ATTRIBUTE SKILLS GO ON EVERY INTERACTABLE EXCHANGE'S SHELF: 35 loyalty each, at a standing of 1.0, for every desk
 * grade. The imprints stay off the market; this is a second door onto them (`entity_offers`). A row already there is kept.
 */
return new class extends Migration
{
    private const SKILLS = [
        'cognitive_imprinting', 'eideticimplants', 'pattern_recognition', 'hand_eye_coordination_drills',
        'exercise_routines', 'fine_motor_skills', 'hightened_senses', 'hyperfocus', 'microexpressions',
    ];

    public function up(): void
    {
        $items = DB::table('items')->join('skills', 'skills.id', '=', 'items.use_skill_id')
            ->whereIn('skills.slug', self::SKILLS)->pluck('items.id');

        $now = now();

        foreach (DB::table('entities')->where('interactable', true)->pluck('id') as $entity) {
            DB::table('entity_offers')->insertOrIgnore($items->map(fn ($item): array => [
                'entity_id' => $entity,
                'item_id' => $item,
                'min_grade' => 1,
                'max_grade' => 5,
                'loyalty_price' => 35,
                'min_standing' => 1.0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }
    }

    public function down(): void
    {
        $items = DB::table('items')->join('skills', 'skills.id', '=', 'items.use_skill_id')
            ->whereIn('skills.slug', self::SKILLS)->pluck('items.id');

        DB::table('entity_offers')->whereIn('item_id', $items)->where('loyalty_price', 35)->where('min_standing', 1.0)->delete();
    }
};
