<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The combat skills were authored in the local admin and have to reach the live game by deploy, since
 * a deploy carries code and migrations and not admin edits.
 *
 * WHAT THIS DOES TO A SKILL THAT ALREADY EXISTS is set its effects and its prerequisites, and
 * nothing else: its title, description, rank, attributes and price are the live admin's to keep. The
 * four skills production does not have (Shotguns, Physiology, Combat Training, Tactical Movement) are
 * created whole, and `Imprint::ensureFor()` gives each its imprint, as every skill migration does.
 *
 * Every grant is the same shape: one `percentage_of_base` per level, 1 to 5, per target. They are
 * aim (`aim.<class>`), damage (`weapon_damage.<class>`), `defence` and `pursuit`. A grant at level
 * 1–5 only; percentages of a small base round to a tenth (`aim`, `defence`, `pursuit`) or not at all
 * (`weapon_damage`), so none of them vanishes. Every number is an unmeasured default.
 *
 * Re-running is harmless: effects are overwritten with the same list and a prerequisite that is
 * already there is left alone. A skill that is missing from a database (the test one, say) is skipped.
 */
return new class extends Migration
{
    /** @var array<string, array{0: int, 1: list<string>}> slug => [percent per level, targets] */
    private const GRANTS = [
        'closequartercombat' => [3, ['aim.unarmed']],
        'pistols' => [3, ['aim.pistol']],
        'smgs' => [3, ['aim.smg']],
        'rifles' => [3, ['aim.rifle']],
        'shotguns' => [3, ['aim.shotgun']],
        'blades' => [3, ['aim.blade']],
        'ballistics' => [2, ['aim.pistol', 'aim.rifle', 'aim.smg', 'aim.shotgun']],
        'physiology' => [3, [
            'weapon_damage.blade', 'weapon_damage.blunt', 'weapon_damage.pistol', 'weapon_damage.rifle',
            'weapon_damage.smg', 'weapon_damage.unarmed', 'weapon_damage.shotgun',
        ]],
        'combat_training' => [4, ['defence']],
        'tactical_movement' => [3, ['pursuit']],
    ];

    /** @var array<string, array<string, mixed>> the skills production does not have */
    private const NEW = [
        'shotguns' => ['title' => 'Shotguns', 'rank' => 2, 'primary_attribute' => 'perception', 'secondary_attribute' => 'motor', 'base_cost' => 1000],
        'physiology' => ['title' => 'Physiology', 'rank' => 8, 'primary_attribute' => 'memory', 'secondary_attribute' => 'senses', 'base_cost' => 2000],
        'combat_training' => ['title' => 'Combat Training', 'rank' => 8, 'primary_attribute' => 'motor', 'secondary_attribute' => 'reflex', 'base_cost' => 3000],
        'tactical_movement' => ['title' => 'Tactical Movement', 'rank' => 16, 'primary_attribute' => 'senses', 'secondary_attribute' => 'motor', 'base_cost' => 4000],
    ];

    /** @var array<string, array<string, int>> skill => [required skill => level] */
    private const REQUIRES = [
        'smgs' => ['pistols' => 3],
        'rifles' => ['pistols' => 3],
        'ballistics' => ['pistols' => 3],
        'blades' => ['closequartercombat' => 3],
        'physiology' => ['closequartercombat' => 3],
        'combat_training' => ['closequartercombat' => 3],
        'tactical_movement' => ['closequartercombat' => 3, 'combat_training' => 3],
    ];

    public function up(): void
    {
        foreach (self::NEW as $slug => $definition) {
            if (! Skill::query()->where('slug', $slug)->exists()) {
                $skill = Skill::query()->create($definition + ['slug' => $slug, 'category' => 'Combat', 'on_market' => true]);
                Imprint::ensureFor($skill);
            }
        }

        foreach (self::GRANTS as $slug => [$percent, $targets]) {
            $skill = Skill::query()->where('slug', $slug)->first();

            if ($skill === null) {
                continue;
            }

            $skill->modifiers = $this->grants($percent, $targets);
            $skill->save();
        }

        foreach (self::REQUIRES as $slug => $needs) {
            $skill = Skill::query()->where('slug', $slug)->first();

            foreach ($needs as $requiredSlug => $level) {
                $required = Skill::query()->where('slug', $requiredSlug)->first();

                if ($skill === null || $required === null) {
                    continue;
                }

                DB::table('skill_prerequisites')->updateOrInsert(
                    ['skill_id' => $skill->id, 'required_skill_id' => $required->id],
                    ['required_level' => $level],
                );
            }
        }
    }

    public function down(): void
    {
        // The skills this created go, imprint first (`items.use_skill_id` is restrictOnDelete); the
        // ones that already existed are put back to having no effects, which is how production had them.
        foreach (array_keys(self::NEW) as $slug) {
            $skill = Skill::query()->where('slug', $slug)->first();

            if ($skill !== null) {
                $skill->imprint()->delete();
                $skill->delete();
            }
        }

        foreach (array_diff(array_keys(self::GRANTS), array_keys(self::NEW)) as $slug) {
            $skill = Skill::query()->where('slug', $slug)->first();

            if ($skill !== null) {
                $skill->modifiers = null;
                $skill->save();
            }
        }

        // The two prerequisites production did not have; the others were there already.
        foreach (['rifles', 'ballistics'] as $slug) {
            $skill = Skill::query()->where('slug', $slug)->first();
            $pistols = Skill::query()->where('slug', 'pistols')->first();

            if ($skill !== null && $pistols !== null) {
                DB::table('skill_prerequisites')->where('skill_id', $skill->id)->where('required_skill_id', $pistols->id)->delete();
            }
        }
    }

    /**
     * @param  list<string>  $targets
     * @return list<array<string, mixed>>
     */
    private function grants(int $percent, array $targets): array
    {
        $rows = [];

        foreach ($targets as $target) {
            foreach (range(1, 5) as $level) {
                $rows[] = ['op' => 'percentage_of_base', 'level' => $level, 'amount' => $percent, 'target' => ['id' => $target]];
            }
        }

        return $rows;
    }
};
