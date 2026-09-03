<?php

declare(strict_types=1);

namespace App\Admin;

use App\Inventory\Skillbook;
use App\Models\ArchetypeItem;
use App\Models\ArchetypeSkill;
use App\Models\CharacterSkill;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\MarketPurchase;
use App\Models\Skill;
use App\Models\SkillPrerequisite;
use App\Models\TrainingQueueEntry;

/**
 * What a delete would actually destroy.
 *
 * Almost every foreign key pointing at an item or a skill cascades, which is
 * correct — an inventory row for an item that no longer exists is not a thing
 * a player owns — but it also means a single confirm button can quietly reach
 * a long way into live player data. The confirm dialog says how far.
 *
 * Counted at the moment it is asked, twice: once to draw the dialog and once
 * as the flash message after the row is gone. It is a description, never a
 * guard — nothing here decides whether the delete may proceed.
 */
final class DeletionImpact
{
    /** @param  array<string, int>  $counts */
    private function __construct(private readonly array $counts) {}

    /**
     * An item takes its inventory rows, its shelf and its sales history.
     *
     * If it is a container, the rows INSIDE every copy of it go too:
     * inventory_items.parent_id cascades, which is the fiction (crush the
     * shard, lose the data) and is also what stops orphan rows.
     *
     * An archetype handing it out silently stops doing so. Nobody who already
     * has one loses it — those rows are counted above — but every character
     * made afterwards is short a thing the card promised.
     */
    public static function forItem(Item $item): self
    {
        $rows = InventoryItem::where('item_id', $item->id);

        $counts = [
            'inventory_rows' => (clone $rows)->count(),
            'copies' => (int) (clone $rows)->sum('quantity'),
            'shelf' => $item->stockOnHand(),
            'purchases' => MarketPurchase::where('item_id', $item->id)->count(),
            'archetype_loadouts' => ArchetypeItem::where('item_id', $item->id)->count(),
        ];

        if ($item->isContainer()) {
            $counts['contents'] = InventoryItem::whereIn(
                'parent_id',
                (clone $rows)->select('id')
            )->count();
        }

        // A skill whose only book is deleted cannot be learned by anyone who
        // does not already know it. Worth saying out loud.
        if ($item->isSkillbook()) {
            $counts['orphans_skill'] = 1;
        }

        return new self(array_filter($counts, static fn (int $n): bool => $n > 0));
    }

    /**
     * A skill takes its book, everyone's progress in it, every queued level of
     * it, and every requirement naming it.
     *
     * The book is the one thing that does not cascade: items.use_skill_id is
     * restrictOnDelete, so the database refuses to drop a skill something
     * still points at. The controller deletes the book first, inside the same
     * transaction — which means the book's own inventory rows are in scope
     * here too.
     */
    public static function forSkill(Skill $skill): self
    {
        $book = Skillbook::forSkill($skill);

        $counts = [
            'characters' => CharacterSkill::where('skill_id', $skill->id)->count(),
            'queue_entries' => TrainingQueueEntry::where('skill_id', $skill->id)->count(),
            // Skills that require THIS one simply lose the requirement, which
            // makes them easier to learn rather than broken — but silently.
            'dependents' => SkillPrerequisite::where('required_skill_id', $skill->id)->count(),
            'own_prerequisites' => SkillPrerequisite::where('skill_id', $skill->id)->count(),
            'books' => $book === null ? 0 : 1,
            'book_copies' => $book === null
                ? 0
                : (int) InventoryItem::where('item_id', $book->id)->sum('quantity'),
            // Both halves of the loadout: the skill itself, and the book for
            // it that an archetype might hand out instead.
            'archetype_loadouts' => ArchetypeSkill::where('skill_id', $skill->id)->count()
                + ($book === null ? 0 : ArchetypeItem::where('item_id', $book->id)->count()),
        ];

        return new self(array_filter($counts, static fn (int $n): bool => $n > 0));
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }

    public function isEmpty(): bool
    {
        return $this->counts === [];
    }
}
