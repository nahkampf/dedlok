<?php

declare(strict_types=1);

namespace App\Stats\Sources;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Stats\Modifier;

/**
 * What is installed in a character's body.
 *
 * The fifth modifier source, and the first that comes from an ITEM. An
 * implant's effects live on `items.modifiers` — MECHANICS is explicit that a
 * thing with a row carries its modifiers on that row, which is why there is no
 * `config/implants.php` — and this reads them for every object currently in
 * the body.
 *
 * INSTALLED IS THE WHOLE GATE. An implant in the stash does nothing, an
 * implant inside a memory shard does nothing, and neither can be installed
 * without being taken out first. `installed_at` is the one column that decides
 * it and App\Implants\ImplantFitter is the only thing that writes it.
 *
 * No time-awareness here yet, unlike CharacterModifiers. Nothing records when
 * an implant was REMOVED, so a past moment cannot be reconstructed — the same
 * honest limit RunningPrograms has. `installed_at` means a catch-up can at
 * least refuse to credit an implant fitted after the window began, which is
 * what the optional moment below does.
 */
final readonly class InstalledImplants implements ModifierSource
{
    public function __construct(
        private Character $character,
        /** The moment to resolve as of; null is now. */
        private ?\DateTimeInterface $asAt = null,
    ) {}

    /** @return list<Modifier> */
    public function modifiers(): array
    {
        $installed = InventoryItem::query()
            ->where('character_id', $this->character->getKey())
            ->whereNotNull('installed_at')
            ->when($this->asAt !== null, fn ($query) => $query->where('installed_at', '<=', $this->asAt))
            ->with('item')
            ->get();

        $modifiers = [];

        foreach ($installed as $row) {
            $item = $row->item;

            if ($item === null || ! is_array($item->modifiers)) {
                continue;
            }

            foreach ($item->modifiers as $shape) {
                if (is_array($shape)) {
                    $modifiers[] = Modifier::fromArray($shape + ['source' => $item->displayName()]);
                }
            }
        }

        return $modifiers;
    }
}
