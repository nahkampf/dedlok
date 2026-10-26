<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\City\CityRefusal;
use App\Insurance\Insurance;
use App\Insurance\InsuranceKind;
use App\Models\Character;
use App\Models\ClinicOffer;
use App\Models\District;
use App\Models\InsuranceOffer;
use App\Models\InsurancePolicy;
use App\Models\Location;
use App\Stats\InjuryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

final class InsuranceTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private function place(Character $character): Location
    {
        $district = District::query()->findOrFail($character->district_id);
        $place = Location::query()->create(['district_id' => $district->getKey(), 'slug' => 'vectra', 'name' => 'Vectra', 'map_x' => 1, 'map_y' => 1, 'discovery' => 'open']);

        foreach (['property', 'medical'] as $kind) {
            foreach ([50 => 100, 70 => 200, 90 => 300] as $share => $price) {
                InsuranceOffer::query()->create(['location_id' => $place->getKey(), 'kind' => $kind, 'share' => $share, 'price' => $price]);
            }
        }

        return $place;
    }

    public function test_signing_renews_by_queueing_and_a_second_renewal_is_refused(): void
    {
        $character = Character::factory()->create(['creds' => 1000]);
        $place = $this->place($character);
        $insurance = new Insurance;

        $this->assertSame(200, $insurance->sign($character, $place, InsuranceKind::Property, 70));
        $this->assertSame(70, $insurance->active($character, InsuranceKind::Property)?->share);

        $this->assertSame(300, $insurance->sign($character, $place, InsuranceKind::Property, 90));
        $this->assertSame(70, $insurance->active($character, InsuranceKind::Property)?->share);
        $this->assertSame(90, $insurance->queued($character, InsuranceKind::Property)?->share);
        $this->assertSame(CityRefusal::AlreadyCovered, $insurance->sign($character, $place, InsuranceKind::Property, 50));
        $this->assertSame(500, (int) $character->fresh()->creds);
    }

    public function test_the_reminder_goes_once_and_skips_a_renewed_term(): void
    {
        $a = Character::factory()->create();
        $b = Character::factory()->create();

        foreach ([$a, $b] as $c) {
            InsurancePolicy::query()->create(['character_id' => $c->getKey(), 'kind' => 'property', 'share' => 50, 'starts_at' => now()->subDays(12), 'ends_at' => now()->addDay()]);
        }

        InsurancePolicy::query()->create(['character_id' => $b->getKey(), 'kind' => 'property', 'share' => 50, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(15)]);

        $insurance = new Insurance;

        $this->assertSame(1, $insurance->remind());
        $this->assertSame(0, $insurance->remind());
    }

    public function test_a_flatline_pays_the_clinic_price_at_the_policy_share(): void
    {
        $character = Character::factory()->create(['creds' => 0]);
        $place = $this->place($character);
        ClinicOffer::query()->delete();
        ClinicOffer::query()->create(['location_id' => $place->getKey(), 'service' => 'neural_damage', 'price' => 100]);
        InsurancePolicy::query()->create(['character_id' => $character->getKey(), 'kind' => 'medical', 'share' => 90, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);

        $paid = (new Insurance)->claimInjury($character, InjuryLedger::NEURAL, 6.0);

        $this->assertSame(540, $paid);
        $this->assertSame(540, (int) $character->fresh()->creds);
    }

    public function test_a_surge_pays_a_share_of_base_value(): void
    {
        $character = Character::factory()->create(['creds' => 0]);
        InsurancePolicy::query()->create(['character_id' => $character->getKey(), 'kind' => 'property', 'share' => 70, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);

        $paid = (new Insurance)->claimLoss($character, 1000, ['Kurogane LNK-1' => 1]);

        $this->assertSame(700, $paid);
        $this->assertSame(0, (new Insurance)->claimLoss(Character::factory()->create(), 1000, []));
    }
}
