<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Electrode and NeuraLink ports are scrapped: a deck presents no IO socket, and nothing could ever be fitted into one.
 *
 * Deletes the `io` rows from `deck_slots`, drops any `io_*` keys from a deck's `board_layout`, and takes `io` out of the
 * kind and variant CHECKs. The CHECKs are MySQL-only, like the migrations that made them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('deck_slots')->where('kind', 'io')->delete();

        DB::table('items')->whereNotNull('board_layout')->get(['id', 'board_layout'])->each(function (object $item): void {
            $layout = json_decode($item->board_layout, true);

            if (! is_array($layout)) {
                return;
            }

            $kept = array_filter($layout, static fn ($key): bool => ! str_starts_with((string) $key, 'io_'), ARRAY_FILTER_USE_KEY);

            if (count($kept) !== count($layout)) {
                DB::table('items')->where('id', $item->id)->update(['board_layout' => $kept === [] ? null : json_encode($kept)]);
            }
        });

        if (DB::getDriverName() === 'mysql') {
            $this->rewriteChecks(io: false);
        }
    }

    /** The ports' rows and layout keys are gone for good; only the schema's allowance for them comes back. */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->rewriteChecks(io: true);
        }
    }

    private function rewriteChecks(bool $io): void
    {
        $kinds = "'cpu','npu','ram','nic','expansion','psu',".($io ? "'io'," : '')."'thermal','storage'";

        DB::statement('ALTER TABLE `deck_slots` DROP CONSTRAINT `chk_deck_slots_kind`');
        DB::statement("ALTER TABLE `deck_slots` ADD CONSTRAINT `chk_deck_slots_kind` CHECK (`kind` IN ({$kinds}))");

        DB::statement('ALTER TABLE `deck_slots` DROP CONSTRAINT `chk_deck_slots_variant`');
        DB::statement(
            'ALTER TABLE `deck_slots` ADD CONSTRAINT `chk_deck_slots_variant` CHECK ('.
            "(`kind` = 'cpu' AND `variant` IS NOT NULL AND `variant` IN ('socket_g','socket_k','socket_z')) OR ".
            "(`kind` = 'ram' AND `variant` IS NOT NULL AND `variant` IN ('opto','qdram','universal')) OR ".
            "(`kind` = 'expansion' AND `variant` IS NOT NULL AND `variant` IN ('xgate','pei','horizon')) OR ".
            ($io ? "(`kind` = 'io' AND `variant` IS NOT NULL AND `variant` IN ('electrode','neuralink')) OR " : '').
            "(`kind` IN ('npu','nic','psu','thermal','storage') AND `variant` IS NULL))"
        );
    }
};
