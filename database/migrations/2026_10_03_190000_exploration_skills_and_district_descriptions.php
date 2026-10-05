<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Item;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Content authored in the LOCAL admin on 2026-10-03, carried to production.
 *
 * The route `2026_09_29_140000_content_authored_on_localhost` took: everything is
 * keyed by SLUG and upserted, so running it where the rows already exist (the
 * laptop that made them) changes nothing.
 *
 * - Acclimatization (rank 4): -6% `explore_duration` a level.
 * - Observation (rank 8): +1 `exploration` a level — flat, because a percentage of
 *   a pool near 14 is under half a point at level 1.
 * - Both imprints are COMMON with a shelf of 25, whatever their rank would derive
 *   (`Imprint` derives rarity and shelf once, at creation, from rank).
 * - New descriptions for all seven districts.
 *
 * `down()` removes the two skills and their imprints and leaves the districts' text
 * alone: the words they replaced are not recoverable from here.
 */
return new class extends Migration
{
    private const SHELF = 25;

    public function up(): void
    {
        $this->skill('acclimatization', [
            'title' => 'Acclimatization',
            'description' => 'The ability to quickly read the layout of a city district, picking up on the flow of '
                .'people and subtle markers until the local area feels familiar.',
            'category' => 'Physical',
            'rank' => 4,
            'primary_attribute' => 'senses',
            'secondary_attribute' => 'perception',
            'base_cost' => 1500,
        ], 'percentage_of_base', 'explore_duration', -6);

        $this->skill('observation', [
            'title' => 'Observation',
            'description' => 'The ability to observe, categorize and analyze the flow of people in a given location. '
                .'From it one can infer quite a lot about what might be hidden down alleyways, behind '
                .'nondescript doors or up a dilapidated stairwell.'
                ."\n"
                ."\n"
                .'"To condense fact from the vapor of nuance" - Neal Stephenson, "Snow Crash"',
            'category' => 'Physical',
            'rank' => 8,
            'primary_attribute' => 'perception',
            'secondary_attribute' => 'senses',
            'base_cost' => 2300,
        ], 'add', 'exploration', 1);

        foreach ($this->districts() as $slug => $description) {
            DB::table('districts')->where('slug', $slug)->update([
                'description' => $description,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['acclimatization', 'observation'] as $slug) {
            $skill = Skill::query()->where('slug', $slug)->first();
            $skill?->imprint()->delete();
            $skill?->delete();
        }
    }

    /** A skill on the market with five equal grants against one stat, and its imprint. */
    private function skill(string $slug, array $columns, string $op, string $stat, int $amount): void
    {
        $skill = Skill::query()->updateOrCreate(['slug' => $slug], [
            ...$columns,
            'on_market' => true,
            'modifiers' => array_map(
                static fn (int $level): array => [
                    'op' => $op, 'level' => $level, 'amount' => $amount, 'target' => ['id' => $stat],
                ],
                range(1, 5),
            ),
        ]);

        Imprint::ensureFor($skill);
        Imprint::syncFor($skill);

        Item::query()->where('use_skill_id', $skill->id)->update([
            'rarity' => 'common',
            'seed_quantity' => self::SHELF,
        ]);
    }

    /** @return array<string, string> */
    private function districts(): array
    {
        return [
            'old-town' => 'The oldest part of the city is a strange mix of centuries old stone buildings, winding '
                .'narrow streets and alleys, mansions and quaint parks intermixed with hypermodern glass '
                .'towers, skyways and subterranean walkways. It\'s home to some really Old Money and '
                .'Powerful People, who constantly groan about how the public areas, the storefronts and '
                .'the newly constructed buildings are destroying their property values or irrevocably '
                .'changing the character of the district.',
            'dockside' => 'Dockside stretches for miles, its cranes and warehouses, container parks and quays '
                .'lending it a jungle or maze-like appearance. Half of it has long since been abandoned, '
                .'as the tides of trade receded, while the other half is a bustling hive of activity, '
                .'starkly lit by sodium vapor lights making the rolling fog an impenetrable, yellow '
                .'nothingness.',
            'eastside' => 'Eastside is home to the entertainment district, where night clubs, theatres, cineplexes,'
                .' VR halls and arcades compete with more exotic or shady establishments. It is also where'
                .' you\'ll find some of the gated communities known as "burbclaves", where fences and '
                .'private security keep the inhabitants isolated from the rest of the City.',
            'downtown' => 'Downtown is the frantic city centre, the CBD, the Grand Central Station and the Hotel '
                .'District. This is where sararīmen and business travelers rub shoulders with hustlers, '
                .'tourists and the nouveau rich, where gigantic ad screens and neon signs shine from every '
                .'facade, and where thousands arrive each day with dreams and hopes - and thousands leave,'
                .' with the same now crushed.',
            'little-moscow' => 'Little Moscow is really a misnomer that stuck; it\'s not only Russians here, you\'ll also '
                .'find New Dhaka here, a Chinatown, the Jewish Quarter and various other small enclaves '
                .'where people have built a small patch of home in imitation of where they once came from.'
                .' It\'s a vibrant district, where a thousand languages are spoken, where food stalls fill '
                .'the air with smells from a dozen national cuisines, where night markets and street '
                .'festivals make this part of town more lively at night than by day. This is where '
                .'bohemians, lower class intellectuals and students congregate, and where freshly arrived '
                .'immigrants naturally seek a new home.',
            'crystal-heights' => 'The Crystal Heights are a weird place; as the city creeps up the steep hills the new, '
                .'shiny highrises and storefronts look like chrome paint spilled on a rusty old steel '
                .'plate as they straddle the old dilapidated slums. And towering above it all are the '
                .'Soleri Arcologies, a failed experiment that refuses to face reality - an enclosed space '
                .'where the corpo "lifers" live out their regimented lives. It\'s a place of contrast, '
                .'where a single turn from the brightly neon-lit boulevards might take you down an alleyway'
                .' littered with burned out cars and the detritus of low society. But it is also a place '
                .'where the friction of these two worlds sometimes ignite sparks - often in innovation, '
                .'some times in outright riots.',
            'knightsbridge-projects' => 'Knightsbridge used to be at the forefront of city planning - this was where the New City '
                .'was going to be built. A fairer, smarter, more optimized city district, that would '
                .'address the problems of public transportation, affordable housing and reasonable zoning.'
                .' That all sounded good on paper, but as the fickle politics of The City pulled the plug '
                .'on financing half way through it all came crashing down, warping and mutating as ad hoc '
                .'solutions and random patchwork set in. Time has not been kind to Knightsbridge, the '
                .'streets are run down and full of pot holes and abandoned cars. Soot, rust and algae '
                .'stain the concrete superblocks. Great masses of cabling crisscross the air between '
                .'housing blocks, like the web of a demented spider. Gangs roam the alleyways, and the '
                .'recurring police raids leave further cracks and destruction that only rarely gets '
                .'repaired. But people here are resilient and crafty, and fiercely protective of their '
                .'neighbourhoods.',
        ];
    }
};
