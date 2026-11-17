<?php

declare(strict_types=1);

use App\Entities\EntityCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kronos Capital, phase 1 (docs/BANK.md): somewhere to keep creds that nothing can take. The entity (its front desk is
 * placed with `php artisan cyberspace:exchanges` after the deploy), the City location that is its branch, and the
 * accounts with their ledger. The entity's one-line business and its description are PLACEHOLDERS the owner rewrites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_branches', function (Blueprint $table): void {
            $table->comment('A location that is a bank: it offers the bank by having a row here (App\\Models\\Location::features).');
            $table->id()->comment('Primary key.');
            $table->foreignId('location_id')->unique()->comment('The place that is a branch.')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->comment('A character\'s account at Kronos Capital. The balance is what the ledger sums to; only App\\Bank\\Bank writes it.');
            $table->id()->comment('Primary key.');
            $table->foreignId('character_id')->unique()->comment('Whose account it is. One each.')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('balance')->default(0)->comment('Creds held. Always equals the sum of bank_entries.amount (bank:verify asks).');
            $table->timestamps();
        });

        Schema::create('bank_entries', function (Blueprint $table): void {
            $table->comment('The account\'s statement: one immutable line per movement, carrying the balance it left. No updated_at.');
            $table->id()->comment('Primary key.');
            $table->foreignId('bank_account_id')->comment('The account it belongs to.')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount')->comment('Creds in (positive) or out (negative).');
            $table->unsignedBigInteger('balance')->comment('The account balance after this line.');
            $table->string('kind', 24)->comment('App\\Bank\\EntryKind.');
            $table->string('note', 255)->nullable()->comment('Composed when written and never recomposed.');
            $table->timestamp('created_at')->nullable()->comment('When it happened.');
            $table->index(['bank_account_id', 'id']);
        });

        $now = now();

        if (! DB::table('entities')->where('name', 'Kronos Capital')->exists()) {
            DB::table('entities')->insert([
                'name' => 'Kronos Capital',
                'category' => EntityCategory::Corporation->value,
                'interactable' => true,
                'business' => 'Personal banking at Kronos Capital',
                'description' => 'Kronos Capital is the go-to personal banking partners for those with a... Complicated income flow.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $district = DB::table('districts')->where('slug', 'downtown')->value('id');

        if ($district === null) {
            return;
        }

        DB::table('locations')->insertOrIgnore([
            'district_id' => $district,
            'slug' => 'kronos-capital',
            'name' => 'Kronos Capital',
            'description' => 'Kronos Capital is the City\'s prime personal banking partner for those in less than well-documented lines of work.',
            'map_x' => 62,
            'map_y' => 40,
            'discovery' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('bank_branches')->insertOrIgnore([
            'location_id' => DB::table('locations')->where('slug', 'kronos-capital')->value('id'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_entries');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('bank_branches');
        DB::table('locations')->where('slug', 'kronos-capital')->delete();
        DB::table('entities')->where('name', 'Kronos Capital')->delete();
    }
};
