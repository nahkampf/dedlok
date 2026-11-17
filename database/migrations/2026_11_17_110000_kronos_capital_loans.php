<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kronos Capital, phase 2 (docs/BANK.md): loans and what each payment was made of. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table): void {
            $table->comment('A loan from Kronos Capital. One open loan (active or defaulted) per character; only App\\Bank\\Loans writes it. All sums are whole creds.');
            $table->id()->comment('Primary key.');
            $table->foreignId('character_id')->comment('The borrower.')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('borrowed')->comment('The amount lent. Fixes the payment and the early-payoff fee.');
            $table->unsignedInteger('principal')->comment('Principal still owed.');
            $table->unsignedInteger('interest')->default(0)->comment('Interest accrued and not yet paid.');
            $table->unsignedInteger('payment')->comment('The fixed instalment, rounded up. The last one is whatever is owed.');
            $table->string('state', 12)->default('active')->comment('active, defaulted or repaid (App\\Bank\\LoanState).');
            $table->timestamp('next_due_at')->nullable()->comment('When the next instalment falls due. Null once the loan is repaid.');
            $table->timestamp('missed_at')->nullable()->comment('When an instalment was missed and the grace clock started. Null when no instalment is missed.');
            $table->timestamp('defaulted_at')->nullable()->comment('When the grace ran out. Null until then.');
            $table->unsignedTinyInteger('settlement')->nullable()->comment('The share (percent) of every wallet inflow taken for the loan while a settlement runs. Null when none.');
            $table->timestamp('refused_at')->nullable()->comment('When the player refused the default call. Starts the seizure roll.');
            $table->unsignedSmallInteger('seizure_nights')->default(0)->comment('Nights the seizure roll has run since the refusal; the chance grows with it.');
            $table->timestamp('repaid_at')->nullable()->comment('When it was cleared.');
            $table->timestamps();
            $table->index(['character_id', 'state']);
            $table->index(['state', 'next_due_at']);
        });

        Schema::create('loan_payments', function (Blueprint $table): void {
            $table->comment('What each payment on a loan was made of. Immutable: the sums of interest and principal here are what the loan\'s balance is checked against (bank:verify).');
            $table->id()->comment('Primary key.');
            $table->foreignId('loan_id')->comment('The loan it paid.')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('interest')->default(0)->comment('The part that paid accrued interest.');
            $table->unsignedInteger('principal')->default(0)->comment('The part that paid principal.');
            $table->unsignedInteger('fee')->default(0)->comment('The early-payoff fee, when it was one.');
            $table->string('source', 12)->comment('What paid it: account, wallet, skim or seizure (App\\Bank\\PaymentSource).');
            $table->timestamp('created_at')->nullable()->comment('When it was paid.');
            $table->index('loan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_payments');
        Schema::dropIfExists('loans');
    }
};
