<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Events\SkillInjected;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\InventoryItem;
use App\Models\Skill;
use App\Skills\SkillProgression;
use Illuminate\Support\Facades\DB;

/**
 * Turning an imprint into a skill on the sheet.
 *
 * This is the ONLY way a character_skills row comes into existence. Training
 * does not create one — a queue entry for a skill that was never injected is a
 * data error, not an instruction to learn it — so owning and injecting the
 * book is a hard gate in front of every skill in the game.
 *
 * There are two doors into that row, not two writers. inject() is the one a
 * player uses: a book is spent, and the skill lands at 0 points. grant() is
 * the one character creation uses for an archetype's starting skills, where
 * there is no book to spend and the level is whatever the archetype says. They
 * are on the same class so the gate stays a single place to read — if a third
 * origin ever appears, it belongs here too rather than beside it.
 *
 * The book is consumed and the row is created in one transaction. Either the
 * player spends the book and gets the skill or neither happens; a crash
 * between the two would otherwise eat the book for nothing.
 */
final class SkillInjector
{
    public function inject(Character $character, InventoryItem $entry): InjectionResult
    {
        $item = $entry->item;

        if ($item === null || ! $item->isImprint()) {
            return InjectionResult::refused(InjectionRefusal::NotAnImprint);
        }

        if ($entry->character_id !== $character->id || $entry->quantity < 1) {
            return InjectionResult::refused(InjectionRefusal::NotOwned);
        }

        $skill = $item->useSkill;

        if ($skill === null) {
            return InjectionResult::refused(InjectionRefusal::SkillMissing);
        }

        return DB::transaction(function () use ($character, $entry, $item, $skill): InjectionResult {
            // Re-read inside the transaction and lock it: two taps on the same
            // button must not spend two books, and must not race the uniqueness
            // check below either.
            $locked = InventoryItem::whereKey($entry->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->character_id !== $character->id || $locked->quantity < 1) {
                return InjectionResult::refused(InjectionRefusal::NotOwned);
            }

            $known = CharacterSkill::query()
                ->where('character_id', $character->id)
                ->where('skill_id', $skill->id)
                ->exists();

            // Injecting a skill already on the sheet would either wipe its
            // points or do nothing at all. Both are worse than refusing and
            // leaving the book in the player's hands.
            if ($known) {
                return InjectionResult::refused(InjectionRefusal::AlreadyKnown);
            }

            $locked->consumeOne();

            $progress = CharacterSkill::create([
                'character_id' => $character->id,
                'skill_id' => $skill->id,
                'skill_points' => 0,
                'attained_level' => 0,
            ]);

            SkillInjected::dispatch($character, $skill, $item);

            return InjectionResult::injected($progress);
        });
    }

    /**
     * Put a skill on the sheet without a book, at a level.
     *
     * What an archetype's loadout does. No book is consumed because none is
     * owned: the skill is part of who the character already is, and the level
     * is the archetype saying the training happened before the game started.
     *
     * The points are seeded to exactly the level's threshold, so the stored
     * attained_level and the level the points imply agree — which is what
     * `skills:validate` checks, and what would otherwise drift the moment the
     * first minute of training was deposited.
     *
     * Level 0 is the ordinary case and means the same thing an injection does:
     * known, untrained. A level outside the progression is a programming
     * error rather than a refusal — the request, the editor and a CHECK
     * constraint all police it before a row can carry one.
     */
    public function grant(Character $character, Skill $skill, int $level = 0): InjectionResult
    {
        $progression = SkillProgression::fromConfig();

        if ($level < 0 || $level > $progression->maxLevel()) {
            throw new \InvalidArgumentException(
                "Level [{$level}] is outside 0..{$progression->maxLevel()}."
            );
        }

        return DB::transaction(function () use ($character, $skill, $level): InjectionResult {
            $known = CharacterSkill::query()
                ->where('character_id', $character->id)
                ->where('skill_id', $skill->id)
                ->lockForUpdate()
                ->exists();

            // Same answer as an injection, for the same reason: overwriting
            // progress is worse than granting nothing. A loadout that names a
            // skill the character somehow already has is a no-op, not a reset.
            if ($known) {
                return InjectionResult::refused(InjectionRefusal::AlreadyKnown);
            }

            $points = $level > 0 ? $skill->pointsForLevel($level) : 0;

            $progress = CharacterSkill::create([
                'character_id' => $character->id,
                'skill_id' => $skill->id,
                'skill_points' => $points,
                'attained_level' => $level,
            ]);

            SkillInjected::dispatch($character, $skill, null, $points);

            return InjectionResult::injected($progress);
        });
    }
}
