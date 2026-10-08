<?php

declare(strict_types=1);

use App\Programs\ProgramKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Decryptor can be limited to some encryptions, and the first one is made.
 *
 * `programs.decrypts` is a list of encryption skill slugs the program can work on; NULL (the ordinary case, and
 * every other kind of program) means any. Read by `App\Decryption\DecryptionQueue`: a file waits for a decryptor
 * running on the deck that can break ITS encryption.
 *
 * **Ghostkey** is the cheap one: Cinder only, common, a shelf of 25, free (`base_cost` 0), 2 QB to own, 2 QB of
 * memory and 3 qFLOPS while it runs. Keyed by slug so a deploy where it was authored by hand changes nothing about
 * the item, only fills in the limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->json('decrypts')->nullable()->comment('Decryptors only: the encryption skill slugs it can break. NULL means any. Read by App\\Decryption\\DecryptionQueue.');
        });

        $now = now();

        $existing = DB::table('items')->where('slug', 'ghostkey')->value('id');

        $itemId = $existing ?? DB::table('items')->insertGetId([
            'slug' => 'ghostkey',
            'name' => 'Ghostkey',
            'flavor' => 'A patient little decryptor that tries the keys people forget they ever used. It is useless against anything better than Cinder, and it has never once complained about that.',
            'container' => 'data',
            'tier' => 1,
            'rarity' => 'common',
            'stackable' => true,
            'weight' => 0,
            'size' => 0,
            'capacity' => 0,
            'base_cost' => 0,
            'on_market' => true,
            'seed_quantity' => 25,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('programs')->updateOrInsert(['item_id' => $itemId], [
            'kind' => ProgramKind::Decryptor->value,
            'qflops_cost' => 3,
            'memory_cost' => 2,
            'storage_size' => 2,
            'background_process' => true,
            'multithreaded' => true,
            'modifiers' => null,
            'decrypts' => json_encode(['cinder']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $id = DB::table('items')->where('slug', 'ghostkey')->value('id');

        if ($id !== null) {
            DB::table('inventory_items')->where('item_id', $id)->delete();
            DB::table('items')->where('id', $id)->delete();
        }

        Schema::table('programs', function (Blueprint $table): void {
            $table->dropColumn('decrypts');
        });
    }
};
