<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Characters\DownedCause;
use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\Decks\Storage;
use App\Inventory\ItemGranter;
use App\Missions\MissionLedger;
use App\Models\Character;
use App\Models\Hunt;
use App\Models\Item;
use App\Programs\ProgramRunner;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Stats\Resolver;
use App\Support\Points;
use App\Support\Text;
use App\Support\Trace;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Cyberspace combat — one hunter, fought until it or you gives out.
 *
 * A HUNTER IS AN ICE, so the exchange is `Simulator::iceStrikes()`'s own
 * shape, not a second one invented beside it: your running icebreaker cuts
 * its integrity, and if it survives it swings back — a miss does nothing, a
 * hit goes through `DamageResolver` as `damage.synaptic_integrity.ice`,
 * because it is that damage and every resistance already built for it
 * applies without learning a hunter exists.
 *
 * NO TRACE, NO IDS, NO EGRESS. This is what the player asked cyberspace
 * combat to be: straight into the exchange, nothing else running, and no
 * door out until it is settled — `App\Http\Middleware\EnsureHuntIsResolved`
 * is the lock, this is what happens inside it.
 *
 * THE PLAYER ACTS, THEN THE HUNTER DOES, the order every fight in this game
 * follows: somebody who breaks the ICE does not then take a hit from it.
 */
final class HunterCombat
{
    /** @param  Closure(int, int): int  $roll */
    public function __construct(
        private readonly Resolver $resolver,
        private readonly ProgramRunner $programs,
        private readonly WaitingRoom $waitingRoom,
        private readonly Closure $roll,
        private readonly CheckRoller $checks,
    ) {}

    /** @param  ?Closure(int, int): int  $roll */
    public static function fromConfig(Resolver $resolver, ?Closure $roll = null): self
    {
        $roll ??= Trace::die(static fn (int $min, int $max): int => random_int($min, $max));

        return new self($resolver, new ProgramRunner, new WaitingRoom($resolver), $roll, app(CheckRoller::class));
    }

    /**
     * One exchange: you hit, and if it is still standing, it hits back.
     *
     * ONE TRANSACTION, because a hunter fought halfway and left unsaved is a
     * fight the next request would have to guess the middle of.
     *
     * @return list<array{text: string, tone: string}>
     */
    public function fight(Character $character, Hunt $hunt, bool $egress = false): array
    {
        return Trace::scope($character, $egress ? 'hunt.egress' : 'hunt.fight', fn (): array => $this->exchange($character, $hunt, $egress), [
            'hunt' => Trace::describe($hunt), 'ice' => Trace::describe($hunt->ice),
        ]);
    }

    /** @return list<array{text: string, tone: string}> */
    private function exchange(Character $character, Hunt $hunt, bool $egress): array
    {
        return DB::transaction(function () use ($character, $hunt, $egress): array {
            $new = [];

            if ($egress) {
                $held = $this->tryEgress($character, $hunt);

                if ($held === null) {
                    return $this->appendLog($hunt, [$this->line('cyberspace.hunt.log.egress', tone: 'ok')]);
                }

                $new[] = $held;
            } else {
                // `icebreaker_damage` hands in zero at its own call site, exactly
                // as Simulator::attack() does — it is deliberately not in
                // `stats.bases`, so `Character::stat()` throws for it rather than
                // answering zero, and asking through a Sheet would be wrong here
                // anyway.
                $dealt = $this->resolver->resolve('icebreaker_damage', 0.0, $character->modifiers())->value;
                $critical = $dealt > 0 && CriticalHit::rolls($character, $this->roll);
                $dealt = $critical ? CriticalHit::scale($dealt) : $dealt;
                Trace::note('hunt.player_hit', [
                    'icebreaker_damage' => $dealt, 'critical' => $critical,
                    'ice_integrity_before' => $hunt->ice_integrity, 'ice_integrity_after' => max(0, Points::of($hunt->ice_integrity - $dealt)),
                    'running' => $character->runningProcesses()->pluck('item.name')->all(),
                ]);
                $hunt->ice_integrity = max(0, Points::of($hunt->ice_integrity - $dealt));

                $new[] = $dealt > 0
                    ? $this->line($critical ? 'cyberspace.hunt.log.hit_critical' : 'cyberspace.hunt.log.hit', ['damage' => $dealt], 'ok')
                    : $this->line('cyberspace.hunt.log.no_program', tone: 'warn');

                if ($hunt->ice_integrity <= 0) {
                    $hunt->save();
                    array_push($new, ...$this->win($character, $hunt));

                    return $this->appendLog($hunt, $new);
                }

            }

            $ice = $hunt->ice;

            Trace::note('hunt.ice_swings', ['ice' => $ice->name, 'skill' => $ice->skill, 'attack' => $ice->attack]);

            if (($this->roll)(1, 100) > $ice->skill) {
                $hunt->save();
                $new[] = $this->line('cyberspace.hunt.log.miss');

                return $this->appendLog($hunt, $new);
            }

            $result = (new DamageResolver($this->resolver))->apply(
                $character,
                Damage::synapticIntegrity($ice->attack, DamageOrigin::Ice),
            );

            $new[] = $this->line('cyberspace.hunt.log.hunter_hits', ['damage' => $result->taken], 'crit');

            if ($character->fresh()->current_synaptic_integrity <= 0) {
                $new[] = $this->lose($character, $hunt);
            } else {
                $hunt->save();
            }

            return $this->appendLog($hunt, $new);
        });
    }

    /**
     * Try to pull out of a hunt: the run's own egress roll (the `egress` stat against the grade's difficulty plus
     * the hunter's level). Null when it worked (hunt closed, heat unchanged, nothing paid, jacked out), else the
     * line saying it held, and the hunter swings as it would after a miss on the icebreaker.
     *
     * @return ?array{text: string, tone: string}
     */
    private function tryEgress(Character $character, Hunt $hunt): ?array
    {
        $table = (array) Config::get('cyberspace.egress.difficulty', []);
        $difficulty = (float) ($table[$hunt->grade] ?? ($table[1] ?? 0))
            + (int) $hunt->ice->level * (float) Config::get('cyberspace.egress.ice_term', 1);

        if (! $this->checks->roll($character->stat('egress')->value, $difficulty, 'egress')->passed) {
            return $this->line('cyberspace.hunt.log.egress_held', tone: 'warn');
        }

        $this->programs->stopRunOnly($character);

        $character->forceFill(['current_hunt_id' => null])->save();
        $hunt->forceFill(['won' => false, 'resolved_at' => now()])->save();

        Navigator::fromConfig($this->resolver)->jackOut($character);

        return null;
    }

    /**
     * What this exchange added, folded onto the hunt's own record — the same
     * call `runs.log` makes, so `hunt.show` reads one row rather than a
     * session flash the next request would not have.
     *
     * @param  list<array{text: string, tone: string}>  $new
     * @return list<array{text: string, tone: string}>
     */
    private function appendLog(Hunt $hunt, array $new): array
    {
        $hunt->forceFill(['log' => [...($hunt->log ?? []), ...$new]])->save();

        return $new;
    }

    /**
     * The hunter breaks. Stop what was running for it, price the win by
     * grade, and close the row.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function win(Character $character, Hunt $hunt): array
    {
        $this->programs->stopRunOnly($character);

        $grade = $hunt->grade;
        [$low, $high] = (array) Config::get("cyberspace.heat.hunt.grades.{$grade}.creds", [0, 0]);
        $creds = ($this->roll)((int) $low, (int) $high);

        $reduction = $grade * (int) Config::get('cyberspace.heat.hunt.win_heat_reduction_per_grade', 5);
        $newHeat = max(0.0, (float) $character->heat - $reduction);

        $character->forceFill([
            'heat' => $newHeat,
            'current_hunt_id' => null,
        ])->save();

        Wallet::move($character, $creds, Reason::Hunt);

        (new Achievements)->record($character, Metric::BloodhoundsDefeated);

        $hunt->forceFill(['won' => true, 'resolved_at' => now()])->save();

        $lines = [$this->line('cyberspace.hunt.log.won', ['creds' => $creds, 'heat' => round($newHeat, 1)], 'ok')];

        $program = $this->maybeDropProgram($character);

        if ($program !== null) {
            $lines[] = $this->line('cyberspace.hunt.log.program', ['item' => $program->displayName()], 'ok');
        }

        return $lines;
    }

    /**
     * You go down. Heat comes off it, deliberately less and less predictably
     * than a win does — losing is not meant to be the cheap way to manage it.
     *
     * @return array{text: string, tone: string}
     */
    private function lose(Character $character, Hunt $hunt): array
    {
        $this->programs->stopRunOnly($character);

        // Whatever it was carrying for an exchange is gone, and the job with it.
        app(MissionLedger::class)->transportLost($character);
        $character->refresh();

        [$low, $high] = (array) Config::get('cyberspace.heat.hunt.defeat_heat_loss', [0.05, 0.15]);
        $fraction = $low + (($this->roll)(0, 1000) / 1000) * ($high - $low);
        $newHeat = max(0.0, round((float) $character->heat * (1 - $fraction), 1));

        $character->forceFill(['heat' => $newHeat, 'current_hunt_id' => null])->save();

        $hunt->forceFill(['won' => false, 'resolved_at' => now()])->save();

        $this->waitingRoom->checkFloor($character, new DownedCause(
            reason: 'hunter',
            ice: $hunt->ice->name,
            damage: $hunt->ice->attack,
            resource: 'current_synaptic_integrity',
        ));

        return $this->line('cyberspace.hunt.log.lost', ['heat' => $newHeat], 'crit');
    }

    /**
     * A LOW CHANCE of a program on top of the creds. Silently skipped if it
     * would not fit — nothing forces room the way extraction does, and a
     * reward that cannot be carried is simply not carried.
     */
    private function maybeDropProgram(Character $character): ?Item
    {
        $chance = (float) Config::get('cyberspace.heat.hunt.program_chance', 8);

        if (($this->roll)(1, 100) > $chance) {
            return null;
        }

        $item = Item::query()->onMarket()->whereHas('program')->inRandomOrder()->first();

        if ($item === null || ! Storage::fits($character, $item->size)) {
            return null;
        }

        ItemGranter::grant($character, $item, 1);

        return $item;
    }

    /** @param  array<string, mixed>  $replace */
    private function line(string $key, array $replace = [], string $tone = 'default'): array
    {
        return ['text' => Text::get($key, $replace), 'tone' => $tone];
    }
}
