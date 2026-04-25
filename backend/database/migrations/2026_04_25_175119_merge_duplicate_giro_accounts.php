<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Account #1: "Girokonto" (mit Anführungszeichen, 1157 Transaktionen)
        // Account #4: Girokonto (ohne Anführungszeichen, 24 Transaktionen)
        // Beide haben die gleiche IBAN: DE86••••8885
        // Wir führen Account #4 in Account #1 zusammen

        $oldAccountId = 1; // "Girokonto" – der mit den meisten Transaktionen
        $duplicateAccountId = 4; // Girokonto – der neuere, doppelte

        DB::transaction(function () use ($oldAccountId, $duplicateAccountId): void {
            // 1. FinanceImports umhängen
            DB::table('finance_imports')
                ->where('account_id', $duplicateAccountId)
                ->update(['account_id' => $oldAccountId]);

            // 2. Transactions umhängen
            DB::table('transactions')
                ->where('account_id', $duplicateAccountId)
                ->update(['account_id' => $oldAccountId]);

            // 3. TransactionSplits (via transactions) sind durch Foreign Key automatisch mit umgehängt

            // 4. Namen bereinigen: Anführungszeichen aus Account #1 entfernen
            DB::table('accounts')
                ->where('id', $oldAccountId)
                ->update(['name' => 'Girokonto']);

            // 5. Duplikat löschen
            DB::table('accounts')
                ->where('id', $duplicateAccountId)
                ->delete();
        });
    }

    public function down(): void
    {
        // Nicht umkehrbar – Daten sind zusammengeführt
    }
};
