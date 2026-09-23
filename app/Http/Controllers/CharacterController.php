<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Drugs\DrugLedger;
use App\Drugs\Family;
use App\Models\Archetype;
use App\Models\Character;
use App\Models\Dose;
use App\Stats\BreakdownStep;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
        $bio = trim((string) ($data['bio'] ?? ''));

        $character->forceFill(['bio' => $bio === '' ? null : $bio])->save();

        return back()->with('status', Text::get('character.bio_saved'));
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
