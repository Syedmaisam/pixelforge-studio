<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An append-only ledger. A user's balance is the sum of their rows.
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->cascadeOnDelete();

            // welcome_bonus | purchase | hold | release | charge
            $table->string('type');

            // Signed nanodollars: + adds credit, - removes it.
            $table->bigInteger('amount_nanos');
            $table->string('description')->nullable();
            $table->timestamps();

            // The safety net against double charging: one generation can have
            // at most ONE hold, ONE release and ONE charge. The database itself
            // refuses a second one, whatever bug or duplicate webhook tries.
            $table->unique(['generation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
