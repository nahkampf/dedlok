<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievement_seen', function (Blueprint $table): void {
            $table->comment('The distinct things an account has done a metric to, for the achievements that count different ones (drugs tried, people paid).');
            $table->id()->comment('Primary key.');
            $table->foreignId('user_id')
                ->comment('Whose set this is. Achievements attach to the account, never the character.')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('metric', 40)->comment('An App\Achievements\Metric case. Permanent, like the progress rows.');
            $table->string('subject', 64)->comment('What was seen, as its id: an item id for a drug, a character id for somebody paid. Unique per account and metric.');

            $table->unique(['user_id', 'metric', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievement_seen');
    }
};
