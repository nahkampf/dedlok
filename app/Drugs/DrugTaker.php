<?php

declare(strict_types=1);

namespace App\Drugs;

use App\Characters\DownedCause;
use App\Characters\Mood;
use App\Characters\WaitingRoom;
use App\Models\Character;
use App\Models\Dose;
use App\Models\Drug;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Stats\InjuryLedger;
use App\Support\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Taking one. The only door between an item and somebody's blood.
 *
 * ## One dose, one row, and the potency frozen onto it
 *
 * Tolerance is read HERE and written onto the dose, which is the whole reason
 * this mechanic needs nothing from `App\Stats\Resolver`. A dose is worth what
 * it was worth when it was swallowed; a band that climbs an hour later does
 * not reach back into somebody's blood, and the row stays honest about what it
 * is doing.
 *
 * ## Everything in one transaction
 *
 * The copy is spent, the dose begins, the books move and the injury lands
 * together or not at all. A half-resolved dose would be a drug somebody paid
 * for and did not take, or worse, one they took twice.
 *
 * ## Taking one clears the withdrawal and does not cure it
 *
 * The row goes for as long as something of that family is in them. The
 * addiction figure is untouched — feeding a need is not curing it, and only
 * time brings the number down.
 *
 * ## Overdosing is allowed, and it is deterministic
 *
 * A body holds so many of one family at once, and tolerance widens that. Going
 * past it is not refused: it is the mechanic. What it costs is nerve tissue,
 * through the same `neural_damage` injury a freeze and an ICE flatline deepen,
 * so a clinic heals one number rather than three. Nothing rolls, because a
 * drug is a known quantity somebody puts into their own body on purpose — the
 * third one hurting when you knew two was the limit is a mechanic, while the
 * third one hurting sometimes is a slot machine.
 */
final class DrugTaker
{
    public function __construct(
        private readonly DrugLedger $ledger = new DrugLedger,
        private readonly InjuryLedger $injuries = new InjuryLedger,
        ?DamageResolver $damage = null,
        ?WaitingRoom $room = null,
    ) {
        $this->damage = $damage ?? app(DamageResolver::class);
        $this->waitingRoom = $room ?? app(WaitingRoom::class);
    }

    private readonly DamageResolver $damage;

    private readonly WaitingRoom $waitingRoom;

    /**
     * `$inFight` is `Fights`' to pass and nobody else's: a fight-only drug is taken only there, as that round's action,
     * and the fight rather than this class asks the waiting room whether the body survived it.
     */
    public function take(Character $character, InventoryItem $row, bool $inFight = false): DoseResult
    {
        $item = $row->item;
        $drug = $item?->drug;

        if ($drug === null) {
            return DoseResult::refused(DoseRefusal::NotADrug);
        }

        if ((int) $row->character_id !== (int) $character->getKey() || (int) $row->quantity < 1) {
            return DoseResult::refused(DoseRefusal::NotOwned);
        }

        if ($drug->fight_only && ! $inFight) {
            return DoseResult::refused(DoseRefusal::OnlyInAFight);
        }

        return DB::transaction(function () use ($character, $row, $item, $drug, $inFight): DoseResult {
            $now = CarbonImmutable::now();

            // What tolerance has left of it, decided before the books move:
            // the dose being taken is not made weaker by itself.
            $potency = $this->ledger->potencyFor($character, $drug->family);

            // And how far past the body's limit this one is, counted the same
            // way — before this dose is written, because capacity is about
            // what was already in them.
            $overdose = $this->overdoseFor($character, $drug);

            $this->spend($row);

            $dose = Dose::query()->create([
                'character_id' => $character->getKey(),
                'item_id' => $item->getKey(),
                'potency' => $potency,
                'started_at' => $now,
                // A fight-only dose counts rounds (`turns_left`); its expiry is only a backstop for a fight that never
                // finished, and long enough that no real fight outlasts it.
                'expires_at' => $drug->fight_only ? $now->addDay() : $now->addMinutes(max(1, (int) $drug->duration_minutes)),
                'turns_left' => $drug->fight_only ? max(1, (int) $drug->duration_turns) : null,
            ]);

            $this->ledger->record($character, $drug);
            app(Mood::class)->event($character, 'dose');

            // The item may also be a consumable: what it does AT ONCE lands with the dose, and BEFORE any overdose
            // harm, so a lethal dose is not undone by its own medicine. Tolerance does not touch it: it scales
            // only what a dose does while it lasts, and this is a fixed amount somebody set.
            $instant = $this->instantEffects($character, $item);

            if ($overdose->happened()) {
                $this->hurt($character, $drug, $overdose, $inFight);
            }

            // There is something of the family in them now, so whatever they
            // were carrying for going without has stopped being true.
            $this->ledger->reconcile($character->fresh() ?? $character, $drug->family);

            return DoseResult::taken($dose, $potency, $overdose, $instant);
        });
    }

    /**
     * What the consumable half of this item (if it has one) does to the body, once, with the character locked.
     * Applies even at full health: the dose is worth taking for what lasts, and this part simply restores nothing.
     *
     * @return array<string, int>
     */
    private function instantEffects(Character $character, Item $item): array
    {
        $consumable = $item->consumable;

        if ($consumable === null) {
            return [];
        }

        $body = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
        $done = [];

        foreach ($consumable->kinds() as [$kind, $amount]) {
            $done[$kind->value] = ($done[$kind->value] ?? 0) + $kind->apply($body, $amount);
        }

        // The caller holds a model of this character: hand back what changed on the locked one.
        $character->setRawAttributes($body->getAttributes(), true);

        return $done;
    }

    /**
     * The three ways too much hurts, in the order they matter.
     *
     * THE CEILING FIRST, because a flatline below reads the maximum it just
     * lowered — an overdose that stops a heart should not be told the body was
     * bigger than it is. Then the body, through the damage door, where a
     * constitution gets its say. Then, if this family stops hearts and this
     * dose went far enough past the line, the heart stops: the body is emptied
     * outright rather than hit hard, because a flatline is not a matter of
     * degree and nothing resists it.
     *
     * `WaitingRoom::checkFloor()` is asked either way, which is the rule every
     * bad ending in this game follows: whatever emptied somebody, the same
     * door decides they are on the floor.
     */
    private function hurt(Character $character, Drug $drug, Overdose $overdose, bool $inFight = false): void
    {
        $this->injuries->inflict($character, 'neural_damage', $overdose->injury);

        if ($overdose->hitpoints > 0) {
            $this->damage->apply(
                $character,
                Damage::hitpoints($overdose->hitpoints, DamageOrigin::Overdose),
            );
        }

        if ($overdose->lethal) {
            // Emptied rather than hit for a large number: mitigation is about
            // how hard something lands, and a heart that has stopped is not a
            // question of how hard. The same call the ICE attack tag of this
            // name makes about an injury.
            $character->forceFill(['current_hitpoints' => 0])->save();
        }

        // In a fight the fight asks the floor, at its own ending: asking here too would put a body on the waiting
        // screen under a fight that is still live.
        if ($inFight) {
            return;
        }

        $this->waitingRoom->checkFloor($character->fresh() ?? $character, new DownedCause(
            reason: $overdose->lethal ? 'overdose_flatline' : 'overdose',
            damage: $overdose->hitpoints,
            resource: 'current_hitpoints',
            effects: [Text::get($overdose->lethal ? 'drugs.downed_flatline' : 'drugs.downed', [
                'drug' => $drug->item?->displayName() ?? '',
            ])],
        ));
    }

    /**
     * What this dose costs for being one too many.
     *
     * Zero excess is the ordinary answer. The count is what is ALREADY
     * working: the dose about to be taken is the one that breaks the capacity
     * rather than one of the things filling it.
     */
    public function overdoseFor(Character $character, Drug $drug): Overdose
    {
        $active = Dose::query()
            ->where('character_id', $character->getKey())
            ->inEffect()
            ->whereHas('item.drug', static fn ($query) => $query->where('family', $drug->family->value))
            ->count();

        $excess = ($active + 1) - $this->ledger->capacityFor($character, $drug->family);

        if ($excess <= 0) {
            return Overdose::none();
        }

        /** @var array{hp_per_excess?: int, lethal_at?: ?int} $family */
        $family = (array) Config::get("drugs.overdose.families.{$drug->family->value}", []);

        // The drug's own threshold if it has one, the family's otherwise. NULL
        // on both is a family that does not stop hearts.
        $lethalAt = $drug->lethal_at ?? ($family['lethal_at'] ?? null);

        return new Overdose(
            excess: $excess,
            injury: round($excess * (float) Config::get('drugs.overdose.damage_per_excess', 2.0), 2),
            hitpoints: $excess * (int) ($family['hp_per_excess'] ?? 0),
            lethal: $lethalAt !== null && $excess >= (int) $lethalAt,
        );
    }

    /**
     * One copy off the pile.
     *
     * The last one takes the row with it rather than leaving a zero, which is
     * the same call every other thing that spends an item makes.
     */
    private function spend(InventoryItem $row): void
    {
        if ((int) $row->quantity > 1) {
            $row->forceFill(['quantity' => (int) $row->quantity - 1])->save();

            return;
        }

        $row->delete();
    }
}
