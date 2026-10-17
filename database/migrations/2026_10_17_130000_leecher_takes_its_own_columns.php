<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Leecher's take (which resource, how much) gets columns of its own, so `storage_size` means only what OWNING one costs. Each existing
 * Leecher's take moves across from whichever of compute, memory or storage it had; compute and memory Leechers are left owning nothing, as
 * they did, and storage Leechers carry a tenth of what they take (0.1, 0.3, 0.8 at 1, 3, 8), so the number they stole no longer counts against the deck.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->string('leech_resource', 8)->nullable()->comment('Leechers only: the one resource it borrows from its host system (compute, memory or storage). NULL for every other program.');
            $table->decimal('leech_amount', 10, 1)->default(0)->comment('Leechers only: how much of that resource it borrows, in qFLOPS or QB. Zero for every other program.');
        });

        foreach (DB::table('programs')->where('kind', 'leecher')->get() as $p) {
            [$resource, $amount, $owns] = match (true) {
                (float) $p->qflops_cost > 0 => ['compute', $p->qflops_cost, 0],
                (float) $p->memory_cost > 0 => ['memory', $p->memory_cost, 0],
                (float) $p->storage_size > 0 => ['storage', $p->storage_size, round((float) $p->storage_size / 10, 1)],
                default => [null, 0, 0],
            };

            DB::table('programs')->where('item_id', $p->item_id)->update([
                'leech_resource' => $resource, 'leech_amount' => $amount, 'qflops_cost' => 0, 'memory_cost' => 0, 'storage_size' => $owns,
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('programs')->where('kind', 'leecher')->get() as $p) {
            DB::table('programs')->where('item_id', $p->item_id)->update(match ($p->leech_resource) {
                'compute' => ['qflops_cost' => $p->leech_amount, 'storage_size' => 0],
                'memory' => ['memory_cost' => $p->leech_amount, 'storage_size' => 0],
                default => ['storage_size' => $p->leech_amount],
            });
        }

        Schema::table('programs', fn (Blueprint $table) => $table->dropColumn(['leech_resource', 'leech_amount']));
    }
};
