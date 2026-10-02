<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Models\Backdoor;
use App\Models\Character;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Stats\Resolver;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * A little from every machine somebody is draining, once a night.
 *
 * ## The safe half of a portfolio
 *
 * A siphon is slow and safe where a routing hop is free and expendable: it
 * pays whether or not its holder goes anywhere, and the only thing that takes
 * it is the ageing roll. That asymmetry is the design — income is insulated
 * from routing risk, so a trace that catches somebody never touches what they
 * earn — and it is why the two kinds are worth building separately.
 *
 * ## The base is the machine's; the modifiers are the person's
 *
 * `(3000 x grade) / 100` is what the machine is worth draining, and everything
 * after that belongs to whoever holds the backdoor: `siphon_yield` goes
 * through `App\Stats\Resolver` like every other number, so the first skill
 * that makes somebody better at this is a row rather than a branch in here.
 *
 * ## It never touches the backdoor
 *
 * Paying out reads `backdoors` and writes `characters.creds`, so this is not
 * the table's writer and does not need to be — `App\Cyberspace\Backdoors`
 * stays the one place that installs and loses them.
 */
final readonly class SiphonPayout
{
    public function __construct(private Resolver $resolver) {}

    public static function fromConfig(?Resolver $resolver = null): self
    {
        return new self($resolver ?? app(Resolver::class));
    }

    /**
     * Pay every siphon in the world.
     *
     * @return array{characters: int, backdoors: int, creds: int}
     */
    public function run(): array
    {
        $held = Backdoor::query()
            ->ofKind(BackdoorKind::Siphon)
            ->with('system')
            ->get()
            ->filter(static fn (Backdoor $backdoor): bool => $backdoor->system !== null)
            ->groupBy('character_id');

        $paid = 0;
        $counted = 0;

        foreach ($held as $characterId => $backdoors) {
            $character = Character::query()->find($characterId);

            if ($character === null) {
                continue;
            }

            $amount = 0;

            /*
             * ponytail: one resolve per DISTINCT GRADE per character rather
             * than one per backdoor. Percentages apply to base + flat, so the
             * answer genuinely differs by grade and cannot be resolved once
             * and scaled — but two grade 9s resolve identically, and there are
             * only nine grades. Nothing at this scale; if it ever matters, the
             * fix is to resolve the nine once per character rather than to
             * make resolution cheaper.
             */
            $modifiers = $character->modifiers();
            $byGrade = [];
            $lines = [];

            foreach ($backdoors as $backdoor) {
                $grade = (int) $backdoor->system->grade;

                $byGrade[$grade] ??= max(0, $this->resolver
                    ->resolve('siphon_yield', (float) $this->baseFor($grade), $modifiers)
                    ->toInt());

                $amount += $byGrade[$grade];
                $counted++;

                // What the report says about this system, as true tonight. A
                // snapshot: by morning it may have moved or burned.
                $lines[] = [
                    'system' => $backdoor->system->displayName(),
                    'address' => (string) $backdoor->system->address,
                    'grade' => $grade,
                    'creds' => $byGrade[$grade],
                ];
            }

            /*
             * ONE REPORT PER CHARACTER, NOT A MESSAGE PER SIPHON. It happened
             * while they slept, which is the inbox's whole test — and ten
             * siphons as ten messages would bury everything else in it. Sent
             * even on a night that paid nothing, because holding a siphon that
             * paid nothing is itself worth knowing.
             */
            usort($lines, static fn (array $a, array $b): int => $b['creds'] <=> $a['creds']);

            (new Notifier)->send($character, Category::Cyberspace, 'siphons_ran', [
                'creds' => Number::format($amount),
            ], $lines);

            if ($amount <= 0) {
                continue;
            }

            DB::transaction(static function () use ($character, $amount): void {
                Wallet::move($character, $amount, Reason::Siphon);
            });

            // Siphoned creds are counted apart from raided ones on purpose:
            // the design's whole point about a siphon is that it is the half
            // of the portfolio that never went anywhere.
            (new Achievements)->record($character, Metric::SiphonCreds, $amount);

            $paid += $amount;
        }

        return ['characters' => $held->count(), 'backdoors' => $counted, 'creds' => $paid];
    }

    /** What the machine itself is worth draining, before its holder is asked. */
    public function baseFor(int $grade): int
    {
        return max(0, $grade) * (int) Config::get('cyberspace.backdoors.siphon.per_grade', 30);
    }
}
