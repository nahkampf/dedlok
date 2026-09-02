<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Events\SkillInjected;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

/**
 * Turning a skillbook into a skill on the sheet.
 *
 * This is the ONLY way a character_skills row comes into existence. Training
 * does not create one — a queue entry for a skill that was never injected is a
 * data error, not an instruction to learn it — so owning and injecting the
 * book is a hard gate in front of every skill in the game.
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

        if ($item === null || ! $item->isSkillbook()) {
            return InjectionResult::refused(InjectionRefusal::NotASkillbook);
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
}
