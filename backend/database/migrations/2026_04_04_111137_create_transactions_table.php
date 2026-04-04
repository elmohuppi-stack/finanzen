<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_import_id')->nullable()->constrained()->nullOnDelete();
            $table->date('booking_date');
            $table->date('value_date')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('direction')->default('debit');
            $table->string('counterparty_name')->nullable();
            $table->text('description')->nullable();
            $table->string('external_id')->nullable();
            $table->string('transaction_hash', 64)->nullable();
            $table->string('source_system')->default('csv');
            $table->string('source_reference')->nullable();
            $table->uuid('transfer_group_id')->nullable();
            $table->boolean('is_transfer')->default(false);
            $table->boolean('is_hidden_from_cashflow')->default(false);
            $table->json('metadata')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'booking_date']);
            $table->index(['external_id', 'source_system']);
            $table->unique(['account_id', 'transaction_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
