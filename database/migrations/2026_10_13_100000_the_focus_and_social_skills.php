<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;

/**
 * THE TWO ATTRIBUTE SKILLS THAT WERE MISSING. Seven attributes already have a rank 16 skill that adds one point per level
 * (Cognitive Imprinting for Intellect, and so on); these cover Focus and Social. Muscle has none, by design. Like the
 * others they are off the market: content found rather than bought.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->skill('hyperfocus', 'Hyperfocus', 'memory', 'focus', 'focus',
            'A library of eidetic snapshots from people in deep flow: the held breath, the narrowed attention, the hours that pass unnoticed. Practised enough, it can be called up on demand.');

        $this->skill('microexpressions', 'Microexpressions', 'perception', 'social', 'social',
            'Recordings of interviews, interrogations and negotiations, annotated frame by frame: the flicker of a lip, the half-second of a lie. Learn to read what people do not mean to say.');
    }

    public function down(): void
    {
        foreach (['hyperfocus', 'microexpressions'] as $slug) {
            $skill = Skill::query()->where('slug', $slug)->first();
            $skill?->imprint()->delete();
            $skill?->delete();
        }
    }

    private function skill(string $slug, string $title, string $primary, string $secondary, string $grants, string $description): void
    {
        $skill = Skill::query()->updateOrCreate(['slug' => $slug], [
            'title' => $title,
            'description' => $description,
            'category' => 'Physical',
            'rank' => 16,
            'primary_attribute' => $primary,
            'secondary_attribute' => $secondary,
            'base_cost' => 10000,
            'on_market' => false,
            'modifiers' => array_map(
                static fn (int $level): array => ['op' => 'add', 'level' => $level, 'amount' => 1, 'target' => ['id' => $grants]],
                range(1, 5),
            ),
        ]);

        Imprint::ensureFor($skill);
        Imprint::syncFor($skill);
    }
};
