<?php

declare(strict_types=1);

use App\Models\Item;
use App\Programs\ProgramEditor;
use App\Programs\ProgramKind;
use Illuminate\Database\Migrations\Migration;

/**
 * ORACLE, retuned on localhost on 2026-09-29 and carried to production.
 *
 * It was +9% to scan checks against the Surveyor Mk II's +10%, at four times
 * the compute — a rare analyser that lost to a common one. Now +19% and a
 * quarter off every pass's duration, and off the market: found, not bought.
 *
 * Keyed by slug and written whole, so running it where the edit was made
 * changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $oracle = Item::query()->where('slug', 'scanner-oracle')->first();

        if ($oracle === null) {
            return;
        }

        $oracle->update(['on_market' => false]);

        ProgramEditor::apply($oracle, ProgramKind::Analyser, [
            'modifiers' => [
                ['op' => 'percentage_of_base', 'amount' => 19, 'target' => ['id' => 'check.systemprofiling']],
                ['op' => 'percentage_of_base', 'amount' => -25, 'target' => ['id' => 'scan_duration']],
            ],
        ]);
    }

    public function down(): void
    {
        $oracle = Item::query()->where('slug', 'scanner-oracle')->first();

        if ($oracle === null) {
            return;
        }

        $oracle->update(['on_market' => true]);

        ProgramEditor::apply($oracle, ProgramKind::Analyser, [
            'modifiers' => [
                ['op' => 'percentage_of_base', 'amount' => 9, 'target' => ['id' => 'check.systemprofiling']],
            ],
        ]);
    }
};
