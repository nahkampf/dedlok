<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Inventory\Imprint;
use App\Models\Item;
use App\Models\Skill;
use Illuminate\Database\Seeder;

class ItemSeeder extends Seeder
{
    /**
     * One imprint per skill, and nothing else.
     *
     * There is no hardcoded catalogue. Item definitions live in the `items`
     * table and are authored in the admin item editor — a list of them in PHP
     * would be a second source for the same content, and the one that wins on
     * a re-seed rather than the one somebody edited. Deleting an item there
     * has to stay deleted.
     *
     * Imprints are the exception because they are DERIVED, not authored:
     * there is exactly one book per skill by definition, so listing them would
     * be a second place to forget when a skill is added. They carry no name of
     * their own — it composes from the skill they teach.
     */
    public function run(): void
    {
        Skill::query()->each(function (Skill $skill): void {
            // The derivation lives in App\Inventory\Imprint, because the
            // admin skill editor creates books too and two copies of these
            // rules would be two copies to keep in step.
            $this->fillInMarketValues(
                Imprint::ensureFor($skill),
                Imprint::attributesFor($skill),
            );
        });
    }

    /**
     * Give a book that predates the market its rarity and shelf size.
     *
     * Creating a book never touches an existing row, so a shelf size tuned in
     * place survives a re-run. These two are the exception while they are
     * still absent: a seed quantity of 0 is not a tuned value, it is a column
     * that did not exist when the row was written, and leaving it means a book
     * nobody can ever buy.
     *
     * Once it is non-zero this stops touching it, so a tuned value is as safe
     * as it was before.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function fillInMarketValues(Item $item, array $attributes): void
    {
        if ($item->wasRecentlyCreated || $item->seed_quantity > 0) {
            return;
        }

        $item->forceFill([
            'rarity' => $attributes['rarity'],
            'seed_quantity' => $attributes['seed_quantity'],
        ])->save();
    }
}
