<?php

declare(strict_types=1);

use App\Entities\EntityCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAO, a military intelligence office. By migration, like every entity: its id ends up written into systems and books.
 * Interactable: it needs front desks placed after the deploy (`php artisan cyberspace:exchanges`). THO, DAO, RP and HAC are its suboffices, named in the description only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('entities')->where('name', 'TAO')->exists()) {
            return;
        }

        DB::table('entities')->insert([
            'name' => 'TAO',
            'category' => EntityCategory::Military->value,
            'interactable' => true,
            'business' => 'Gets into what it was never invited into.',
            'description' => 'Officially, the Tailored Access Operations office only operates outside of the country borders, finding different ways of infiltrating or gaining access to critical systems - be that through physical or cyber means, through social engineering, blackmail, bribes or threats. Unofficially though? Who knows. The TAO have many different suboffices; THO that makes hardware, DAO that runs cyber operations, RP that does research and HAC that deals with all the sticky human business. This is not an organization that ever forgets anything.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('entities')->where('name', 'TAO')->delete();
    }
};
