<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Rose, teal and sand join the colours a district may be drawn in (`App\City\DistrictColor`; tokens in ui-kit.css). */
return new class extends Migration
{
    public function up(): void
    {
        $this->colours("'steel', 'cyan', 'brass', 'violet', 'clay', 'jade', 'moss', 'rose', 'teal', 'sand'");
    }

    public function down(): void
    {
        DB::table('districts')->whereIn('color', ['rose', 'teal', 'sand'])->update(['color' => 'steel']);
        $this->colours("'steel', 'cyan', 'brass', 'violet', 'clay', 'jade', 'moss'");
    }

    private function colours(string $list): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE districts DROP CHECK chk_districts_color');
        DB::statement("ALTER TABLE districts ADD CONSTRAINT chk_districts_color CHECK (color IN ({$list}))");
    }
};
