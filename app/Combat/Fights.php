<?php

declare(strict_types=1);

namespace App\Combat;

use App\Characters\DownedCause;
use App\Characters\Fatigue;
use App\Characters\Mood;
use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\City\EncounterRunner;
use App\Combat\Fight\Action;
use App\Combat\Fight\ActionKind;
use App\Combat\Fight\Arm;
use App\Combat\Fight\CharacterMitigation;
use App\Combat\Fight\CombatantBuilder;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\FightMitigation;
use App\Combat\Fight\FightState;
use App\Combat\Fight\NpcMitigation;
use App\Combat\Fight\Result;
use App\Combat\Fight\Side;
use App\Drugs\DrugTaker;
use App\Items\Consumer;
use App\Items\UseEffect;
use App\Items\UseRefusal;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\Consumable;
use App\Models\Dose;
use App\Models\Fight;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Npc;
use App\Support\Points;
use App\Support\Text;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that writes a fight, and `characters.current_fight_id`. docs/COMBAT.md, step 4.
 *
 * It is the seam between the pure engine and the game. The engine plays a plain-data snapshot and
 * writes nothing; this builds the snapshot, runs a round of it, and applies what happened to the
 * real character — hit points and Synaptic Integrity through `Character::takeDamage()`, rounds
 * through `WeaponLoader`, the ending through the waiting room.
 *
 * ## One transaction a round, both rows locked
 *
 * A fight half-played and left unsaved is a fight the next request would have to guess the middle of.
 * The character and the fight are locked, so two presses of the same button serialise, and the second
 * finds the fight already moved on (or over) and changes nothing.
 *
 * ## One source of truth for the player's body
 *
 * Side A's hit points and SI are overwritten from the locked character row at the START of every
 * round, then the round's drop is applied with `takeDamage()` — every round, so the topbar dial stays
 * true and anything that regenerates in between (SI does, every minute) is kept rather than wiped.
 * Never `DamageResolver::apply()`: the mitigation already resolved the hit, so applying would resolve
 * it twice. Side B is never rebuilt from its NPC row; it stays the stored snapshot.
 *
 * ## Endings
 *
 * A round that ends the fight hands it to `FightEnding` (a win pays, a loss costs, getting away is
 * nothing), appends what it did to the log, and only then asks the waiting room whether the player went
 * down — damage, consequence, the floor. `FightEnding` is resolved from the container, never injected, so
 * a test can pin its own die without disturbing the fight's.
 */
final class Fights
{
    /** @param  callable(): float  $die  uniform in [0, 1) */
    public function __construct(private readonly FightEngine $engine, private readonly mixed $die, private readonly Fatigue $fatigue = new Fatigue) {}

    public static function fromConfig(?Closure $die = null): self
    {
        return new self(
            new FightEngine(CheckRoller::fromConfig(), static fn (int $min, int $max): int => random_int($min, $max)),
            $die ?? static fn (): float => random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX,
        );
    }

    /**
     * Open a fight against an opponent. The enemy acts first, as in an encounter; nothing happens until
     * the player takes their turn. `$return` is where the encounter that started it takes them back. It refuses a character who cannot be in one — and NOT one in an
     * encounter, because a fight opens inside one.
     */
    public function open(Character $character, Npc $npc, ?FightReturn $return = null): Fight|FightRefusal
    {
        return DB::transaction(function () use ($character, $npc, $return): Fight|FightRefusal {
            $locked = Character::query()->lockForUpdate()->findOrFail($character->getKey());

            $refusal = match (true) {
                app(WaitingRoom::class)->isDown($locked) => FightRefusal::Down,
                $locked->isJackedIn() => FightRefusal::JackedIn,
                $locked->current_run_id !== null => FightRefusal::InRun,
                $locked->current_hunt_id !== null => FightRefusal::Hunted,
                $locked->current_fight_id !== null => FightRefusal::InFight,
                CityActivity::query()->whereKey($locked->getKey())->exists() => FightRefusal::Busy,
                default => null,
            };

            if ($refusal !== null) {
                return $refusal;
            }

            $state = FightState::opening(CombatantBuilder::forCharacter($locked), CombatantBuilder::forNpc($npc));

            $fight = Fight::create([
                'character_id' => $locked->getKey(),
                'npc_id' => $npc->getKey(),
                'opponent_name' => $npc->name,
                'grade' => $npc->grade,
                'entity_id' => $npc->entity_id,
                'tags' => $npc->tags,
                'loot' => $npc->loot()->get()->map(static fn ($drop): array => [
                    'item_id' => $drop->item_id, 'chance' => $drop->chance, 'min' => $drop->min_quantity, 'max' => $drop->max_quantity,
                ])->all() ?: null,
                'resistances' => $npc->resistances,
                'slot_resistances' => $npc->slot_resistances,
                'ranged_row_id' => $locked->equipped_ranged_id,
                'melee_row_id' => $locked->equipped_melee_id,
                'encounter_row_id' => $return?->encounterRowId,
                'win_key' => $return?->win,
                'lose_key' => $return?->lose,
                'flee_key' => $return?->flee,
                'state' => $state->toArray(),
            ]);

            $locked->forceFill(['current_fight_id' => $fight->getKey()])->save();
            $character->forceFill(['current_fight_id' => $fight->getKey()]);

            return $fight;
        });
    }

    /**
     * One round: the player's chosen action, and everything the engine does around it.
     *
     * A press on a fight that has already ended (or was never there) is a refusal and changes nothing.
     */
    public function act(Character $character, Action $action): Fight|FightRefusal
    {
        return DB::transaction(function () use ($character, $action): Fight|FightRefusal {
            $locked = Character::query()->lockForUpdate()->findOrFail($character->getKey());
            $fight = $locked->current_fight_id === null ? null : Fight::query()->lockForUpdate()->find($locked->current_fight_id);

            if ($fight === null || ! $fight->isLive()) {
                return FightRefusal::NoFight;
            }

            // A drug is used from the belt, and only a fight-only one: checked before anything moves, so a refusal
            // costs nothing and the round is not played.
            $use = null;

            if ($action->kind === ActionKind::Use) {
                $use = $this->usable($locked, (int) $action->round);

                if ($use === null) {
                    return FightRefusal::CannotUse;
                }

                // A consumable that would do nothing is refused before the turn is spent, as the stash refuses it.
                if ($use->item?->drug === null && app(Consumer::class)->refusalFor($locked, $use->item->consumable, $fight->snapshot()->a->bleeding()) !== null) {
                    return FightRefusal::NothingToDo;
                }
            }

            // The player's side is rebuilt below, so a weapon that is not the one this fight began with would be
            // picked up silently: it is loud instead, and the whole round is rolled back.
            if ((int) $fight->ranged_row_id !== (int) $locked->equipped_ranged_id) {
                throw new \LogicException('The ranged weapon this fight began with is no longer in hand.');
            }

            if ((int) $fight->melee_row_id !== (int) $locked->equipped_melee_id) {
                throw new \LogicException('The close weapon this fight began with is no longer in hand.');
            }

            $before = $fight->snapshot();

            // What the doses in effect do at the START of the round, before anybody acts: restore or cost hit points.
            $before = $before->withLog($this->tickDoses($locked, $before->round));

            // Overwrite the player's side from the real body: the one source of truth. Its aim, damage, defence and
            // pursuit are resolved afresh, every round, because a dose changes them mid-fight; only what the fight
            // itself put on the player (statuses from the opponent's riders) is kept. Hit points and SI are the body's.
            $body = CombatantBuilder::forCharacter($locked);
            $before = $before->with(Side::A, $body->withStatuses($before->a->statuses));

            $after = $this->engine->round(
                $before,
                ['a' => $action],
                $this->die,
                new FightMitigation(new CharacterMitigation($locked), new NpcMitigation((array) $fight->resistances, (array) $fight->slot_resistances)),
            );

            $this->applyToCharacter($locked, $fight, $before, $after);

            // A round played tires, however it went. The clock that lowers fatigue is stopped for a fight.
            $this->fatigue->charge($locked, 'fight_round');

            // A fight still going: the doses in effect lose a round, and then (so the new one starts with all its
            // rounds) the one the player used this turn is taken.
            if ($after->result === null) {
                $this->ageDoses($locked);

                // Only a pass of the engine's check (and not a stunned turn or a fumble) gets the item out.
                if ($use !== null && $after->used) {
                    $after = $this->takeDose($locked, $use, $after);
                }
            }

            $effects = [];
            $settled = [];

            if ($after->result !== null) {
                // What the ending says it was, before the ending's own lines go after it.
                $effects = array_values(array_filter([$after->log[array_key_last($after->log)]['text'] ?? null]));
                $settled = app(FightEnding::class)->settle($locked, $fight, $after->result);
                $round = $after->round;

                // Marked, so the controller can flash exactly these without re-deriving what was settled.
                $after = $after->withLog(array_map(static fn (array $line): array => ['round' => $round, 'ending' => true] + $line, $settled));
                $effects = [...$effects, ...array_column($settled, 'text')];
            }

            $fight->forceFill(['state' => $after->toArray()]);

            if ($after->result !== null) {
                $this->finish($locked, $fight, $after, $effects, array_column($settled, 'text'));
            }

            $fight->save();
            $character->forceFill($locked->only(['current_fight_id', 'current_hitpoints', 'current_synaptic_integrity', 'current_fatigue', 'creds']));

            return $fight;
        });
    }

    /**
     * End a live fight as the player's loss, with no round played: a collapse takes them out of it. The same ending a
     * lost round has (doses, the clock, the encounter's loss screen), but nobody is paid, mugged or maimed for it.
     */
    public function forfeit(Character $locked): void
    {
        $fight = $locked->current_fight_id === null ? null : Fight::query()->lockForUpdate()->find($locked->current_fight_id);

        if ($fight === null || ! $fight->isLive()) {
            return;
        }

        $state = $fight->snapshot()->ended(Result::ADown);
        $fight->forceFill(['state' => $state->toArray()]);
        $this->finish($locked, $fight, $state, [], []);
        $fight->save();
    }

    /**
     * What a screen needs to draw the buttons, from the stored state: every chance quoted, and which
     * actions are on offer. Reads only; the numbers are the ones the next roll will use.
     *
     * @return array{state: FightState, attack: list<float>, flight: float, closing: float, can_attack: bool, can_create_distance: bool, can_close_in: bool, can_disengage: bool, loads: list<array{key: string, name: string, stock: int}>, uses: list<array{id: int, name: string, turns: int, hitpoints: int, quantity: int}>, doses: list<array{name: string, turns: int}>}
     */
    public function options(Fight $fight): array
    {
        $state = $fight->snapshot();
        $arm = $state->a->ranged;
        $loads = [];

        foreach ($arm?->specs ?? [] as $key => $spec) {
            if ($arm !== null && $arm->canLoad((string) $key)) {
                $loads[] = ['key' => (string) $key, 'name' => $spec->name, 'stock' => (int) $spec->stock];
            }
        }

        return [
            'state' => $state,
            'explain' => ChanceExplainer::for($fight->character, $state),
            'attack' => $this->engine->quote($state, Side::A),
            'flight' => $this->engine->quoteFlight($state, Side::A),
            'closing' => $this->engine->quoteClosing($state, Side::A),
            'use_chance' => $this->engine->quoteUse($state, Side::A),
            'headbutt_chance' => $this->engine->quoteHeadbutt($state, Side::A),
            'takedown_chance' => $this->engine->quoteTakedown($state, Side::A),
            'can_headbutt' => $this->engine->legal($state, Side::A, Action::headbutt()),
            'can_takedown' => $this->engine->legal($state, Side::A, Action::takedown()),
            'can_close_in' => $this->engine->legal($state, Side::A, Action::closeIn()),
            'can_disengage' => $this->engine->legal($state, Side::A, Action::disengage()),
            'can_attack' => $this->engine->canAttack($state, Side::A),
            'can_create_distance' => $this->engine->legal($state, Side::A, Action::createDistance()),
            'loads' => $loads,
            'uses' => $this->belted((int) $fight->character_id)->map(fn (InventoryItem $row): array => [
                'id' => (int) $row->getKey(),
                'name' => (string) $row->item?->displayName(),
                'drug' => $row->item?->drug !== null,
                'effects' => $row->item?->drug === null ? (string) $row->item?->consumable?->describe() : '',
                // A consumable that would do nothing right now is shown and cannot be pressed.
                'ready' => $row->item?->drug !== null || app(Consumer::class)->refusalFor($fight->character, $row->item->consumable, $state->a->bleeding()) === null,
                'turns' => (int) $row->item?->drug?->duration_turns,
                'hitpoints' => (int) $row->item?->drug?->turn_hitpoints,
                'quantity' => (int) $row->quantity,
            ])->values()->all(),
            'doses' => Dose::query()->where('character_id', $fight->character_id)->where('turns_left', '>', 0)->with('item')->get()
                ->map(static fn (Dose $dose): array => ['name' => (string) $dose->item?->displayName(), 'turns' => (int) $dose->turns_left])->all(),
        ];
    }

    /**
     * The fight-only drugs on the belt: the only things a fight can use. Not the stash (you cannot reach into it
     * mid-fight) and not an ordinary drug (those are taken outside one).
     *
     * @return Collection<int, InventoryItem>
     */
    private function belted(int $characterId): Collection
    {
        return InventoryItem::query()
            ->where('character_id', $characterId)
            ->whereNotNull('belt_slot')
            ->where('quantity', '>=', 1)
            // A fight-only drug, or a consumable (that is not a drug) its author allowed in a fight.
            ->where(static fn ($rows) => $rows
                ->whereHas('item.drug', static fn ($query) => $query->where('fight_only', true))
                ->orWhere(static fn ($plain) => $plain
                    ->whereDoesntHave('item.drug')
                    ->whereHas('item.consumable', static fn ($query) => $query->where('usable_in_fight', true))))
            ->with('item.drug', 'item.consumable')
            ->orderBy('belt_slot')
            ->get();
    }

    private function usable(Character $character, int $rowId): ?InventoryItem
    {
        return $this->belted((int) $character->getKey())->firstWhere('id', $rowId);
    }

    /**
     * Each dose in effect, at the start of a round: hit points restored (scaled by the dose's potency, so tolerance
     * costs the benefit here too) or lost (the drug's own price, flat and not mitigable like an overdose's injury).
     *
     * @return list<array{round: int, text: string, tone: string}>
     */
    private function tickDoses(Character $character, int $round): array
    {
        $lines = [];
        $doses = Dose::query()->where('character_id', $character->getKey())->where('turns_left', '>', 0)->with('item.drug')->orderBy('id')->get();

        foreach ($doses as $dose) {
            $amount = (int) $dose->item?->drug?->turn_hitpoints;
            $name = (string) $dose->item?->displayName();

            if ($amount > 0) {
                $gained = $character->recover('current_hitpoints', Points::of($amount * (float) $dose->potency));
                $lines[] = ['round' => $round, 'text' => Text::get('combat.fight.log.dose_heals', ['item' => $name, 'amount' => $gained]), 'tone' => 'ok'];
            } elseif ($amount < 0) {
                $character->takeDamage('current_hitpoints', -$amount);
                $lines[] = ['round' => $round, 'text' => Text::get('combat.fight.log.dose_costs', ['item' => $name, 'amount' => -$amount]), 'tone' => 'crit'];
            }
        }

        return $lines;
    }

    /** A round has passed: every fight dose has one fewer, and a spent one is gone. */
    private function ageDoses(Character $character): void
    {
        $rows = Dose::query()->where('character_id', $character->getKey())->where('turns_left', '>', 0);
        $rows->decrement('turns_left');

        Dose::query()->where('character_id', $character->getKey())->where('turns_left', 0)->delete();
    }

    /**
     * Take the belted drug the player spent this turn on, through `DrugTaker` (books, tolerance, overdose and the
     * instant half all apply), and say so. The body has changed, so the state's hit points follow it; a body that did
     * not survive the dose ends the fight as a loss, and `finish()` asks the floor.
     */
    private function takeDose(Character $character, InventoryItem $row, FightState $after): FightState
    {
        $round = max(1, $after->round - 1);
        $name = (string) $row->item?->displayName();
        $drug = $row->item?->drug;

        if ($drug === null) {
            return $this->spendConsumable($character, $row, $after, $round, $name);
        }

        $result = app(DrugTaker::class)->take($character, $row, inFight: true);

        if (! $result->succeeded()) {
            return $after->withLog([['round' => $round, 'text' => $result->refusal->message(), 'tone' => 'crit']]);
        }

        $said = [Text::get('combat.fight.log.dose', ['who' => $after->a->name, 'item' => $name])];
        [$after, $eased] = $this->ease($after, $row->item?->consumable);

        foreach ($result->instant as $kind => $amount) {
            $said[] = UseEffect::from($kind)->done($kind === UseEffect::StopBleeding->value ? $eased : $amount);
        }

        $said[] = Text::get('combat.fight.log.dose_lasts', ['turns' => (int) $drug?->duration_turns]);
        $lines = [['round' => $round, 'text' => implode(' ', $said), 'tone' => 'ok']];

        if ($result->overdosed()) {
            $lines[] = ['round' => $round, 'text' => Text::get('combat.fight.log.dose_overdose'), 'tone' => 'crit'];
        }

        $after = $after->with(Side::A, $after->a->with(hp: $character->current_hitpoints, si: $character->current_synaptic_integrity))->withLog($lines);

        return $after->a->down() ? $after->ended(Result::ADown) : $after;
    }

    /**
     * What a used item does about the wound: bleeding lives in the fight, so the fight takes the levels off its own state.
     *
     * @return array{0: FightState, 1: int} the state, and how many levels came off
     */
    private function ease(FightState $after, ?Consumable $consumable): array
    {
        $levels = 0;

        foreach ($consumable?->kinds() ?? [] as [$kind, $amount]) {
            $levels += $kind === UseEffect::StopBleeding ? $amount : 0;
        }

        if ($levels === 0) {
            return [$after, 0];
        }

        [$side, $eased] = $after->a->eased($levels);

        return [$after->with(Side::A, $side), $eased];
    }

    /** A consumable allowed in a fight, used from the belt: the same copy-spending and effects as the stash, said in the log. */
    private function spendConsumable(Character $character, InventoryItem $row, FightState $after, int $round, string $name): FightState
    {
        $done = app(Consumer::class)->use($character, $row, inFight: true, bleeding: $after->a->bleeding());

        if ($done instanceof UseRefusal) {
            return $after->withLog([['round' => $round, 'text' => $done->message(), 'tone' => 'crit']]);
        }

        $said = [Text::get('combat.fight.log.dose', ['who' => $after->a->name, 'item' => $name])];
        [$after, $eased] = $this->ease($after, $row->item?->consumable);

        foreach ($done as $kind => $amount) {
            $said[] = UseEffect::from($kind)->done($kind === UseEffect::StopBleeding->value ? $eased : $amount);
        }

        return $after->with(Side::A, $after->a->with(hp: $character->current_hitpoints, si: $character->current_synaptic_integrity))
            ->withLog([['round' => $round, 'text' => implode(' ', $said), 'tone' => 'ok']]);
    }

    /**
     * Put what the round did to the player's side onto the real character: the drop in hit points and
     * SI, and the rounds fired or loaded onto the weapon row they came from.
     */
    private function applyToCharacter(Character $character, Fight $fight, FightState $before, FightState $after): void
    {
        $character->takeDamage('current_hitpoints', Points::of(max(0, $before->a->hp - $after->a->hp)));
        $character->takeDamage('current_synaptic_integrity', Points::of(max(0, $before->a->si - $after->a->si)));

        $this->applyAmmunition($character, $fight, $before->a->ranged, $after->a->ranged);
    }

    /**
     * Compare the weapon before and after the round: a different round or more of it is a Load, fewer
     * rounds is shots fired. Both go through `WeaponLoader`, the only writer of the magazine, and a
     * Load is checked against what the engine says it did — a difference means a bug, and is loud.
     */
    private function applyAmmunition(Character $character, Fight $fight, ?Arm $before, ?Arm $after): void
    {
        if ($before === null || $after === null || $before->rounds === null || $after->rounds === null) {
            return;
        }

        $loaded = $after->loaded !== $before->loaded || $after->rounds > $before->rounds;

        if (! $loaded && $after->rounds === $before->rounds) {
            return;
        }

        $row = $this->weaponRow($character, $fight);
        $loader = app(WeaponLoader::class);

        if ($loaded) {
            $item = Item::query()->with('ammunition')->find((int) $after->loaded) ?? throw new \LogicException('The round that was loaded no longer exists.');
            $result = $loader->load($character, $row, $item);

            if ($result !== true) {
                throw new \LogicException('The fight loaded a round the stash could not supply: '.$result->value.'.');
            }

            if ($row->fresh()->rounds() !== $after->rounds) {
                throw new \LogicException("The magazine holds {$row->fresh()->rounds()} after loading and the fight says {$after->rounds}.");
            }

            return;
        }

        if ($loader->spend($character, $row, $before->rounds - $after->rounds) !== true) {
            throw new \LogicException('The fight fired more rounds than the weapon held.');
        }
    }

    /** The ranged weapon's row, checked to be the one this fight began with and still the one in hand. */
    private function weaponRow(Character $character, Fight $fight): InventoryItem
    {
        $row = $fight->ranged_row_id === null ? null : InventoryItem::query()->find($fight->ranged_row_id);

        if ($row === null || (int) $character->equipped_ranged_id !== (int) $row->getKey() || $row->character_id !== $character->getKey()) {
            throw new \LogicException('The ranged weapon this fight began with is no longer in hand.');
        }

        return $row;
    }

    /**
     * The fight is over: stamp it, let go of the character, and ask the floor if they went down.
     *
     * @param  list<string>  $effects  what the ending said and did, for the waiting screen to read back
     * @param  list<string>  $settled  only what `FightEnding` did (pay, drops, what was taken), for the encounter's log
     */
    private function finish(Character $character, Fight $fight, FightState $state, array $effects, array $settled): void
    {
        $fight->forceFill(['result' => $state->result, 'resolved_at' => now()]);

        // Fight-only doses end with the fight, however it ended.
        Dose::query()->where('character_id', $character->getKey())->whereNotNull('turns_left')->delete();

        // And the clock stopped while it lasted: every ordinary dose that was in effect when it opened (or ran out
        // during it) gets back the time the fight took, so the pause is real once the fight is over.
        $took = max(0, (int) $fight->created_at->diffInSeconds(now(), absolute: true));

        if ($took > 0) {
            Dose::query()->where('character_id', $character->getKey())->whereNull('turns_left')->where('expires_at', '>', $fight->created_at)
                ->get()->each(static fn (Dose $dose) => $dose->forceFill(['expires_at' => $dose->expires_at->addSeconds($took)])->save());
        }
        $character->forceFill(['current_fight_id' => null])->save();

        match ($state->result) {
            Result::BDown => app(Mood::class)->event($character, 'fight_won'),
            Result::ADown => app(Mood::class)->event($character, 'fight_lost'),
            default => null,
        };

        if ($state->result === Result::ADown) {
            app(WaitingRoom::class)->checkFloor($character, new DownedCause(
                reason: 'fight',
                tags: array_map(static fn (CombatTag $tag): string => $tag->value, CombatTag::fromStored($fight->tags)),
                effects: $effects,
            ));
        }

        // A fight an encounter started takes the encounter on from here. Resolved when needed and not
        // injected: the runner opens fights through this class, and each must be free of the other at
        // construction.
        app(EncounterRunner::class)->afterFight($character, $fight, $settled);
    }
}
