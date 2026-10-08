<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** KO-FI: a supporter flag may run out, and every membership payment Ko-fi tells us about is kept once. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('supporter_until')->nullable()->after('supporter_since')
                ->comment('When the supporter flag lapses. NULL while a supporter means for good (granted by hand); set by a Ko-fi membership payment.');
        });

        Schema::create('kofi_payments', function (Blueprint $table): void {
            $table->comment('Every Ko-fi membership payment received by webhook, once. The message id is what makes a retried delivery harmless.');
            $table->id()->comment('Surrogate key.');
            $table->string('message_id', 100)->unique()->comment('Ko-fi\'s message_id for the delivery; unique, so a retry changes nothing.');
            $table->string('email')->comment('The email on the payment, as Ko-fi sent it. Matched to users.email, case-insensitively.');
            $table->string('tier_name')->nullable()->comment('The membership tier named on the payment. Informational.');
            $table->boolean('is_first')->default(false)->comment('Whether it was the first payment of the subscription.');
            $table->unsignedBigInteger('user_id')->nullable()->comment('The account it matched. Plain column, no foreign key: NULL when no account had that email, and the row outlives the account.');
            $table->timestamp('received_at')->comment('When the webhook arrived, which is what the month counts from.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kofi_payments');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('supporter_until');
        });
    }
};
