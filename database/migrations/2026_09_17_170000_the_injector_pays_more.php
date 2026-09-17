<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PayloadInjector pays 4.5% a level rather than 4%.
 *
 * A tuning change and nothing more: the five conditional rows are the same
 * shape they were, because "per level of something else" is still five copies
 * of one modifier each conditional on one more level — a modifier's amount is a
 * number and never an expression.
 *
 * Maxed, that is 22.5% over base plus flat rather than 20%.
 *
 * By migration rather than by hand so a fresh clone and this database agree.
 * The item editor is still where it gets tuned next time.
 */
return new class extends Migration
{
    private const SLUG = 'evasion-payloadinjector';

    public function up(): void
    {
        $this->setAmount(4.5);
    }

    public function down(): void
    {
        $this->setAmount(4);
    }

    private function setAmount(float $amount): void
    {
        $itemId = DB::table('items')->where('slug', self::SLUG)->value('id');

        if ($itemId === null) {
            return;
        }

        DB::table('programs')->where('item_id', $itemId)->update([
            'modifiers' => json_encode(array_map(
                static fn (int $level): array => [
                    'op' => 'percentage_of_base',
                    'amount' => $amount,
                    'target' => ['id' => 'stealth'],
                    'condition' => [
                        'when' => 'skill_at_least',
                        'args' => ['skill' => 'obfuscation', 'level' => $level],
                    ],
                ],
                range(1, 5),
            )),
            'updated_at' => now(),
        ]);
    }
};
