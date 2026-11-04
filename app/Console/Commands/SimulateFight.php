<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Combat\Fight\CharacterMitigation;
use App\Combat\Fight\Combatant;
use App\Combat\Fight\CombatantBuilder;
use App\Combat\Fight\FightMitigation;
use App\Combat\Fight\FightState;
use App\Combat\Fight\NpcMitigation;
use App\Combat\Fight\Simulation;
use App\Combat\WeaponClass;
use App\Models\Character;
use App\Models\Npc;
use Illuminate\Console\Command;

/**
 * Plays N fights of a real character's loadout against a described opponent and prints how it goes.
 *
 * The balance tool docs/COMBAT.md promised BEFORE the content: the numbers (aim, damage, hit points, the
 * tier spread) are unmeasured, and the design is the ratios — a fight of 4–10 rounds that costs a
 * fifth to most of a character's hit points. It reads the character and writes NOTHING; the opponent
 * comes from options because NPCs are not a table yet. The enemy goes first, as in an encounter.
 */
final class SimulateFight extends Command
{
    protected $signature = 'fight:simulate
        {handle : the character whose equipment, stats and stash are fought with}
        {--runs=500 : how many fights}
        {--seed=1 : the dice seed, so a run is repeatable}
        {--foe= : the slug of an opponent from the editor; when given, the --foe-* options below are ignored}
        {--foe-name=Gang member}
        {--foe-hp=40}
        {--foe-aim=10}
        {--foe-defence=8}
        {--foe-pursuit=8}
        {--foe-flees-at= : a share of its hit points (0.25) below which it tries to leave}
        {--foe-ranged=pistol:5 : class:damage, or none}
        {--foe-melee=blade:4 : class:damage, or none (bare hands)}
        {--foe-rate=1 : shots per attack, for its ranged weapon}
        {--foe-resist= : type:percent,… e.g. penetrating:30,blunt:10}';

    protected $description = 'Play many fights of a character against a described opponent and report the odds (writes nothing)';

    public function handle(): int
    {
        $character = Character::query()->where('handle', $this->argument('handle'))->first();

        if ($character === null) {
            $this->error('No character with that handle.');

            return self::FAILURE;
        }

        $npc = null;

        if ($this->option('foe') !== null && $this->option('foe') !== '') {
            $npc = Npc::query()->where('slug', $this->option('foe'))->first();

            if ($npc === null) {
                $this->error('No opponent with that slug.');

                return self::FAILURE;
            }
        }

        $foe = $npc !== null ? CombatantBuilder::forNpc($npc) : CombatantBuilder::fromSpec([
            'name' => (string) $this->option('foe-name'),
            'hp' => (int) $this->option('foe-hp'),
            'aim' => (float) $this->option('foe-aim'),
            'defence' => (float) $this->option('foe-defence'),
            'pursuit' => (float) $this->option('foe-pursuit'),
            'flees_at' => $this->option('foe-flees-at') === null || $this->option('foe-flees-at') === '' ? null : (float) $this->option('foe-flees-at'),
            'ranged' => $this->weapon((string) $this->option('foe-ranged'), (int) $this->option('foe-rate')),
            'melee' => $this->weapon((string) $this->option('foe-melee'), 1),
        ]);

        $you = CombatantBuilder::forCharacter($character);
        $opening = FightState::opening($you, $foe);   // the enemy first, as in an encounter

        $result = Simulation::make()->run(
            $opening,
            new FightMitigation(new CharacterMitigation($character), $npc?->mitigation() ?? new NpcMitigation($this->resist((string) $this->option('foe-resist')))),
            max(1, (int) $this->option('runs')),
            (int) $this->option('seed'),
        );

        $this->report($character, $you, $foe, $result);

        return self::SUCCESS;
    }

    /** @return array{class: string, damage: float, rate: int}|null */
    private function weapon(string $text, int $rate): ?array
    {
        if ($text === '' || $text === 'none') {
            return null;
        }

        [$class, $damage] = array_pad(explode(':', $text, 2), 2, '0');

        if (WeaponClass::tryFrom($class) === null) {
            throw new \InvalidArgumentException("Unknown weapon class [{$class}]. Known: ".implode(', ', WeaponClass::values()).'.');
        }

        return ['class' => $class, 'damage' => (float) $damage, 'rate' => $rate];
    }

    /** @return array<string, float> */
    private function resist(string $text): array
    {
        $map = [];

        foreach (array_filter(explode(',', $text)) as $pair) {
            [$type, $percent] = array_pad(explode(':', $pair, 2), 2, '0');
            $map[trim($type)] = (float) $percent;
        }

        return $map;
    }

    /** @param  array<string, mixed>  $r */
    private function report(Character $character, Combatant $you, Combatant $foe, array $r): void
    {
        $pct = static fn (int $n, int $of): string => number_format($of > 0 ? 100 * $n / $of : 0, 1).'%';

        $this->line("<options=bold>{$character->handle}</> against <options=bold>{$foe->name}</> — {$r['runs']} fights, enemy first");
        $this->table(['Fight', 'Result'], [
            ['You', sprintf('%d hp, defence %.1f, ranged %s, close %s', $you->hp, $you->defence, $you->ranged?->name ?? 'none', $you->melee?->name ?? 'none')],
            ['Them', sprintf('%d hp, defence %.1f, aim %.1f', $foe->hp, $foe->defence, $foe->ranged?->aim ?? $foe->melee?->aim ?? 0)],
            ['Your first shot', $r['quote'] === [] ? 'nothing to fire' : implode(', ', array_map(static fn (float $c): string => number_format(100 * $c, 0).'%', $r['quote']))],
            ['Win', $pct($r['wins'], $r['runs'])],
            ['Lose', $pct($r['losses'], $r['runs'])],
            ['Get away', $pct($r['escapes'], $r['runs'])],
            ['No result in time', $pct($r['draws'], $r['runs'])],
            ['Rounds', sprintf('%.1f on average (%d to %d); the target is 4 to 10', $r['rounds'], $r['rounds_min'], $r['rounds_max'])],
            ['HP cost of a win', sprintf('%.1f (%s of your maximum); the target is a fifth to most', $r['hp_lost_on_wins'], $pct((int) round($r['hp_lost_share_on_wins'] * 1000), 1000))],
        ]);
    }
}
