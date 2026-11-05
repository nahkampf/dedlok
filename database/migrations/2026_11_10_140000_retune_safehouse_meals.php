<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Every table now fills the same 18; what a meal eases is what tells them apart. Keyed by slug, so it reaches whatever the admin left. */
return new class extends Migration
{
    private const UP = [
        'squat_highrise' => [18, 7],
        '1roomapt' => [18, 17],
        'hard_loft' => [18, 20],
    ];

    private const DOWN = [
        'squat_highrise' => [10, 10],
        '1roomapt' => [15, 18],
        'hard_loft' => [17, 20],
    ];

    public function up(): void
    {
        $this->set(self::UP);
    }

    public function down(): void
    {
        $this->set(self::DOWN);
    }

    /** @param array<string, array{int, int}> $meals */
    private function set(array $meals): void
    {
        foreach ($meals as $slug => [$fullness, $fatigue]) {
            DB::table('safehouses')->where('slug', $slug)->update(['meal_fullness' => $fullness, 'meal_fatigue' => $fatigue]);
        }
    }
};
