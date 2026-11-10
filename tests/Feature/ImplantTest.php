<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Implants\BodyRegion;
use App\Implants\ImplantFitter;
use App\Implants\ImplantRefusal;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use App\Stats\Sources\InstalledImplants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What is installed in a body.
 *
 * Two things this file is really about. **Capacity belongs to the region**, so
 * four abdominal slots means four altogether however they are filled. And
 * **installed is elsewhere**: an implant in a body is not in the stash, the
 * same way one inside a container or fitted into a deck is not.
 */
final class ImplantTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['user_id' => $this->user->id, 'archetype' => 3],
        ));
    }

    private function fitter(): ImplantFitter
    {
        return app(ImplantFitter::class);
    }

    private function implantItem(BodyRegion $region, array $modifiers = []): Item
    {
        return Item::factory()->physical()->create([
            'implant_region' => $region->value,
            'stackable' => false,
            'modifiers' => $modifiers === [] ? null : $modifiers,
        ]);
    }

    private function own(Item $item): InventoryItem
    {
        return InventoryItem::create([
            'character_id' => $this->character->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);
    }

    private function implant(BodyRegion $region, array $modifiers = []): InventoryItem
    {
        return $this->own($this->implantItem($region, $modifiers));
    }

    // ---- the body ----------------------------------------------------------

    public function test_the_body_has_eleven_slots_across_five_regions(): void
    {
        $this->assertSame(2, BodyRegion::Head->capacity());
        $this->assertSame(4, BodyRegion::Body->capacity());
        $this->assertSame(2, BodyRegion::Arms->capacity());
        $this->assertSame(2, BodyRegion::Legs->capacity());
        $this->assertSame(1, BodyRegion::Spine->capacity());
        $this->assertSame(11, BodyRegion::totalCapacity());
    }

    public function test_every_region_has_a_name(): void
    {
        foreach (BodyRegion::cases() as $region) {
            $this->assertNotSame("implants.regions.{$region->value}", $region->label());
        }
    }

    public function test_a_fresh_body_is_empty_but_every_region_is_present(): void
    {
        $body = $this->fitter()->body($this->character);

        $this->assertCount(5, $body);

        foreach (BodyRegion::cases() as $region) {
            $this->assertTrue($body[$region->value]->isEmpty());
        }
    }

    // ---- installing --------------------------------------------------------

    public function test_an_implant_goes_into_its_own_region(): void
    {
        $implant = $this->implant(BodyRegion::Spine);

        $this->assertTrue($this->fitter()->install($this->character, $implant)->succeeded());
        $this->assertNotNull($implant->fresh()->installed_at);
        $this->assertCount(1, $this->fitter()->installedIn($this->character, BodyRegion::Spine));
        $this->assertCount(0, $this->fitter()->installedIn($this->character, BodyRegion::Head));
    }

    /** CAPACITY BELONGS TO THE REGION: one spine slot means one. */
    public function test_a_region_refuses_more_than_it_holds(): void
    {
        $first = $this->implant(BodyRegion::Spine);
        $second = $this->implant(BodyRegion::Spine);

        $this->assertTrue($this->fitter()->install($this->character, $first)->succeeded());

        $result = $this->fitter()->install($this->character, $second);

        $this->assertSame(ImplantRefusal::NoRoom, $result->refusal);
        $this->assertNull($second->fresh()->installed_at);
    }

    public function test_the_abdomen_holds_four(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->assertTrue(
                $this->fitter()->install($this->character, $this->implant(BodyRegion::Body))->succeeded(),
                "implant {$i} should fit",
            );
        }

        $this->assertSame(
            ImplantRefusal::NoRoom,
            $this->fitter()->install($this->character, $this->implant(BodyRegion::Body))->refusal,
        );
    }

    public function test_something_that_is_not_an_implant_does_not_go_in_a_body(): void
    {
        $junk = $this->own(Item::factory()->physical()->create());

        $this->assertSame(
            ImplantRefusal::NotAnImplant,
            $this->fitter()->install($this->character, $junk)->refusal,
        );
    }

    public function test_somebody_elses_implant_is_refused(): void
    {
        $stranger = Character::factory()->create(['archetype' => 3]);
        $implant = $this->implant(BodyRegion::Head);

        $this->assertSame(
            ImplantRefusal::NotOwned,
            $this->fitter()->install($stranger, $implant)->refusal,
        );
    }

    public function test_installing_twice_is_refused(): void
    {
        $implant = $this->implant(BodyRegion::Head);
        $this->fitter()->install($this->character, $implant);

        $this->assertSame(
            ImplantRefusal::AlreadyInstalled,
            $this->fitter()->install($this->character, $implant->fresh())->refusal,
        );
    }

    /** Packed away is refused, never quietly unpacked. */
    public function test_an_implant_inside_a_container_is_refused(): void
    {
        $shard = $this->own(Item::factory()->physical()->create(['capacity' => 10, 'accepts' => 'physical']));
        $implant = $this->implant(BodyRegion::Head);
        $implant->forceFill(['parent_id' => $shard->id])->save();

        $this->assertSame(
            ImplantRefusal::NotToHand,
            $this->fitter()->install($this->character, $implant->fresh())->refusal,
        );
    }

    // ---- removing ----------------------------------------------------------

    public function test_removing_puts_it_back_in_the_stash(): void
    {
        $implant = $this->implant(BodyRegion::Legs);
        $this->fitter()->install($this->character, $implant);

        $this->assertTrue($this->fitter()->remove($this->character, $implant->fresh())->succeeded());
        $this->assertNull($implant->fresh()->installed_at);
        $this->assertNotNull($implant->fresh(), 'removing destroys nothing');
    }

    public function test_removing_something_that_is_not_in_is_refused(): void
    {
        $implant = $this->implant(BodyRegion::Legs);

        $this->assertSame(
            ImplantRefusal::NotInstalled,
            $this->fitter()->remove($this->character, $implant)->refusal,
        );
    }

    // ---- installed is elsewhere -------------------------------------------

    /**
     * An implant in a body is not in the stash — the same as one in a
     * container, one fitted into a deck, and a running program. All four
     * decided in one place.
     */
    public function test_an_installed_implant_leaves_the_stash(): void
    {
        $implant = $this->implant(BodyRegion::Arms);

        $this->assertSame(1, InventoryItem::query()->loose()->count());

        $this->fitter()->install($this->character, $implant);

        $this->assertSame(0, InventoryItem::query()->loose()->count());
    }

    // ---- what it does ------------------------------------------------------

    public function test_an_installed_implant_reaches_the_resolver(): void
    {
        $before = $this->character->stat('hitpoints_max')->value;

        $implant = $this->implant(BodyRegion::Body, [
            ['op' => 'percentage_of_base', 'amount' => 50, 'target' => ['id' => 'hitpoints_max']],
        ]);

        $this->assertSame($before, $this->character->fresh()->stat('hitpoints_max')->value, 'in the stash it does nothing');

        $this->fitter()->install($this->character, $implant);

        $this->assertGreaterThan($before, $this->character->fresh()->stat('hitpoints_max')->value);
    }

    public function test_removing_takes_the_effect_with_it(): void
    {
        $implant = $this->implant(BodyRegion::Body, [
            ['op' => 'percentage_of_base', 'amount' => 50, 'target' => ['id' => 'hitpoints_max']],
        ]);

        $before = $this->character->stat('hitpoints_max')->value;
        $this->fitter()->install($this->character, $implant);
        $this->fitter()->remove($this->character, $implant->fresh());

        $this->assertSame($before, $this->character->fresh()->stat('hitpoints_max')->value);
    }

    public function test_an_implant_with_no_modifiers_is_ordinary(): void
    {
        $implant = $this->implant(BodyRegion::Head);
        $this->fitter()->install($this->character, $implant);

        $this->assertSame([], (new InstalledImplants($this->character->fresh()))->modifiers());
    }

    // ---- the screen --------------------------------------------------------

    public function test_the_screen_draws_every_region(): void
    {
        $response = $this->actingAs($this->user)->get(route('implants.index'))->assertOk();

        foreach (BodyRegion::cases() as $region) {
            $response->assertSee($region->label());
        }

        $response->assertSee('bodymap__figure', false);
    }

    public function test_the_screen_renders_with_an_implant_installed(): void
    {
        $implant = $this->implant(BodyRegion::Head);
        $this->fitter()->install($this->character, $implant);

        $this->actingAs($this->user)->get(route('implants.index'))
            ->assertOk()
            ->assertSee($implant->item->displayName());
    }

    /** Surgery moved to the clinics: the screen shows a body and no longer writes one. */
    public function test_the_screen_is_read_only_and_points_at_the_clinics(): void
    {
        $implant = $this->implant(BodyRegion::Spine);

        $this->actingAs($this->user)->get(route('implants.index'))
            ->assertOk()->assertSeeText('fitted and removed at a clinic');

        $this->assertFalse(\Route::has('implants.install'));
        $this->assertFalse(\Route::has('implants.remove'));
        $this->assertNull($implant->fresh()->installed_at);
    }

    public function test_every_refusal_has_a_string(): void
    {
        foreach (ImplantRefusal::cases() as $refusal) {
            $this->assertNotSame("implants.refused.{$refusal->value}", $refusal->message());
        }
    }
}
