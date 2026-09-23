<?php

declare(strict_types=1);

use App\Entities\EntityCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ten organisations a runner can actually work for.
 *
 * FIVE ARE PROMOTED AND FIVE ARE NEW, and the split is deliberate. The five
 * promoted ones already run hundreds of machines each, so the moment standing
 * exists there is something to lose it with; the five new ones own nothing,
 * which is right for a regulator and a military command — they do not want
 * their own nodes scanned, they want somebody else's.
 *
 * By migration rather than a seeder, like the entity roster itself and the
 * archetypes: these ids end up written into missions, books and ledger rows,
 * so they are permanent. A seeder that could re-run and renumber them would be
 * a second source for content the table owns.
 *
 * Nothing here is mechanical. `interactable` is the only column that decides
 * anything, and what an entity is LIKE lives in its own words — which is why
 * each of these gets a description and the other 111 do not.
 */
return new class extends Migration
{
    /** The five that were already in the world and are now somebody. */
    private function promoted(): array
    {
        return [
            'Anansi Data Weaving' => 'Brokers information and, unusually for the trade, keeps its word. Anansi buys scan files by the file and asks only that they be complete — a partial read is a rumour, and the Weave does not deal in rumours. It sells the same data on to people who would never be seen buying it, which is the whole of the business and the reason nobody moves against it.',

            'Ninth Circle Security' => 'Private soldiers with a sense of humour that nobody outside the company finds funny. Ninth Circle takes contracts that its clients cannot be seen taking, which increasingly means work in cyberspace rather than on a street, and it hires runners the way it hires everyone: briefly, at a good rate, and with a clause about discretion that is enforced rather than printed.',

            'Bright Verge Media' => 'Owns nine networks and runs them as though they competed. Bright Verge does not want machines broken; it wants to know what is on them before the people who own them do. Its agents pay for reads, for files and for the occasional errand that a court would call interference, and its people are unfailingly polite about all three.',

            'Ivory Registry' => 'Verifies that you are who you say you are, and — for a second and much larger fee — that you never were. The Registry keeps the ledgers that other institutions trust, which makes the quiet alteration of one the most valuable thing it sells. It would rather a machine were left standing and lied to than broken and noticed.',

            'Khaleej Sovereign Holdings' => 'A sovereign fund large enough to be weather. Khaleej owns positions in half the companies on this grid and takes an interest in the other half; what it wants from a runner is rarely explained and always specific. It is the only desk on the grid that will talk to somebody at the top of their profession, and the only one that can afford to.',
        ];
    }

    /** The five that had to be invented, because none of the 121 was one. */
    private function authored(): array
    {
        return [
            [
                'name' => 'Bureau of Network Integrity',
                'category' => EntityCategory::Government,
                'business' => 'Licenses what may run on the grid, and removes what may not.',
                'description' => 'The regulator. The Bureau maintains that cyberspace is infrastructure and that infrastructure is licensed, a position it holds against all evidence and most of the people on this grid. It pays runners to read unlicensed machines, and occasionally to remove one — carefully worded as decommissioning, on paperwork nobody outside the Bureau will ever see.',
            ],
            [
                'name' => 'Directorate of Revenue Enforcement',
                'category' => EntityCategory::Government,
                'business' => 'Finds the money, and then finds the rest of it.',
                'description' => 'Tax enforcement with subpoena power and a budget that never grows. The Directorate cannot afford its own operators, so it buys intelligence on shell systems and quiet holdings from whoever brings it — reliably, unglamorously, and at rates it describes as statutory. Nobody has ever enjoyed working for them. Everybody gets paid.',
            ],
            [
                'name' => 'Joint Signals Command',
                'category' => EntityCategory::Military,
                'business' => 'Listens to everything, and explains nothing.',
                'description' => 'The military\'s ear on civilian infrastructure. Joint Signals wants footholds — its own siphons, on other people\'s machines, installed by somebody with no uniform and no connection to it. It does not explain what the take is for, it does not acknowledge the relationship afterwards, and it pays very well for both of those things.',
            ],
            [
                'name' => 'Lockjaw',
                'category' => EntityCategory::Fringe,
                'business' => 'Information wants to be free. Lockjaw helps it along.',
                'description' => 'A data liberation crew that treats every corporate archive as stolen property awaiting return. Lockjaw pays in software and instruction nobody sells — tools written for one job and given away afterwards, techniques that never reach a market. It despises every other organisation on this grid, government and corporate alike, and it keeps a longer memory of who has worked for them than anybody realises.',
            ],
            [
                'name' => 'Golden Path',
                'category' => EntityCategory::Fringe,
                'business' => 'The machines are the disease. They are the cure.',
                'description' => 'A doomsday cult with a technical education. The Path holds that the grid is a wound in the world and that closing it is a devotional act, which in practice means burning machines and leaving its own instruments inside the ones it spares. It is generous, entirely sincere, and the only employer on this grid that thanks you afterwards.',
            ],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->promoted() as $name => $description) {
            $updated = DB::table('entities')->where('name', $name)->update([
                'interactable' => true,
                'description' => $description,
                'updated_at' => $now,
            ]);

            // A missing row is not an error worth stopping a migration for:
            // the roster is content, and a database seeded before it existed
            // simply has one fewer desk.
            if ($updated === 0) {
                logger()->warning("Entity [{$name}] was not found to promote.");
            }
        }

        foreach ($this->authored() as $entity) {
            DB::table('entities')->updateOrInsert(
                ['name' => $entity['name']],
                [
                    'category' => $entity['category']->value,
                    'interactable' => true,
                    'business' => $entity['business'],
                    'description' => $entity['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    /**
     * The five promoted ones go back to being scenery; the five authored ones
     * are deleted only if nothing points at them, which is what the foreign
     * key on `systems.owner_id` already guarantees for the ones that never
     * owned a machine.
     */
    public function down(): void
    {
        DB::table('entities')
            ->whereIn('name', array_keys($this->promoted()))
            ->update(['interactable' => false, 'description' => null]);

        DB::table('entities')
            ->whereIn('name', array_column($this->authored(), 'name'))
            ->delete();
    }
};
