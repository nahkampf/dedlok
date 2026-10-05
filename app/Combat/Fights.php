<?php

declare(strict_types=1);

namespace App\Combat;

use App\Characters\DownedCause;
use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\City\EncounterRunner;
use App\Combat\Fight\Action;
use App\Combat\Fight\Arm;
use App\Combat\Fight\CharacterMitigation;
use App\Combat\Fight\CombatantBuilder;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\FightMitigation;
use App\Combat\Fight\FightState;
use App\Combat\Fight\NpcMitigation;
use App\Combat\Fight\Result;
use App\Combat\Fight\Side;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\Fight;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Npc;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that writes a fight, and `characters.current_fight_id`. COMBAT.md, step 4.
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
    public function __construct(private readonly FightEngine $engine, private readonly mixed $die) {}

    public static function fromConfig(?Closure $die = null): self
    {
        return new self(
            new FightEngine(CheckRoller::fromConfig()),
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

            $before = $fight->snapshot();

            // Overwrite the player's side from the real body: the one source of truth.
            $before = $before->with(Side::A, $before->a->with(hp: (int) $locked->current_hitpoints, si: (int) $locked->current_synaptic_integrity));

            $after = $this->engine->round(
                $before,
                ['a' => $action],
                $this->die,
                new FightMitigation(new CharacterMitigation($locked), new NpcMitigation((array) $fight->resistances, (array) $fight->slot_resistances)),
            );

            $this->applyToCharacter($locked, $fight, $before, $after);

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
            $character->forceFill($locked->only(['current_fight_id', 'current_hitpoints', 'current_synaptic_integrity', 'creds']));

            return $fight;
        });
    }

    /**
     * What a screen needs to draw the buttons, from the stored state: every chance quoted, and which
     * actions are on offer. Reads only; the numbers are the ones the next roll will use.
     *
     * @return array{state: FightState, attack: list<float>, flight: float, closing: float, can_attack: bool, can_create_distance: bool, can_close_in: bool, can_disengage: bool, loads: list<array{key: string, name: string, stock: int}>}
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
            'attack' => $this->engine->quote($state, Side::A),
            'flight' => $this->engine->quoteFlight($state, Side::A),
            'closing' => $this->engine->quoteClosing($state, Side::A),
            'can_close_in' => $this->engine->legal($state, Side::A, Action::closeIn()),
            'can_disengage' => $this->engine->legal($state, Side::A, Action::disengage()),
            'can_attack' => $this->engine->canAttack($state, Side::A),
            'can_create_distance' => $this->engine->legal($state, Side::A, Action::createDistance()),
            'loads' => $loads,
        ];
    }

    /**
     * Put what the round did to the player's side onto the real character: the drop in hit points and
     * SI, and the rounds fired or loaded onto the weapon row they came from.
     */
    private function applyToCharacter(Character $character, Fight $fight, FightState $before, FightState $after): void
    {
        $character->takeDamage('current_hitpoints', max(0, $before->a->hp - $after->a->hp));
        $character->takeDamage('current_synaptic_integrity', max(0, (int) $before->a->si - (int) $after->a->si));

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
        $character->forceFill(['current_fight_id' => null])->save();

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
