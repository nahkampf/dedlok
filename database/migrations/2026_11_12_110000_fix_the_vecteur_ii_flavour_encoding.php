<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The GSM Vecteur II's flavour text was saved double-encoded: "Systèmes" arrived as "SystÃ¨mes" (the bytes of è read
 * as two Latin-1 characters and encoded again). This puts the word right. Data only; an item that is missing, or whose
 * text is already right, is left alone. The garbled and the correct spellings are written as bytes so this file cannot
 * be mangled the same way.
 */
return new class extends Migration
{
    private const GARBLED = "Syst\xC3\x83\xC2\xA8mes";

    private const RIGHT = "Syst\xC3\xA8mes";

    public function up(): void
    {
        $flavor = DB::table('items')->where('slug', 'gms_vecteur_ii')->value('flavor');

        if (! is_string($flavor) || ! str_contains($flavor, self::GARBLED)) {
            return;
        }

        DB::table('items')->where('slug', 'gms_vecteur_ii')->update(['flavor' => str_replace(self::GARBLED, self::RIGHT, $flavor)]);
    }

    public function down(): void
    {
        // The garbling was a mistake; there is nothing worth putting back.
    }
};
