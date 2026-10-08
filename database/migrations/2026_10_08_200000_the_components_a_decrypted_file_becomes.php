<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The components a decrypted file can become (`cyberspace.decryption.components`, CRAFTING.md). Like the Memory Dump they are
 * created here and never stocked or bought: a row is found, in the `extracted_file` JSON a dump already carries, and keeps
 * where it came from. Unstackable on purpose: each carries its own size, price and origin.
 *
 * The flavour text is a placeholder for the author to replace in the item editor.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const COMPONENTS = [
        'kernel-log' => 'Kernel Log',
        'cipher-fragment' => 'Cipher Fragment',
        'temporary-file' => 'Temporary File',
        'cache-file' => 'Cache File',
        'error-log' => 'Error Log',
        'stack-trace' => 'Stack Trace',
        'garbage-collection' => 'Garbage Collection',
        'config-file' => 'Config File',
        'backup' => 'Backup',
        'customer-record' => 'Customer Record',
        'software-manifest' => 'Software Manifest',
        'project-file' => 'Project File',
        'security-profile' => 'Security Profile',
        'research-data' => 'Research Data',
        'design-document' => 'Design Document',
        'experimental-data' => 'Experimental Data',
        'field-test-data' => 'Field Test Data',
        'parameterized-dataset' => 'Parameterized Dataset',
        'vectorized-dataset' => 'Vectorized Dataset',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::COMPONENTS as $slug => $name) {
            if (DB::table('items')->where('slug', $slug)->exists()) {
                continue;
            }

            DB::table('items')->insert([
                'slug' => $slug,
                'name' => $name,
                'flavor' => 'PLACEHOLDER. Something that was a system\'s, now yours, to be built into something else.',
                'container' => 'data',
                'tier' => 1,
                'rarity' => 'common',
                'base_cost' => 0,
                'on_market' => false,
                'seed_quantity' => 0,
                'stackable' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Held copies would be orphaned; nothing to undo safely.
    }
};
