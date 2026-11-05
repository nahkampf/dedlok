<?php

declare(strict_types=1);

namespace App\City;

use App\Characters\Flags;
use App\Cyberspace\ExtractedFile;
use App\Cyberspace\Navigator;
use App\Cyberspace\Position;
use App\Cyberspace\Simulator;
use App\Decks\Storage;
use App\Drugs\DrugTaker;
use App\Insurance\Insurance;
use App\Inventory\ItemGranter;
use App\Journal\Journal;
use App\Models\Character;
use App\Models\CharacterEncounter;
use App\Models\EncounterOutcome;
use App\Models\Entity;
use App\Models\FileText;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Run;
use App\Models\Skill;
use App\Notifications\Notifier;
use App\Standing\StandingLedger;
use App\Standing\StandingReason;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Stats\InjuryLedger;
use App\Stats\Resolver;
use App\Support\Qb;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Support\Facades\Config;

/**
 * Applies one outcome row. EVERY KIND GOES THROUGH THE WRITER THAT ALREADY OWNS
 * IT — nothing here writes a column that has an owner. What returns is the line
 * the player is told, or null when there is nothing worth saying.
 *
 * Deleted content is ordinary: an item or entity that has since gone makes its
 * outcome do nothing, so an old encounter can outlive what it points at.
 */
final class EncounterOutcomes
{
    public function __construct(
        private readonly StandingLedger $standing,
        private readonly InjuryLedger $injuries,
        private readonly DamageResolver $damage,
        private readonly DrugTaker $drugs,
    ) {}

    /** Did applying it take away hit points or Synaptic Integrity? The caller asks the floor if so. */
    public function dealsDamage(EncounterOutcome $outcome): bool
    {
        return $outcome->kind === OutcomeKind::Damage;
    }

    public function apply(Character $character, EncounterOutcome $outcome): ?string
    {
        $amount = (float) ($outcome->amount ?? 0);

        return match ($outcome->kind) {
            OutcomeKind::Creds => $this->creds($character, (int) round($amount)),
            OutcomeKind::Item => $this->item($character, $outcome, max(1, (int) round($amount))),
            OutcomeKind::Standing => $this->standing($character, $outcome->entity, $amount),
            OutcomeKind::Heat => $this->heat($character, $amount),
            OutcomeKind::Flag => $this->flag($character, (string) $outcome->ref),
            OutcomeKind::Injury => $this->injury($character, (string) $outcome->ref, $amount),
            OutcomeKind::Damage => $this->hurt($character, (string) $outcome->ref, (int) round(abs($amount))),
            OutcomeKind::Dose => $this->dose($character, $outcome),
            OutcomeKind::Trace => $this->trace($character, (int) round($amount)),
            OutcomeKind::WakeIce => $this->alert($character, owner: false),
            OutcomeKind::Detected => $this->alert($character, owner: true),
            OutcomeKind::GridMove => $this->gridMove($character, (string) $outcome->ref),
            OutcomeKind::Journal => $this->journal($character, $outcome),
            OutcomeKind::JournalDone => $this->journalDone($character, (string) $outcome->ref),
            OutcomeKind::Message => $this->message($character, $outcome),
            OutcomeKind::File => $this->file($character, $outcome),
        };
    }

    /** The run this character is in, or null: a run outcome outside a run does nothing. */
    private function liveRun(Character $character): ?Run
    {
        return $character->current_run_id === null
            ? null
            : Run::query()->live()->find($character->current_run_id);
    }

    private function trace(Character $character, int $points): ?string
    {
        $run = $this->liveRun($character);

        if ($run === null || $points === 0) {
            return null;
        }

        $moved = app(Simulator::class)->shiftTrace($run, $points);

        return $moved === 0 ? null : Text::get($moved > 0 ? 'city.encounter.outcomes.trace_up' : 'city.encounter.outcomes.trace_down');
    }

    private function alert(Character $character, bool $owner): ?string
    {
        $run = $this->liveRun($character);

        if ($run === null || ! app(Simulator::class)->alert($character, $run, $owner)) {
            return null;
        }

        return Text::get($owner ? 'city.encounter.outcomes.detected' : 'city.encounter.outcomes.ice_woken');
    }

    private function journal(Character $character, EncounterOutcome $outcome): ?string
    {
        $written = app(Journal::class)->write(
            $character,
            (string) $outcome->ref,
            $outcome->text,
            $outcome->entry_slug,
            $outcome->entry_text,
        );

        return match (true) {
            $written['note'] => Text::get('city.encounter.outcomes.journal_note'),
            $written['entry'] => Text::get('city.encounter.outcomes.journal_entry'),
            default => null,
        };
    }

    /** Silent: the player finds it in the inbox, now or after its delay. */
    private function message(Character $character, EncounterOutcome $outcome): null
    {
        app(Notifier::class)->story($character, (string) $outcome->ref, (string) $outcome->text, (string) $outcome->entry_text, (int) round((float) $outcome->amount));

        return null;
    }

    /** Silent: a thing ticked off in a journal is something the player sees there. */
    private function journalDone(Character $character, string $slug): null
    {
        if ($slug !== '') {
            app(Journal::class)->complete($character, $slug);
        }

        return null;
    }

    private function gridMove(Character $character, string $ref): ?string
    {
        if (preg_match('/^(\d+),\s*(\d+)$/', trim($ref), $m) !== 1) {
            return null;
        }

        $moved = Navigator::fromConfig(app(Resolver::class))->relocate($character, new Position((int) $m[1], (int) $m[2]));

        return $moved ? Text::get('city.encounter.outcomes.moved') : null;
    }

    private function creds(Character $character, int $amount): ?string
    {
        if ($amount === 0) {
            return null;
        }

        Wallet::move($character, $amount, Reason::Encounter, Text::get('wallet.notes.encounter'));

        return Text::get($amount > 0 ? 'city.encounter.outcomes.creds_gain' : 'city.encounter.outcomes.creds_loss', [
            'amount' => number_format(abs($amount)),
        ]);
    }

    private function item(Character $character, EncounterOutcome $outcome, int $quantity): string
    {
        $item = $outcome->item;

        if ($item === null) {
            return Text::get('city.encounter.outcomes.item_gone');
        }

        // Data is a door the deck's storage guards, like every other that adds it.
        if (! Storage::fits($character, Storage::sizeOf($item) * $quantity)) {
            return Text::get('city.encounter.outcomes.item_no_room', ['item' => $item->displayName()]);
        }

        ItemGranter::grant($character, $item, $quantity);

        return Text::get('city.encounter.outcomes.item', ['item' => $item->displayName(), 'quantity' => $quantity]);
    }

    /**
     * Leave a file on the deck: sealed under an encryption, or not. A catalogue item asks the deck for room like the item
     * outcome does. A TEXT does not: a quest's words are never lost to a full deck (its size still counts in what is used).
     */
    private function file(Character $character, EncounterOutcome $outcome): ?string
    {
        $encryption = (string) $outcome->ref;
        $skill = $encryption === '' ? null : Skill::query()->where('slug', $encryption)->first();

        // An encryption that has since gone leaves nothing rather than something unsealed.
        if ($encryption !== '' && $skill === null) {
            return null;
        }

        $item = $outcome->item;

        if ($outcome->item_id !== null) {
            if ($item === null) {
                return Text::get('city.encounter.outcomes.item_gone');
            }

            $size = Storage::sizeOf($item);

            if (! Storage::fits($character, $size)) {
                return Text::get('city.encounter.outcomes.item_no_room', ['item' => $item->displayName()]);
            }

            if ($skill === null) {
                ItemGranter::grant($character, $item, 1);
            } else {
                $this->sealed($character, new ExtractedFile(label: $item->displayName(), sizeQb: $size, encryptionSkillId: $skill->getKey(), valueSealed: 0, valueBroken: 0, payloadItemId: $item->getKey()));
            }

            return Text::get('city.encounter.outcomes.file', ['title' => $item->displayName()]);
        }

        $title = (string) $outcome->text;
        $words = FilePlaceholders::fill((string) $outcome->body, $character, CharacterEncounter::query()->find($character->current_encounter_id)?->door);

        if ($title === '' || trim($words) === '') {
            return null;
        }

        $file = new ExtractedFile(label: $title, sizeQb: Qb::of($outcome->amount ?? 0), encryptionSkillId: $skill?->getKey(), valueSealed: 0, valueBroken: 0, payloadText: true);
        $slug = $skill === null ? ExtractedFile::TEXT_SLUG : ExtractedFile::SEALED_SLUG;
        $holder = Item::query()->where('slug', $slug)->first();

        if ($holder === null) {
            return null;
        }

        $row = $character->inventory()->create(['item_id' => $holder->getKey(), 'parent_id' => null, 'quantity' => 1, 'extracted_file' => $file->toArray()]);
        FileText::create(['inventory_item_id' => $row->getKey(), 'title' => $title, 'body' => $words, 'ice' => $outcome->ice]);

        return Text::get('city.encounter.outcomes.file', ['title' => $title]);
    }

    /** A sealed dump carrying what it turns into. */
    private function sealed(Character $character, ExtractedFile $file): void
    {
        $dump = Item::query()->where('slug', ExtractedFile::SEALED_SLUG)->first();

        $dump === null || $character->inventory()->create(['item_id' => $dump->getKey(), 'parent_id' => null, 'quantity' => 1, 'extracted_file' => $file->toArray()]);
    }

    private function standing(Character $character, ?Entity $entity, float $amount): ?string
    {
        if ($entity === null || $amount === 0.0) {
            return null;
        }

        $this->standing->record($character, $entity, StandingReason::Encounter, $amount);

        return Text::get($amount > 0 ? 'city.encounter.outcomes.standing_gain' : 'city.encounter.outcomes.standing_loss', [
            'entity' => $entity->name,
        ]);
    }

    /** Heat has no single writer; this is the one place an encounter touches it. Floors at nothing. */
    private function heat(Character $character, float $amount): ?string
    {
        if ($amount === 0.0) {
            return null;
        }

        $character->forceFill(['heat' => max(0.0, (float) $character->heat + $amount)])->save();

        return Text::get($amount > 0 ? 'city.encounter.outcomes.heat_gain' : 'city.encounter.outcomes.heat_loss', [
            'amount' => rtrim(rtrim(number_format(abs($amount), 1), '0'), '.'),
        ]);
    }

    private function flag(Character $character, string $flag): null
    {
        if ($flag !== '') {
            Flags::set($character, $flag);
        }

        return null;
    }

    private function injury(Character $character, string $key, float $points): ?string
    {
        if ($key === '' || $points === 0.0) {
            return null;
        }

        if ($points > 0) {
            $this->injuries->inflict($character, $key, $points);
            (new Insurance)->claimInjury($character, $key, $points);
        } else {
            $this->injuries->heal($character, $key, abs($points));
        }

        return Text::get($points > 0 ? 'city.encounter.outcomes.injury_gain' : 'city.encounter.outcomes.injury_loss', [
            'injury' => (string) Config::get("effects.{$key}.label", $key),
        ]);
    }

    /**
     * Take a drug: grant one copy, then take it at once, through the one door.
     *
     * `DrugTaker` takes an inventory row and spends a copy of it, and an encounter has none, so one is
     * made — in the same transaction, so the copy is never left behind. Everything a dose is then
     * applies as if the player had chosen it: tolerance weakens it, addiction grows, and too many of
     * a family overdoses, which is its own damage, injury and (for lethal families) flatline. The
     * taker asks the floor itself.
     */
    private function dose(Character $character, EncounterOutcome $outcome): ?string
    {
        $item = $outcome->item;

        if ($item === null) {
            return Text::get('city.encounter.outcomes.item_gone');
        }

        if ($item->drug === null || ItemGranter::grant($character, $item, 1) < 1) {
            return null;
        }

        // The pile or copy just granted: loose, and the newest if copies do not stack.
        $row = InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->where('item_id', $item->getKey())
            ->loose()
            ->latest('id')
            ->first();

        $result = $row === null ? null : $this->drugs->take($character, $row);

        if ($result === null || ! $result->succeeded()) {
            return null;
        }

        $line = Text::get('city.encounter.outcomes.dose', ['item' => $item->displayName()]);

        return $result->overdosed()
            ? $line.' '.Text::get('city.encounter.outcomes.dose_overdose')
            : $line;
    }

    private function hurt(Character $character, string $resource, int $amount): ?string
    {
        if ($amount < 1) {
            return null;
        }

        $hit = $resource === 'hitpoints'
            ? Damage::hitpoints($amount, DamageOrigin::Encounter)
            : Damage::synapticIntegrity($amount, DamageOrigin::Encounter);

        $result = $this->damage->apply($character, $hit);

        return Text::get('city.encounter.outcomes.damage', ['amount' => $result->taken]);
    }
}
