<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beta keys: what a tester needs to register while `features.alpha_testing` is on.
 *
 * `user_id` NULLS when the account goes and never cascades: a row that REMEMBERS a key was spent must outlive the
 * person who spent it, or a deleted tester's key would quietly become valid again. `used_at` is what says it is
 * spent; `user_id` is only who.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beta_keys', function (Blueprint $table): void {
            $table->comment('Single-use codes that let a tester register while the alpha testing flag is on. Written only by App\\Beta\\BetaKeys.');

            $table->id()->comment('Primary key.');
            $table->string('code', 32)->unique()->comment('The key as typed at registration: upper case, in groups of four (XXXX-XXXX-XXXX). Unique.');
            $table->timestamp('given_out_at')->nullable()->comment('When an admin copied it to hand to somebody. NULL means still in the admin\'s pocket. Informational: it does not stop the key working.');
            $table->foreignId('user_id')->nullable()->unique()->comment('Who registered with it. NULL while unused, and again if that account is deleted (used_at still says it is spent).')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable()->comment('When it was spent at registration. NULL means it still works. Never cleared.');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beta_keys');
    }
};
