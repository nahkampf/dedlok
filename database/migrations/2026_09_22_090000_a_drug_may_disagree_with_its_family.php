<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One drug's own lethal threshold, where the family's is wrong for it.
 *
 * WHAT TOO MUCH DOES BELONGS TO THE FAMILY, because it is pharmacology rather
 * than branding: opiates stop you breathing soonest, a stimulant has to work
 * harder at it, and nothing about a psychedelic stops a heart. That lives in
 * `config/drugs.overdose.families` and answers for every drug in the
 * catalogue.
 *
 * THIS IS THE EXCEPTION, and it is nullable because almost every row should
 * leave it alone. A cut street stimulant that kills at two rather than three
 * is a number on its own row; a research-grade opiate that a body tolerates
 * further sets a higher one. NULL is not "never" — it is "ask the family",
 * which is what nearly every drug wants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drugs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('lethal_at')->nullable()->after('tolerance_gain')
                ->comment('How many doses past what the body holds stops this one\'s heart, overriding the family. NULL asks the family, which is what nearly every drug wants; a high number is how a drug in a lethal family is made survivable.');
        });
    }

    public function down(): void
    {
        Schema::table('drugs', function (Blueprint $table): void {
            $table->dropColumn('lethal_at');
        });
    }
};
