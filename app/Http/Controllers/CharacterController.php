<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Characters\Avatar;
use App\Characters\HeatDecay;
use App\Drugs\DrugLedger;
use App\Drugs\Family;
use App\Models\Archetype;
use App\Models\Character;
use App\Models\Dose;
use App\Stats\BreakdownStep;
use App\Support\Text;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\View\View;
use RuntimeException;

/**
 * The character sheet: who you are, rather than what you are carrying.
 *
 * ## Four screens became four tabs, and they are still four routes
 *
 * Skills, Implants and Achievements each had a rail item of their own and are
 * panels of this window now. They kept their URLs, their controllers and their
 * tests: the tab strip is a nav of LINKS, so each tab is a request that draws
 * one panel rather than one request that draws four. A window that rendered
 * every panel would run four screens' worth of queries to show one of them,
 * and this is the screen a player opens most.
 *
 * ## The bio is the only thing here the game never reads
 *
 * Everything else on this screen is derived — the attributes are resolved, the
 * archetype is a row, the picture is found by convention. The bio is the
 * player's own text and nothing mechanical will ever look at it.
 */
class CharacterController extends Controller
{
    public function index(Request $request): View
    {
        $character = $this->character($request);

        return view('character.index', [
            'character' => $character,
            // RESOLVED, never the column: an implant or a drug moves an
            // attribute, and a sheet that showed the stored number would
            // disagree with every check made against it.
            'attributes' => $this->attributes($character),
            'max' => Character::ATTRIBUTE_MAX,
            'vitals' => $this->vitals($character),
            'heat' => $this->heat($character),
            // What is in them right now, and how close each family is to the
            // line. Empty for nearly everybody, and then the section is absent
            // rather than a row of zeroes.
            'doses' => $this->doses($character, new DrugLedger),
            // The roster is cached and read on nearly every request anyway,
            // so naming the role costs nothing. `characters.archetype` is an
            // id rather than a relation — see Character::modifiers().
            'archetype' => Archetype::roster()->get($character->archetype),
        ]);
    }

    /**
     * The heat box: the figure, how full the bar is, what cools it a day, and when it reaches 0.
     *
     * Heat has no ceiling, so the bar is a scale of 100 (where the bounty roll stops climbing) and caps there. The
     * night it reaches 0 is counted from the next run of `characters:cool`, one rate a night, with the same rounding
     * the job uses — `HeatDecay::tonight()` rounds each night's result to a tenth.
     *
     * @return array{value: string, ratio: float, rate: string, zero_at: ?string, days: ?int, fill: string}
     */
    private function heat(Character $character): array
    {
        $heat = round((float) $character->heat, 1);
        $rate = round((new HeatDecay)->rateFor($character), 1);
        $nights = null;

        if ($heat > 0 && $rate > 0) {
            $left = $heat;
            $nights = 0;

            while ($left > 0 && $nights < 100000) {
                $left = round($left - $rate, 1);
                $nights++;
            }
        }

        $zeroAt = null;
        $days = null;
        $cron = Config::get('cadence.heat_cool');

        if ($nights !== null && is_string($cron)) {
            $first = CarbonImmutable::instance(CronExpression::factory($cron)->getNextRunDate(now()));
            $zeroAt = $first->addDays($nights - 1);
            $days = max(1, (int) ceil(now()->diffInHours($zeroAt, false) / 24));
        }

        $format = static fn (float $n): string => rtrim(rtrim(number_format($n, 1), '0'), '.');

        return [
            'value' => $format($heat),
            'ratio' => max(0.0, min(1.0, $heat / 100)),
            'rate' => $format($rate),
            'zero_at' => $zeroAt?->utc()->format('Y-m-d H:i').' UTC',
            'days' => $days,
            // The bands the bounty roll and the hunters read (config/cyberspace.php, heat): under the first they
            // do nothing; the top two grades start at 62.
            'fill' => $heat >= 62 ? 'var(--crit)' : ($heat >= 24 ? 'var(--warn)' : 'var(--ok)'),
        ];
    }

    /**
     * Save what they wrote about themselves.
     *
     * No confirmation and no history: it is a text field somebody owns, and
     * the only thing that can go wrong is writing over their own words, which
     * is theirs to do.
     */
    public function bio(Request $request): RedirectResponse
    {
        $character = $this->character($request);

        $data = $request->validate([
            'bio' => ['nullable', 'string', 'max:65000'],
        ]);

        // Empty and absent are the same thing here: clearing the box is how
        // somebody takes their bio down, and a row of spaces is not a bio.
        //
        // NOT `trim()`: the bio is monospace and may be ASCII art, so the indentation of its FIRST line is part of
        // the drawing. Blank lines at the start and whitespace at the end go; leading spaces on a line do not. Line
        // endings are one kind, because a browser submits CRLF.
        $raw = str_replace(["\r\n", "\r"], "\n", (string) ($data['bio'] ?? ''));
        $bio = rtrim(ltrim($raw, "\n"));

        $character->forceFill(['bio' => trim($bio) === '' ? null : $bio])->save();

        return back()->with('status', Text::get('character.bio_saved'));
    }

    /** A supporter's own picture: centre-cropped to 300x400 by `Avatar`, replacing what stood there. */
    public function avatar(Request $request, Avatar $avatar): RedirectResponse
    {
        abort_unless($request->user()->isSupporter(), 403);

        $request->validate(['avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192']], [
            'avatar.required' => Text::get('character.avatar_required'),
            'avatar.mimes' => Text::get('character.avatar_invalid'),
            'avatar.max' => Text::get('character.avatar_invalid'),
        ]);

        try {
            $avatar->store($this->character($request), $request->file('avatar'));
        } catch (RuntimeException) {
            return back()->withErrors(['avatar' => Text::get('character.avatar_invalid')]);
        }

        return back()->with('status', Text::get('character.avatar_saved'));
    }

    /** Takes the picture down; the default comes back. Open to anybody who has one, supporter or not any more. */
    public function removeAvatar(Request $request, Avatar $avatar): RedirectResponse
    {
        $avatar->remove($this->character($request));

        return back()->with('status', Text::get('character.avatar_removed'));
    }

    /**
     * Every attribute, resolved, with the ratio a bar is drawn from.
     *
     * @return list<array{key: string, label: string, value: int, ratio: float}>
     */
    private function attributes(Character $character): array
    {
        $sheet = $character->sheet();
        $rows = [];

        foreach (Character::ATTRIBUTES as $attribute) {
            $value = (int) round($sheet->value($attribute));

            $rows[] = [
                'key' => $attribute,
                'label' => Text::get("character.names.{$attribute}"),
                'value' => $value,
                'ratio' => max(0.0, min(1.0, $value / Character::ATTRIBUTE_MAX)),
            ];
        }

        return $rows;
    }

    /**
     * The two resources, each against its RESOLVED maximum, with what moved
     * that maximum.
     *
     * One line per layer rather than per modifier, straight off the resolver's
     * steps — percentages are summed and applied once, so a line each would
     * imply a compounding that does not happen. The run screen's evasion card
     * reads the same steps the same way.
     *
     * @return list<array{label: string, current: int, max: int, ratio: float, steps: list<BreakdownStep>}>
     */
    private function vitals(Character $character): array
    {
        $sheet = $character->sheet();
        $rows = [];

        foreach (Character::RESOURCES as $resource => $statId) {
            $stat = $sheet->get($statId);

            $rows[] = [
                'label' => Text::get("character.vitals.{$statId}"),
                'current' => (int) $character->{$resource},
                'max' => $stat->toInt(),
                'ratio' => $character->resourceRatio($resource),
                'steps' => $stat->steps,
            ];
        }

        return $rows;
    }

    /**
     * One row per drug family they carry anything for: what is working, how
     * many more this body holds, and what the books say.
     *
     * A FAMILY WITH NOTHING IN IT AND CLEAN BOOKS IS LEFT OUT. Somebody can be
     * deep into opiates and clean of stimulants, so this is per family like
     * the ledger underneath it, and absent and zero are the same answer.
     *
     * The capacity is counted against what is ALREADY working, which is what
     * `App\Drugs\DrugTaker` counts when it decides whether the dose being
     * taken is the one that breaks it — so the meter is the warning, not a
     * second opinion.
     *
     * @return list<array<string, mixed>>
     */
    private function doses(Character $character, DrugLedger $ledger): array
    {
        $inEffect = Dose::query()
            ->where('character_id', $character->getKey())
            ->inEffect()
            ->with('item.drug')
            ->orderBy('expires_at')
            ->get();

        $rows = [];

        foreach (Family::cases() as $family) {
            $books = $ledger->for($character, $family);
            $working = $inEffect->filter(fn (Dose $dose): bool => $dose->item?->drug?->family === $family)->values();
            $addiction = (float) $books->addiction;
            $tolerance = (float) $books->tolerance;

            if ($working->isEmpty() && $addiction <= 0.0 && $tolerance <= 0.0) {
                continue;
            }

            $capacity = $ledger->capacityFor($character, $family);

            $rows[] = [
                'family' => $family,
                'doses' => $working,
                'used' => $working->count(),
                'capacity' => $capacity,
                'ratio' => $capacity > 0 ? min(1.0, $working->count() / $capacity) : 1.0,
                'tolerance' => $tolerance,
                'addiction' => $addiction,
                // Over the line, which is not the same as in withdrawal: a
                // dose of the right family holds it off without curing it.
                'hooked' => $ledger->isHooked($character, $family),
                'withdrawing' => $ledger->isHooked($character, $family) && $working->isEmpty(),
            ];
        }

        return $rows;
    }

    private function character(Request $request): Character
    {
        // RequireCharacter guarantees one on every route in this group.
        return $request->user()->character()->firstOrFail();
    }
}
