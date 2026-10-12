<?php

declare(strict_types=1);

namespace App\City;

use App\Characters\Flags;
use App\Missions\MissionLedger;
use App\Models\Character;
use App\Models\EncounterPrerequisite;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Standing\StandingLedger;

/**
 * Does the character hold what this condition asks? A read, and the only place a prerequisite is judged.
 *
 * Each kind asks the writer that owns the thing (`Flags`, `StandingLedger`, `MissionLedger::heldFiles()`, the
 * resolved `skill.<slug>` stat), so a prerequisite can never disagree with the screen that shows the same fact.
 * `negate` flips the answer.
 */
final class PrerequisiteJudge
{
    public function holds(Character $character, EncounterPrerequisite $row): bool
    {
        $holds = match ($row->kind) {
            PrerequisiteKind::Flag => Flags::has($character, (string) $row->subject),
            PrerequisiteKind::Item => $this->held($character, (int) $row->item_id) >= (int) $row->amount,
            PrerequisiteKind::ScanFiles => app(MissionLedger::class)->heldFiles($character)->count() >= (int) $row->amount,
            PrerequisiteKind::Creds => (int) $character->creds >= (int) $row->amount,
            PrerequisiteKind::Skill => $character->stat('skill.'.$row->subject)->value >= (float) $row->amount,
            PrerequisiteKind::Standing => $this->standing($character, (int) $row->entity_id) >= (float) $row->amount,
            PrerequisiteKind::District => $character->district?->slug === $row->subject,
        };

        return $row->negate ? ! $holds : $holds;
    }

    /** Every condition an encounter has, all of which must hold. None is true. */
    public function allHold(Character $character, iterable $rows): bool
    {
        foreach ($rows as $row) {
            if (! $this->holds($character, $row)) {
                return false;
            }
        }

        return true;
    }

    private function held(Character $character, int $itemId): int
    {
        return (int) InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->where('item_id', $itemId)
            ->loose()
            ->sum('quantity');
    }

    private function standing(Character $character, int $entityId): float
    {
        $entity = Entity::query()->find($entityId);

        return $entity === null ? 0.0 : (new StandingLedger)->standingFor($character, $entity);
    }
}
