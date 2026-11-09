<?php

declare(strict_types=1);

namespace App\Items;

use App\Characters\Meal;
use App\Drugs\DrugLedger;
use App\Drugs\Family;
use App\Models\Character;
use App\Support\Points;
use App\Support\Text;

/**
 * What using a consumable can do. A fixed vocabulary, like `Drugs\Family`: a new kind is a case, a label, and an arm
 * of each `match` below — never a migration and never a free-text field.
 *
 * Each kind answers two questions so a screen can ask before it spends: `would()` is how much it would do to this
 * character right now, and `apply()` does it. A use that would do nothing is refused by the caller (`Consumer`), so
 * nobody wastes a medkit at full health.
 */
enum UseEffect: string
{
    /** Restores current hit points, never past the maximum as it stands (an injury lowers that maximum). */
    case HealHitpoints = 'heal_hitpoints';

    /**
     * Takes levels off bleeding, in a fight. Bleeding lives in the fight and not on the body, so `would()` reads the level it is
     * handed and `apply()` changes nothing here: the fight eases its own wound (`Fights`). Outside a fight there is none, so it
     * would do nothing and is refused. A large amount stops it outright.
     */
    case StopBleeding = 'stop_bleeding';

    /** Fills fullness, never past the maximum. Food. */
    case Fullness = 'fullness';

    /** Takes fatigue off, never below nothing. Food. */
    case EaseFatigue = 'ease_fatigue';

    /**
     * Takes built-up tolerance off ONE drug family (`drug_<family>_tolerance`), floored at nothing. One case per
     * `Drugs\Family`: the amount is points of the tolerance figure, the same unit the bands and the nightly decay use.
     */
    case DrugStimulantTolerance = 'drug_stimulant_tolerance';
    case DrugOpiateTolerance = 'drug_opiate_tolerance';
    case DrugSedativeTolerance = 'drug_sedative_tolerance';
    case DrugPsychedelicTolerance = 'drug_psychedelic_tolerance';
    case DrugNootropicTolerance = 'drug_nootropic_tolerance';
    case DrugMedicalTolerance = 'drug_medical_tolerance';

    /** The family a tolerance kind lowers, or null for any other kind. */
    public function family(): ?Family
    {
        return preg_match('/^drug_([a-z]+)_tolerance$/', $this->value, $m) === 1 ? Family::tryFrom($m[1]) : null;
    }

    /** Where this kind's three sentences live in `strings.consumables.effects`: all the tolerance kinds share one set. */
    private function textKey(): string
    {
        return $this->family() === null ? $this->value : 'drug_tolerance';
    }

    /** @return array<string, string> */
    private function words(int|float $amount): array
    {
        return ['amount' => $amount, 'family' => $this->family()?->label() ?? ''];
    }

    public function label(): string
    {
        return Text::get("consumables.effects.{$this->textKey()}.label", $this->words(0));
    }

    /** "Restores 20 hit points", for a card. */
    public function describe(int|float $amount): string
    {
        return Text::get("consumables.effects.{$this->textKey()}.card", $this->words($amount));
    }

    /** "You recover 12 hit points.", for after it was used. */
    public function done(int|float $amount): string
    {
        return Text::get("consumables.effects.{$this->textKey()}.done", $this->words($amount));
    }

    /** How much `apply()` would change right now. Reads, never writes. */
    public function would(Character $character, int|float $amount, int $bleeding = 0): int|float
    {
        return match ($this) {
            self::StopBleeding => min($amount, $bleeding),
            self::HealHitpoints => Points::of(min($amount, max(0, $character->maximumFor('current_hitpoints') - $character->current_hitpoints))),
            self::Fullness => (new Meal(fullness: $amount))->would($character)['fullness'],
            self::EaseFatigue => (new Meal(fatigue: $amount))->would($character)['fatigue'],
            default => Points::of(min($amount, (float) (new DrugLedger)->for($character, $this->family())->tolerance)),
        };
    }

    /** Does it, and returns how much it actually changed. */
    public function apply(Character $character, int|float $amount): int|float
    {
        return match ($this) {
            self::StopBleeding => 0,
            self::HealHitpoints => $character->recover('current_hitpoints', $amount),
            self::Fullness => (new Meal(fullness: $amount))->apply($character)['fullness'],
            self::EaseFatigue => (new Meal(fatigue: $amount))->apply($character)['fatigue'],
            default => (new DrugLedger)->lowerTolerance($character, $this->family(), $amount),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
