<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The five authored entities take over systems of their own.
 *
 * They arrived owning nothing, which was right for a regulator and wrong for
 * everybody: an entity with no systems can never be acted AGAINST, so half of
 * what standing is for could not happen to it. These counts give each of them
 * a presence proportional to what they are — a tax office runs a handful of
 * systems, a cult that thinks the grid is a wound runs forty of them.
 *
 * **Taken from the flavour entities only.** The five promoted corporations
 * keep everything they run, because thinning them would undo the reason they
 * were promoted. The portal is untouched: it is the one system nobody owns.
 *
 * It ROLLS, like the migration that first handed out owners. Which systems
 * each of them gets is world-building rather than a rule, so there is nothing
 * to derive and nothing to keep in step.
 */
return new class extends Migration
{
    /** @return array<string, int> */
    private function holdings(): array
    {
        return [
            // A regulator is small and everywhere; the range is rolled once.
            'Bureau of Network Integrity' => random_int(5, 10),
            'Directorate of Revenue Enforcement' => 8,
            'Joint Signals Command' => 20,
            'Lockjaw' => 30,
            'Golden Path' => 40,
        ];
    }

    public function up(): void
    {
        foreach ($this->holdings() as $name => $count) {
            $entity = DB::table('entities')->where('name', $name)->first(['id']);

            if ($entity === null) {
                logger()->warning("Entity [{$name}] was not found; it takes over nothing.");

                continue;
            }

            // Flavour rows only, and never a system already held by one of the
            // entities somebody can deal with.
            $ids = DB::table('systems')
                ->whereIn('owner_id', fn ($query) => $query
                    ->select('id')
                    ->from('entities')
                    ->where('interactable', false))
                ->inRandomOrder()
                ->limit($count)
                ->pluck('id');

            DB::table('systems')->whereIn('id', $ids)->update(['owner_id' => $entity->id]);
        }
    }

    /**
     * Ownership is content, not a derivation: there is nothing to put back
     * that would be truer than what is there now, and handing these systems
     * to a random flavour entity would be a different world rather than the
     * old one. Rolling back leaves them where they are.
     */
    public function down(): void {}
};
