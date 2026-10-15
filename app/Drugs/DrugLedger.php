<?php

declare(strict_types=1);

namespace App\Drugs;

use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\Dose;
use App\Models\Drug;
use App\Models\DrugUse;
use App\Notifications\Category;
use App\Notifications\Notifier;
use Illuminate\Support\Facades\Config;

/**
 * The books, and what they oblige.
 *
 * The only writer of `character_drug_use`, and the only thing that writes or
 * clears a withdrawal — which belongs here because addiction is the figure
 * that obliges it.
 *
 * ## Two totals per family, and a missing row means clean
 *
 * Absent and zero are the same answer, so nothing creates a row in advance.
 * Kept per FAMILY because somebody can be deep into opiates and clean of
 * stimulants, and a figure per drug would make every new drug in the catalogue
 * a fresh start on a habit somebody already has.
 *
 * ## Tolerance is read as a BAND, and only when a dose is taken
 *
 * `potencyFor()` is the number frozen onto the dose. The strongest band that
 * applies wins, and below the first one a dose is worth exactly what it says.
 *
 * ## Withdrawal is a row, and taking one clears it without curing anything
 *
 * Past the threshold, going without is a `config/effects.php` definition
 * written as a `character_modifiers` row — a cause with no row of its own,
 * which is what those are for. A dose of the right family clears it for as
 * long as the dose lasts. **The addiction figure is not touched**: feeding it
 * is not curing it, and only time brings the number down.
 */
final class DrugLedger
{
    public function __construct(private readonly Notifier $notifier = new Notifier) {}

    /** What this character carries for a family. Reads zero for a missing row. */
    public function for(Character $character, Family $family): DrugUse
    {
        return DrugUse::query()->firstOrNew(
            ['character_id' => $character->getKey(), 'family' => $family->value],
            ['addiction' => 0.0, 'tolerance' => 0.0],
        );
    }

    /**
     * Take tolerance off one family, floored at nothing, and return how much actually came off. Books that reach
     * nothing on both figures are deleted, as the nightly decay does: absent and zero are the same answer.
     */
    public function lowerTolerance(Character $character, Family $family, int|float $amount): int|float
    {
        return $this->lower($character, $family, 'tolerance', $amount);
    }

    /** The same for addiction. A body that drops under the withdrawal line stops wearing it at once. */
    public function lowerAddiction(Character $character, Family $family, int|float $amount): int|float
    {
        $taken = $this->lower($character, $family, 'addiction', $amount);

        $this->reconcile($character, $family);

        return $taken;
    }

    private function lower(Character $character, Family $family, string $figure, int|float $amount): int|float
    {
        $books = DrugUse::query()
            ->where('character_id', $character->getKey())
            ->where('family', $family->value)
            ->lockForUpdate()
            ->first();

        if ($books === null) {
            return 0;
        }

        $before = (float) $books->{$figure};
        $after = max(0.0, round($before - (float) $amount, 2));
        $other = (float) $books->{$figure === 'addiction' ? 'tolerance' : 'addiction'};

        if ($after === 0.0 && $other === 0.0) {
            $books->delete();
        } else {
            $books->forceFill([$figure => $after])->save();
        }

        return \App\Support\Points::of($before - $after);
    }

    /** What a dose of this family is worth to this character right now, 0–1. */
    public function potencyFor(Character $character, Family $family): float
    {
        return round(1.0 - $this->reductionFor((float) $this->for($character, $family)->tolerance), 2);
    }

    /** How much a band takes off. The strongest that applies wins. */
    public function reductionFor(float $tolerance): float
    {
        $reduction = 0.0;

        /** @var list<array{at: float, reduction: float}> $bands */
        $bands = (array) Config::get('drugs.tolerance.bands', []);

        foreach ($bands as $band) {
            if ($tolerance >= (float) $band['at']) {
                $reduction = max($reduction, (float) $band['reduction']);
            }
        }

        return $reduction;
    }

    /**
     * How many of one family this body holds at once.
     *
     * Tolerance costs potency and buys headroom, which is what makes it worth
     * having as well as worth fearing.
     */
    public function capacityFor(Character $character, Family $family): int
    {
        $per = (float) Config::get('drugs.overdose.per_tolerance', 25.0);
        $tolerance = (float) $this->for($character, $family)->tolerance;

        return (int) Config::get('drugs.overdose.base', 2)
            + ($per > 0 ? (int) floor($tolerance / $per) : 0);
    }

    /** One dose has been taken. Move both figures. */
    public function record(Character $character, Drug $drug): DrugUse
    {
        $books = $this->for($character, $drug->family);

        $books->forceFill([
            'character_id' => $character->getKey(),
            'family' => $drug->family->value,
            // A family that cannot hook anybody never moves the figure, whatever the drug row says.
            'addiction' => $drug->family->addicts() ? round((float) $books->addiction + $drug->addictive, 2) : (float) $books->addiction,
            'tolerance' => round((float) $books->tolerance + $drug->tolerance_gain, 2),
        ])->save();

        return $books;
    }

    /** Is this character over the line for this family? */
    public function isHooked(Character $character, Family $family): bool
    {
        return (float) $this->for($character, $family)->addiction
            >= (float) Config::get('drugs.withdrawal.threshold', 40.0);
    }

    /**
     * Make the withdrawal row match the truth for one family.
     *
     * Written when somebody is over the line with nothing of that family in
     * them, cleared the moment either stops being true. Returns whether the
     * character is IN withdrawal afterwards.
     *
     * The player is told when one begins, and only when it begins — crossing
     * into withdrawal is exactly the thing the inbox exists for, and saying it
     * again every five minutes would be a sweep talking to itself.
     */
    public function reconcile(Character $character, Family $family): bool
    {
        // No withdrawal exists for a family that cannot hook anybody, so there is nothing to write or to look up.
        if (! $family->addicts()) {
            return false;
        }

        $key = $family->withdrawalKey();

        $carried = CharacterModifier::query()
            ->where('character_id', $character->getKey())
            ->where('definition_key', $key)
            ->first();

        $due = $this->isHooked($character, $family) && ! $this->hasInThem($character, $family);

        if ($due && $carried === null) {
            CharacterModifier::query()->create([
                'character_id' => $character->getKey(),
                'definition_key' => $key,
            ]);

            $this->notifier->send($character, Category::City, 'withdrawal', [
                'family' => $family->label(),
            ]);
        }

        if (! $due && $carried !== null) {
            $carried->delete();
        }

        return $due;
    }

    /** Is anything of this family still working? */
    public function hasInThem(Character $character, Family $family): bool
    {
        return Dose::query()
            ->where('character_id', $character->getKey())
            ->inEffect()
            ->whereHas('item.drug', static fn ($query) => $query->where('family', $family->value))
            ->exists();
    }

    /**
     * A day of doing nothing at all.
     *
     * Tolerance falls faster than addiction, so a break gives the drug its
     * strength back long before it gives the need up. Rows that reach nothing
     * on both figures are deleted: absent and zero are the same answer, and a
     * table of zeroes is a table nobody should have to read past.
     *
     * @return int how many books moved
     */
    public function decay(): int
    {
        $addiction = (float) Config::get('drugs.decay.addiction', 1.0);
        $tolerance = (float) Config::get('drugs.decay.tolerance', 2.0);
        $moved = 0;

        foreach (DrugUse::query()->orderBy('id')->cursor() as $books) {
            $was = [(float) $books->addiction, (float) $books->tolerance];
            $now = [
                max(0.0, round($was[0] - $addiction, 2)),
                max(0.0, round($was[1] - $tolerance, 2)),
            ];

            if ($now === $was) {
                continue;
            }

            $moved++;

            if ($now === [0.0, 0.0]) {
                $books->delete();

                continue;
            }

            $books->forceFill(['addiction' => $now[0], 'tolerance' => $now[1]])->save();
        }

        return $moved;
    }
}
