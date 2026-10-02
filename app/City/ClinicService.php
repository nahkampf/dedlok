<?php

declare(strict_types=1);

namespace App\City;

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

    public function label(): string
    {
        return Text::get("city.clinic.services.{$this->value}");
    }
}
