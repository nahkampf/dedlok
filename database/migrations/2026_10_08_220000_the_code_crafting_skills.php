<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;

/**
 * THE CODE CRAFTING SKILLS (docs/CRAFTING.md). Code Crafting gates crafting in general and shortens every build; each kind of program has
 * its own gate that also shortens builds of that kind. All on the market, category "Code Crafting". The per-level grants are the usual
 * five modifiers; the gate levels are `config/crafting.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->skill('code_crafting', 'Code Crafting', 16, 'intellect', 'memory', 700,
            'Turning pieces of other people\'s systems into software of your own: knowing which fragment is load-bearing and which is dead weight.',
            ['all_of' => ['crafting_time']], -4, requires: ['cyberdecks' => 3]);

        $this->skill('script_crafting', 'Script Crafting', 8, 'memory', 'intellect', 1100,
            'Small, single-purpose programs that do one thing for a few minutes and are gone. Cheap to make, and worth knowing how.',
            ['id' => 'crafting_seconds.script'], -3, requires: ['code_crafting' => 1]);

        foreach (['icebreaker' => 'Icebreaker', 'analyser' => 'Analyser', 'decryptor' => 'Decryptor'] as $kind => $title) {
            $this->skill("{$kind}_crafting", "{$title} Crafting", 16, 'intellect', 'memory', 2000,
                "Building {$kind}s from parts rather than buying them: the tolerances, the fit, and what to leave out.",
                ['id' => "crafting_seconds.{$kind}"], -3, requires: ['code_crafting' => 3]);
        }
    }

    public function down(): void
    {
        foreach (['code_crafting', 'script_crafting', 'icebreaker_crafting', 'analyser_crafting', 'decryptor_crafting'] as $slug) {
            $skill = Skill::query()->where('slug', $slug)->first();
            $skill?->imprint()->delete();
            $skill?->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, int>  $requires  skill slug => level, for the ones that exist
     */
    private function skill(string $slug, string $title, int $rank, string $primary, string $secondary, int $cost, string $description, array $target, int $amount, array $requires): void
    {
        $skill = Skill::query()->updateOrCreate(['slug' => $slug], [
            'title' => $title,
            'description' => $description,
            'category' => 'Code Crafting',
            'rank' => $rank,
            'primary_attribute' => $primary,
            'secondary_attribute' => $secondary,
            'base_cost' => $cost,
            'on_market' => true,
            'modifiers' => array_map(
                static fn (int $level): array => ['op' => 'percentage_of_base', 'level' => $level, 'amount' => $amount, 'target' => $target],
                range(1, 5),
            ),
        ]);

        foreach ($requires as $requiredSlug => $level) {
            $required = Skill::query()->where('slug', $requiredSlug)->first();

            if ($required !== null) {
                $skill->prerequisites()->updateOrCreate(['required_skill_id' => $required->getKey()], ['required_level' => $level]);
            }
        }

        Imprint::ensureFor($skill);
        Imprint::syncFor($skill);
    }
};
