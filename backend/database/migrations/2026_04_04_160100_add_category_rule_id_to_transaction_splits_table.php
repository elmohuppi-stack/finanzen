<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_splits', function (Blueprint $table): void {
            $table->foreignId('category_rule_id')->nullable()->constrained('category_rules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transaction_splits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_rule_id');
        });
    }
};
