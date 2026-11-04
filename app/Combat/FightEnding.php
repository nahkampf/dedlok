<?php

declare(strict_types=1);

namespace App\Combat;

use App\Combat\Fight\Result;
use App\Insurance\Insurance;
use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\Entity;
use App\Models\Fight;
use App\Models\Item;
use App\Standing\StandingLedger;
use App\Standing\StandingReason;
use App\Stats\InjuryLedger;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Closure;
use Illuminate\Support\Facades\Config;

/**
 * What a fight's ending is worth. docs/COMBAT.md, *Winning, losing, leaving*.
 *
 * Called by `Fights` when a round ends the fight, on the LOCKED character, inside the round's transaction,
 * BEFORE the waiting room is asked: damage, consequence, the floor — the order every bad ending follows.
 * It returns the lines the fight log and the waiting screen tell; it writes through the owners of what it
 * touches (`Wallet`, `ItemGranter`, `StandingLedger`, `InjuryLedger`) and nothing else.
 *
 * ## Its own die
 *
 * Not the fight's. The fight's die is pinned in a test in an exact order and asserted spent, so an ending
 * that drew from it would break every test that finishes a fight. `DefeatResolver`'s shape: an injected
 * `fn (int $min, int $max): int`, container-bound, pinned by the tests that care.
 *
 * ## Only the ends that have somebody down
 *
 * A win pays; a loss costs; the player getting away, or the opponent doing so, is nothing either way.
 *
 * ## The terms are the fight's own
 *
 * Whose it is, its tags and its loot are copied onto the fight when it opens, so an opponent edited or
 * deleted mid-fight changes nothing. The pay band is the grade's, in config.
 */
final readonly class FightEnding
{
    public function __construct(
        private InjuryLedger $injuries,
        private StandingLedger $standing,
        private Closure $roll,
    ) {}

    public static function fromConfig(?Closure $roll = null): self
    {
        return new self(
            app(InjuryLedger::class),
            app(StandingLedger::class),
            $roll ?? static fn (int $min, int $max): int => random_int($min, $max),
        );
    }

    /**
     * Settle a fight that has just ended.
     *
     * @return list<array{text: string, tone: string}>
     */
    public function settle(Character $character, Fight $fight, Result $result): array
    {
        return match ($result) {
            Result::BDown => $this->win($character, $fight),
            Result::ADown => $this->loss($character, $fight),
            Result::AEscaped, Result::BEscaped => [],
        };
    }

    /**
     * The opponent's pay, then each drop in the order they were authored, then its owner's opinion.
     * Dice are drawn in that order: the pay, then a chance (and, on a success, a quantity) per drop.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function win(Character $character, Fight $fight): array
    {
        $lines = [];
        $band = Config::get("combat.pay.{$fight->grade}");

        if (is_array($band)) {
            $creds = ($this->roll)((int) $band['min'], (int) $band['max']);

            if ($creds > 0) {
                Wallet::move($character, $creds, Reason::Fight, Text::get('wallet.notes.fight', ['name' => $fight->opponent_name]));
                $lines[] = $this->line('combat.fight.spoils.creds', ['creds' => $creds], 'ok');
            }
        }

        foreach ((array) $fight->loot as $drop) {
            $item = Item::query()->find((int) $drop['item_id']);

            // Deleted since the fight began: nothing to drop, and no die spent on it.
            if ($item === null || ($this->roll)(1, 100) > (int) $drop['chance']) {
                continue;
            }

            $count = ItemGranter::grant($character, $item, ($this->roll)((int) $drop['min'], (int) $drop['max']));

            if ($count > 0) {
                $lines[] = $this->line('combat.fight.spoils.item', ['item' => $item->displayName(), 'count' => $count], 'ok');
            }
        }

        // Silent: the Standing tab is where it is read. Nothing here says how the owner took it.
        if ($fight->entity_id !== null) {
            $this->standing->record(
                $character,
                Entity::query()->find($fight->entity_id),
                StandingReason::Defeated,
                StandingReason::Defeated->amount() * $fight->grade,
            );
        }

        return $lines;
    }

    /**
     * Every tag the opponent carries, in the vocabulary's order. The player is already at zero hit points;
     * the caller asks the floor once, after this.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function loss(Character $character, Fight $fight): array
    {
        $lines = [];

        foreach (CombatTag::fromStored($fight->tags) as $tag) {
            $line = match ($tag) {
                CombatTag::Mugged => $this->mugged($character),
                CombatTag::Maim => $this->maim($character),
            };

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** A share of ALL the creds carried, floored. A purse of nothing loses nothing, and says nothing. */
    private function mugged(Character $character): ?array
    {
        $percent = ($this->roll)((int) Config::get('combat.mugging.min'), (int) Config::get('combat.mugging.max'));
        $taken = (int) floor((int) $character->creds * $percent / 100);

        if ($taken <= 0) {
            return null;
        }

        Wallet::move($character, -$taken, Reason::Mugged, Text::get('wallet.notes.mugged'));

        return $this->line('combat.fight.defeat.mugged', ['creds' => $taken], 'crit');
    }

    /** Deepens `physical_trauma` by a share of the maximum as it stands, so two maimings compound. */
    private function maim(Character $character): array
    {
        $percent = ($this->roll)((int) Config::get('combat.maim.min'), (int) Config::get('combat.maim.max'));
        $points = max(1, (int) round($character->maximumFor('current_hitpoints') * $percent / 100));

        $this->injuries->inflict($character, InjuryLedger::TRAUMA, (float) $points);
        (new Insurance)->claimInjury($character, InjuryLedger::TRAUMA, (float) $points);

        // The model's saving hook clamps current hit points to the lower ceiling.
        $character->save();

        return $this->line('combat.fight.defeat.maim', ['points' => $points], 'crit');
    }

    /** @param  array<string, mixed>  $replace */
    private function line(string $key, array $replace, string $tone): array
    {
        return ['text' => Text::get($key, $replace), 'tone' => $tone];
    }
}
