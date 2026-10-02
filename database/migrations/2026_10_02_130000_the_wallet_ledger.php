<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_entries', function (Blueprint $table): void {
            $table->comment('Immutable ledger of every change to characters.creds: one row per movement, written only by App\\Wallet\\Wallet.');
            $table->id()->comment('Surrogate key. Also the order: newest is highest.');
            $table->unsignedBigInteger('character_id')->comment('Whose wallet moved.');
            $table->bigInteger('amount')->comment('Signed creds: positive came in, negative went out. Never 0.');
            $table->unsignedBigInteger('balance')->comment('characters.creds immediately after this movement, so the ledger reads as a statement.');
            $table->string('reason', 32)->comment('App\\Wallet\\Reason value: what kind of movement. A fixed vocabulary.');
            $table->string('note')->nullable()->comment('Specifics composed at write time (item, other party). Stored sentence: never recomposed, because what it names may be gone. NULL is ordinary.');
            $table->timestamp('created_at')->useCurrent()->comment('When it happened. No updated_at: entries are never edited.');

            $table->foreign('character_id')->references('id')->on('characters')->cascadeOnDelete();
            $table->index(['character_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_entries');
    }
};
