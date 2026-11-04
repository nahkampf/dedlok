<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Insurance\Insurance;
use App\Models\Character;
use App\Models\DeckFitting;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Programs\ProgramRunner;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Stats\InjuryLedger;
use App\Stats\Resolver;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * What a piece of ICE actually does to somebody it beats.
 *
 * UNTIL THIS EXISTED, A DEFEAT'S TAGS WERE WORDS ON A SCREEN. `DownedCause`
 * carried their names into the waiting room, and nothing rolled or applied a
 * single one of them — losing to ICE XII and losing to ICE I cost exactly the
 * same thing. This is the class that makes the tag set real.
 *
 * ## Order
 *
 * Called from `Simulator::resolveEnding()`, BEFORE the ejection — damage, then
 * the consequence it causes, then the ejection, then the waiting screen, which
 * is the order every bad ending in this game follows. Applying tags after
 * `jackOut()` would be resolving a fight's outcome once the fight was already
 * over by every other measure.
 *
 * ## A tag never becomes a new cause of damage within the same ending
 *
 * Each of these is applied once, straight from the ICE's own row, and none of
 * them reaches back to roll another. A Surge that leaves the deck unready does
 * not also trigger a freeze — that would be a consequence inventing a new
 * cause, which docs/CYBERSPACE.md rules out explicitly.
 *
 * ## Bounty is not here
 *
 * It is rolled per MACHINE, not carried by the ICE — see `System::ice_bounty`
 * — and it can never appear in `Ice::defeatTags()`. What a bounty actually
 * DOES is still undesigned, so this only reports whether the machine carries
 * one; nothing is written anywhere yet.
 *
 * The die is injected, like everything else that rolls in cyberspace.
 */
final readonly class DefeatResolver
{
    public function __construct(
        private InjuryLedger $injuries,
        private ProgramRunner $runner,
        private DamageResolver $damage,
        private Closure $roll,
    ) {}

    public static function fromConfig(?Closure $roll = null): self
    {
        return new self(
            app(InjuryLedger::class),
            app(ProgramRunner::class),
            new DamageResolver(app(Resolver::class)),
            $roll ?? static fn (int $min, int $max): int => random_int($min, $max),
        );
    }

    /**
     * Apply everything this ICE carries, in the order its row lists them.
     *
     * @return list<array{text: string, tone: string}>
     */
    public function apply(Character $character, Ice $ice): array
    {
        $log = [];
        $lost = [];
        $worth = 0;

        foreach ($ice->defeatTags() as $entry) {
            $line = match ($entry['tag']) {
                DefeatTag::Flatline => $this->flatline($character, $entry['level']),
                DefeatTag::NeuralShock => $this->neuralShock($character, $entry['level']),
                DefeatTag::Surge => $this->surge($character, $entry['level'], $lost, $worth),
                DefeatTag::Erase => $this->erase($character, $entry['level'], $lost, $worth),
                DefeatTag::Skim => $this->skim($character, $entry['level']),
                // Structurally unreachable — Bounty is never in this list — but
                // match must be exhaustive, and a silent skip is the honest
                // answer if the vocabulary ever changes under this code.
                DefeatTag::Bounty => null,
            };

            if ($line !== null) {
                $log[] = $line;
            }
        }

        // One message for everything this ending destroyed, summed by name: 53 × Scan File, not 53 lines.
        if ($lost !== []) {
            ksort($lost);

            (new Notifier)->send($character, Category::Cyberspace, 'damage_report', details: [
                'lost' => array_map(fn (string $name, int $n): string => $n.' × '.$name, array_keys($lost), $lost),
            ]);

            (new Insurance)->claimLoss($character, $worth, $lost);
        }

        return $log;
    }

    /**
     * @param  array<string, int>  $lost  name => copies, added to
     * @param  int  $worth  base value of all of it, added to (what a property policy pays a share of)
     */
    private function noteLost(array &$lost, int &$worth, ?InventoryItem $row): void
    {
        if ($row !== null) {
            $worth += (int) $row->item?->base_cost * max(1, (int) $row->quantity);
            $name = $row->label();
            $lost[$name] = ($lost[$name] ?? 0) + max(1, (int) $row->quantity);
        }
    }

    /**
     * Permanent damage to maximum Synaptic Integrity.
     *
     * A PERCENTAGE OF THE MAXIMUM AS IT STANDS RIGHT NOW — before this
     * flatline, after every earlier one. Two flatlines in the same fight are
     * not built (a program carries one Flatline, never several), but a
     * character who already carries the injury takes this one off a ceiling
     * the last one already lowered, which is what "accumulates" means.
     *
     * Written through InjuryLedger, which deepens ONE row rather than adding a
     * second — the same *neural damage* a freeze and a future overdose will
     * both write into.
     *
     * @return array{text: string, tone: string}
     */
    private function flatline(Character $character, int $level): array
    {
        $band = Config::get("cyberspace.defeat_tags.flatline.{$level}");
        $max = $character->maximumFor('current_synaptic_integrity');
        $pct = ($this->roll)((int) $band['min'], (int) $band['max']);
        $points = (int) round($max * $pct / 100);

        $this->injuries->inflict($character, InjuryLedger::NEURAL, (float) $points);
        (new Insurance)->claimInjury($character, InjuryLedger::NEURAL, (float) $points);

        // The tag is what "flatlined" means, so this is the one place it can
        // be counted — a run ending badly is not the same event, and most of
        // them are not this.
        (new Achievements)->record($character, Metric::Flatlines);

        // Current SI clamps to the new, lower ceiling on save — the character
        // model's own `saving()` hook does this automatically, the same rule
        // an implant coming out already follows. Saved here rather than left
        // for later so the run's own ending does not read a stale number a
        // moment after causing it.
        $character->save();

        return $this->line('flatline', ['points' => $points]);
    }

    /**
     * Damage to CURRENT hit points. The mind's defeat, landing on the body.
     *
     * THROUGH `DamageResolver`, LIKE EVERY OTHER HIT. It called
     * `Character::takeDamage()` directly once, which is the dull half
     * underneath the door and skips every rule the door exists for: a
     * resistance, a vulnerability or an immunity written against physical
     * damage could not touch it, and neither could the Crim's own "Hard
     * knocks" — a perk whose target set (`damage` + `hitpoints`) covers
     * exactly this stat. The percentage is rolled off the MAXIMUM as before;
     * what the resolver decides is how much of it actually lands.
     *
     * The origin is ICE, which is what dealt it. Flatline next door does NOT
     * come through here and should not: it writes a ceiling through
     * `InjuryLedger` rather than landing a hit, and mitigation has nothing to
     * say about an injury.
     *
     * @return array{text: string, tone: string}
     */
    private function neuralShock(Character $character, int $level): array
    {
        $band = Config::get("cyberspace.defeat_tags.neural_shock.{$level}");
        $max = $character->maximumFor('current_hitpoints');
        $pct = ($this->roll)((int) $band['min'], (int) $band['max']);
        $points = (int) round($max * $pct / 100);

        $result = $this->damage->apply(
            $character,
            Damage::hitpoints($points, DamageOrigin::Ice),
        );

        return $this->line('neural_shock', ['points' => $result->taken]);
    }

    /**
     * Destroys fitted hardware, at random.
     *
     * ROLLED ONCE PER MODULE the level names, never once for the tag — see
     * config/cyberspace.php for why that is the whole feel of it. Drawn from
     * whatever is CURRENTLY fitted to the equipped deck; a module already
     * taken by an earlier roll this same Surge cannot be taken twice, because
     * it is gone.
     *
     * A deck with nothing left to lose — everything already surged off, or a
     * deck with no modules at all, which readiness would never have allowed
     * anyway — is a no-op, and that is an ordinary answer rather than a
     * failure.
     *
     * @param  array<string, int>  $lost
     * @return ?array{text: string, tone: string}
     */
    private function surge(Character $character, int $level, array &$lost, int &$worth): ?array
    {
        $config = Config::get("cyberspace.defeat_tags.surge.{$level}", []);
        $chance = (int) ($config['chance'] ?? 0);
        $attempts = (int) ($config['modules'] ?? 0);

        if ($chance <= 0 || $attempts <= 0 || $character->equipped_deck_id === null) {
            return null;
        }

        $destroyed = 0;

        for ($i = 0; $i < $attempts; $i++) {
            if (($this->roll)(1, 100) > $chance) {
                continue;
            }

            $fitting = DeckFitting::query()
                ->where('deck_id', $character->equipped_deck_id)
                ->inRandomOrder()
                ->first();

            if ($fitting === null) {
                break;
            }

            // The fitting row cascades with it — module_id is
            // cascadeOnDelete — so nothing here has to clear it by hand.
            $module = InventoryItem::query()->with('item')->find($fitting->module_id);
            $this->noteLost($lost, $worth, $module);
            $module?->delete();
            $destroyed++;
        }

        if ($destroyed === 0) {
            return null;
        }

        return $this->line('surge', ['count' => $destroyed]);
    }

    /**
     * Deletes software or data held on the deck.
     *
     * THE WHOLE DECK, WHICH IS TWO POOLS. `inventoryIn(CONTAINER_DATA)` is
     * "loose" — and `InventoryItem::scopeLoose()` deliberately excludes
     * anything RUNNING, the same way it excludes anything fitted or
     * installed. That is right for a stash listing and wrong here: "software
     * or data held on the deck" has to include what is running on it, or
     * Erase would spare exactly the programs a character cared enough about
     * to load. So the pool is storage plus whatever is running, and a running
     * row is stopped before it is deleted — `running_on_deck_id` is a fact
     * ProgramRunner owns, not one a delete should blow through.
     *
     * `count` takes exactly that many; `share` takes that fraction, rounded up
     * so a level that promises "half the deck" on one held item still takes
     * it. Level three's `share: 100` is everything, which is why it is the
     * most expensive thing in the tag set.
     *
     * @param  array<string, int>  $lost
     * @return ?array{text: string, tone: string}
     */
    private function erase(Character $character, int $level, array &$lost, int &$worth): ?array
    {
        $config = Config::get("cyberspace.defeat_tags.erase.{$level}", []);

        $pool = $character->inventoryIn(Item::CONTAINER_DATA)
            ->concat($character->runningProcesses())
            ->unique('id')
            ->values();

        if ($pool->isEmpty()) {
            return null;
        }

        $take = $config['count'] !== null
            ? (int) $config['count']
            : (int) ceil($pool->count() * ((int) $config['share']) / 100);

        $targets = $pool->shuffle()->take(max(0, $take));

        if ($targets->isEmpty()) {
            return null;
        }

        DB::transaction(function () use ($character, $targets, &$lost, &$worth): void {
            foreach ($targets as $row) {
                $this->noteLost($lost, $worth, $row);

                if ($row->running_on_deck_id !== null) {
                    $this->runner->stop($character, $row);
                }

                $row->delete();
            }
        });

        return $this->line('erase', ['count' => $targets->count()]);
    }

    /**
     * Takes carried creds. Never what is in a node — a defeat reaches only
     * what a character had on them.
     *
     * @return ?array{text: string, tone: string}
     */
    private function skim(Character $character, int $level): ?array
    {
        $share = (int) Config::get("cyberspace.defeat_tags.skim.{$level}.share", 0);
        $taken = (int) floor($character->creds * $share / 100);

        if ($taken <= 0) {
            return null;
        }

        Wallet::move($character, -$taken, Reason::Skim);

        return $this->line('skim', ['creds' => $taken]);
    }

    /** @param  array<string, mixed>  $replace */
    private function line(string $key, array $replace = []): array
    {
        return ['text' => Text::get("cyberspace.run.log.defeat.{$key}", $replace), 'tone' => 'crit'];
    }
}
