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
        Schema::create('transaction_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('to_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->string('link_type');
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['from_transaction_id', 'to_transaction_id', 'link_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_links');
    }
};
