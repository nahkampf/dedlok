<?php

declare(strict_types=1);

namespace App\City;

use App\Drugs\Family;
use App\Support\Text;

/**
 * What a clinic can do. A fixed vocabulary: a new treatment is a case, a label
 * and an arm in `Clinic`.
 *
 * Restoring a resource and mending an injury are SEPARATE treatments. An injury
 * lowers a maximum and restoring only fills up to the maximum as it stands, so
 * somebody with neural damage wants it mended first and refilled after.
 */
enum ClinicService: string
{
    case Hitpoints = 'hitpoints';
    case SynapticIntegrity = 'synaptic_integrity';
    case NeuralDamage = 'neural_damage';
    case PhysicalTrauma = 'physical_trauma';

    /**
     * Treatment for a habit, per drug family: `drug_<family>_addiction` and `drug_<family>_tolerance` take the stored
     * figure down, priced per point (a body is owed its whole figure, rounded up, like an injury).
     */
    case DrugStimulantAddiction = 'drug_stimulant_addiction';
    case DrugStimulantTolerance = 'drug_stimulant_tolerance';
    case DrugOpiateAddiction = 'drug_opiate_addiction';
    case DrugOpiateTolerance = 'drug_opiate_tolerance';
    case DrugSedativeAddiction = 'drug_sedative_addiction';
    case DrugSedativeTolerance = 'drug_sedative_tolerance';
    case DrugPsychedelicAddiction = 'drug_psychedelic_addiction';
    case DrugPsychedelicTolerance = 'drug_psychedelic_tolerance';
    case DrugNootropicAddiction = 'drug_nootropic_addiction';
    case DrugNootropicTolerance = 'drug_nootropic_tolerance';
    case DrugMedicalAddiction = 'drug_medical_addiction';
    case DrugMedicalTolerance = 'drug_medical_tolerance';

    /** The drug family a habit treatment is for, or null for the other four. */
    public function family(): ?Family
    {
        return preg_match('/^drug_([a-z]+)_(addiction|tolerance)$/', $this->value, $m) === 1 ? Family::tryFrom($m[1]) : null;
    }

    /** Which stored figure a habit treatment lowers: `addiction` or `tolerance`. */
    public function figure(): ?string
    {
        return preg_match('/_(addiction|tolerance)$/', $this->value, $m) === 1 && $this->family() !== null ? $m[1] : null;
    }

    public function label(): string
    {
        $family = $this->family();

        return $family === null
            ? Text::get("city.clinic.services.{$this->value}")
            : Text::get("city.clinic.services.drug_{$this->figure()}", ['family' => $family->label()]);
    }
}
