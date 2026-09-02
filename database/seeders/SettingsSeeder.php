<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Settings the application expects to exist.
     *
     * Written with put(), so re-running the seeder never clobbers a value that
     * has been tuned in production — it only refreshes the row it owns. Use
     * firstOrCreate semantics if you want seeds to be truly write-once.
     */
    public function run(): void
    {
        foreach ($this->defaults() as $key => [$value, $category]) {
            if (! Setting::has($key)) {
                Setting::put($key, $value, $category);
            }
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function defaults(): array
    {
        return [
            'new_character_attribute_points' => ['300', 'character'],
        ];
    }
}
