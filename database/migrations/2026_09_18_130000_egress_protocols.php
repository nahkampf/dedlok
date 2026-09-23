<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Egress Protocols — the first thing in the game that helps you leave.
 *
 * CYBERSPACE.md said this shape would work before there was anything to hang
 * it on: "a skill that helps you leave is a modifier targeting the egress
 * stat, the way Overclocking targets `deck_qflops`." That is all this is. The
 * simulator does not learn that the skill exists, `Simulator` gains no branch,
 * and the explanation card on the run screen names it without knowing what a
 * skill is — it reads the source off the resolver's own breakdown.
 *
 * FIVE ENTRIES, ONE PER LEVEL, which is how `skills.modifiers` expresses "per
 * level": each grant carries the level it applies FROM, and `SkillEffects`
 * includes every grant at or below the attained level. So level 3 is three
 * live grants of +3% each, and because percentages are summed and applied
 * once that is +9% of base rather than 1.03 cubed. Trained out it is +15%,
 * which on a starting pool near 11 is a little over a point and a half — worth
 * roughly five points of chance at the bottom of the grid and rather more in
 * the middle, where the curve is steepest.
 *
 * A MIGRATION AND NOT A SEEDER, the call the corps, the ICE catalogue and the
 * twelve icebreakers all made: content that rows point at arrives once and
 * stays. A seeder would rewrite it on every re-seed, which is how an item
 * somebody deliberately tuned comes back wearing its original numbers.
 *
 * The book is created through `App\Inventory\Imprint`, the same class the
 * seeder and the admin editor use — exactly one book per skill is what makes a
 * book nameless and the injection gate meaningful, so it cannot be something
 * somebody remembers to do.
 */
return new class extends Migration
{
    private const SLUG = 'egressprotocols';

    /** What it needs, and how far trained. */
    private const REQUIRES = ['cyberdecks' => 3];

    public function up(): void
    {
        $skill = Skill::query()->updateOrCreate(['slug' => self::SLUG], [
            'title' => 'Egress Protocols',
            'description' => 'Getting out is its own discipline. Where evasion keeps a machine from noticing you, '
                .'this is the drilled sequence for breaking the connection while something already has — the order '
                .'the buffers go down in, and what not to wait for. Each level makes the attempt a little more '
                .'likely to work.',
            // It is about leaving rather than about the deck, so it files with
            // the other things you do to not be caught. What it REQUIRES is a
            // deck skill; what it IS belongs here.
            'category' => 'Evasion Techniques',
            'rank' => 8,
            // Memory carries it: the sequence is drilled and recalled under
            // pressure rather than worked out. Intellect is the second half —
            // knowing which way out this machine has.
            'primary_attribute' => 'memory',
            'secondary_attribute' => 'intellect',
            'base_cost' => 15000,
            // PUBLICLY AVAILABLE, so the book is on the shelf. `Imprint`
            // keeps `on_market` in step with the skill on every save, which is
            // what makes that one decision rather than two.
            'on_market' => true,
            'modifiers' => array_map(
                static fn (int $level): array => [
                    'op' => 'percentage_of_base',
                    'level' => $level,
                    'amount' => 3,
                    'target' => ['id' => 'egress'],
                ],
                range(1, 5),
            ),
        ]);

        foreach (self::REQUIRES as $slug => $level) {
            $required = Skill::query()->where('slug', $slug)->first();

            // A prerequisite naming a skill that is not there is not worth
            // failing a migration over — nothing else in the schema would be
            // left inconsistent, and the skill is still usable without it.
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

        // The book first: `items.use_skill_id` is restrictOnDelete, so the
        // database refuses a skill something still points at rather than
        // cascading. Everything else — prerequisites, anybody's progress,
        // queued training — cascades on its own.
        $skill->imprint()->delete();
        $skill->delete();
    }
};
