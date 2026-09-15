<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Navigator;
use App\Decks\DeckEquipper;
use App\Decks\FittingRefusal;
use App\Decks\Hardware;
use App\Decks\ModuleFitter;
use App\Decks\Variant;
use App\Models\Character;
use App\Models\DeckFitting;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * THE DECK IS LOCKED WHILE YOU ARE JACKED IN.
 *
 * You cannot fit a module, take one out, strip the deck, unequip it, or
 * destroy it or anything fitted in it while standing inside it. Three writers
 * enforce that — ModuleFitter, DeckEquipper and the destroy path — by asking
 * one question, `Character::isLockedInCyberspace()`.
 *
 * The other half matters just as much and has its own tests below: a deck in
 * the STASH is not in play, and everything that is not the live deck carries on
 * exactly as before. A lock that stopped a player tidying their inventory would
 * be a bug wearing a rule's clothes.
 */
final class DeckLockTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private User $user;

    private Character $character;

    private InventoryItem $deck;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.view_range', 2);

        $this->user = User::factory()->create();
        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['user_id' => $this->user->id, 'archetype' => 3],
        ));

        $this->deck = $this->equipReadyDeck($this->character);
    }

    private function jackIn(): void
    {
        Navigator::fromConfig(app(Resolver::class))->jackIn($this->character);
        $this->character->refresh();
    }

    private function fitter(): ModuleFitter
    {
        return app(ModuleFitter::class);
    }

    // ---- locked -----------------------------------------------------------

    public function test_a_module_cannot_be_taken_out_while_jacked_in(): void
    {
        $this->jackIn();

        $slot = $this->deck->item->deckSlots()->where('kind', 'ram')->firstOrFail();
        $result = $this->fitter()->unfit($this->character, $this->deck, $slot);

        $this->assertSame(FittingRefusal::JackedIn, $result->refusal);
        $this->assertSame(4, DeckFitting::query()->where('deck_id', $this->deck->id)->count());
    }

    public function test_the_deck_cannot_be_stripped_while_jacked_in(): void
    {
        $this->jackIn();

        $result = $this->fitter()->strip($this->character, $this->deck);

        $this->assertSame(FittingRefusal::JackedIn, $result->refusal);
        $this->assertSame(4, DeckFitting::query()->count());
    }

    public function test_a_module_cannot_be_fitted_while_jacked_in(): void
    {
        // An empty socket to aim at, fitted before jacking in.
        $spare = $this->ownedBy($this->character, Item::factory()->module(Hardware::Expansion, null)->create());
        $this->jackIn();

        $slot = $this->deck->item->deckSlots()->where('kind', 'ram')->firstOrFail();

        $this->assertSame(
            FittingRefusal::JackedIn,
            $this->fitter()->fit($this->character, $this->deck, $slot, $spare)->refusal,
        );
        $this->assertSame(
            FittingRefusal::JackedIn,
            $this->fitter()->fitToDeck($this->character, $spare)->refusal,
        );
    }

    public function test_the_deck_cannot_be_unequipped_while_jacked_in(): void
    {
        $this->jackIn();

        $result = app(DeckEquipper::class)->unequip($this->character, false, $this->fitter());

        $this->assertSame(FittingRefusal::JackedIn, $result->refusal);
        $this->assertSame($this->deck->id, $this->character->fresh()->equipped_deck_id);
    }

    /** Equipping SWAPS, so it would take the live deck out from under you. */
    public function test_another_deck_cannot_be_equipped_while_jacked_in(): void
    {
        $other = $this->ownedBy($this->character, Item::factory()->cyberdeck(['cpu_socket_k' => 1, 'psu' => 1])->create());
        $this->jackIn();

        $result = app(DeckEquipper::class)->equip($this->character, $other);

        $this->assertSame(FittingRefusal::JackedIn, $result->refusal);
        $this->assertSame($this->deck->id, $this->character->fresh()->equipped_deck_id);
    }

    public function test_the_live_deck_cannot_be_destroyed_while_jacked_in(): void
    {
        $this->jackIn();

        $this->actingAs($this->user)
            ->post(route('inventory.destroy', $this->deck))
            ->assertSessionHas('error', FittingRefusal::JackedIn->message());

        $this->assertNotNull($this->deck->fresh());
    }

    public function test_a_module_in_the_live_deck_cannot_be_destroyed_while_jacked_in(): void
    {
        $this->jackIn();

        $module = DeckFitting::query()->where('deck_id', $this->deck->id)->firstOrFail()->module;

        $this->actingAs($this->user)
            ->post(route('inventory.destroy', $module))
            ->assertSessionHas('error', FittingRefusal::JackedIn->message());

        $this->assertNotNull($module->fresh());
    }

    // ---- not locked --------------------------------------------------------

    /** Everything works again the moment you are out. */
    public function test_jacking_out_releases_the_lock(): void
    {
        $this->jackIn();
        Navigator::fromConfig(app(Resolver::class))->jackOut($this->character);
        $this->character->refresh();

        $this->assertTrue($this->fitter()->strip($this->character, $this->deck)->succeeded());
        $this->assertSame(0, DeckFitting::query()->count());
    }

    /**
     * A spare deck is not in play. The lock is about the machine you are
     * standing inside, not about decks in general.
     */
    public function test_a_deck_in_the_stash_is_untouched_by_the_lock(): void
    {
        $spareItem = Item::factory()->cyberdeck(['cpu_socket_k' => 1, 'psu' => 1])->create();
        $spare = $this->ownedBy($this->character, $spareItem);
        $chip = $this->ownedBy($this->character, Item::factory()->module(Hardware::Cpu, Variant::SocketK, measure: 10)->create());

        $this->jackIn();

        $slot = $spareItem->deckSlots()->where('kind', 'cpu')->firstOrFail();

        $this->assertTrue($this->fitter()->fit($this->character, $spare, $slot, $chip)->succeeded());
        $this->assertTrue($this->fitter()->strip($this->character, $spare)->succeeded());
    }

    /** The stash still opens, and other things can still be destroyed. */
    public function test_ordinary_items_can_still_be_destroyed_while_jacked_in(): void
    {
        $junk = $this->ownedBy($this->character, Item::factory()->physical()->create());
        $this->jackIn();

        $this->actingAs($this->user)
            ->post(route('inventory.destroy', $junk))
            ->assertSessionHas('status');

        $this->assertNull($junk->fresh());
    }

    public function test_nothing_is_locked_when_you_are_not_jacked_in(): void
    {
        $this->assertFalse($this->character->isJackedIn());
        $this->assertFalse($this->character->isLockedInCyberspace($this->deck));

        $module = DeckFitting::query()->where('deck_id', $this->deck->id)->firstOrFail()->module;
        $this->assertFalse($this->character->isLockedInCyberspace($module));
    }

    public function test_the_lock_names_the_deck_and_its_modules_and_nothing_else(): void
    {
        $junk = $this->ownedBy($this->character, Item::factory()->physical()->create());
        $spare = $this->ownedBy($this->character, Item::factory()->cyberdeck(['cpu_socket_k' => 1, 'psu' => 1])->create());
        $this->jackIn();

        $module = DeckFitting::query()->where('deck_id', $this->deck->id)->firstOrFail()->module;

        $this->assertTrue($this->character->isLockedInCyberspace($this->deck));
        $this->assertTrue($this->character->isLockedInCyberspace($module));
        $this->assertFalse($this->character->isLockedInCyberspace($junk));
        $this->assertFalse($this->character->isLockedInCyberspace($spare));
    }
}
