<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FinanceImport;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_preview_a_csv_import(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $content = <<<'CSV'
"Girokonto";"DE86120300000016478885"
""
"Buchungsdatum";"Wertstellung";"Status";"Zahlungspflichtige*r";"Zahlungsempfänger*in";"Verwendungszweck";"Umsatztyp";"IBAN";"Betrag (€)";"Gläubiger-ID";"Mandatsreferenz";"Kundenreferenz"
"02.04.26";"02.04.26";"Gebucht";"ISSUER";"REWE";"Girokartenumsatz";"Ausgang";"DE27700202700015820743";"-31,04";"";"";"56006389643387010426134430"
CSV;

        $file = UploadedFile::fake()->createWithContent('giro.csv', $content);

        $response = $this->postJson('/api/imports/detect', [
            'file' => $file,
        ]);

        $response->assertOk()->assertJsonStructure([
            'detected_type',
            'file_name',
            'file_hash',
            'line_count',
            'delimiter',
            'headers',
            'sample_rows',
        ]);

        $response->assertJsonPath('detected_type', 'dkb_giro');
        $response->assertJsonPath('headers.0', 'Buchungsdatum');
        $response->assertJsonPath('sample_rows.0.Zahlungsempfänger*in', 'REWE');
    }

    public function test_authenticated_user_can_import_a_csv_and_persist_transactions(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $content = <<<'CSV'
"Girokonto";"DE86120300000016478885"
""
"Buchungsdatum";"Wertstellung";"Status";"Zahlungspflichtige*r";"Zahlungsempfänger*in";"Verwendungszweck";"Umsatztyp";"IBAN";"Betrag (€)";"Gläubiger-ID";"Mandatsreferenz";"Kundenreferenz"
"02.04.26";"02.04.26";"Gebucht";"ISSUER";"REWE";"Girokartenumsatz";"Ausgang";"DE27700202700015820743";"-31,04";"";"";"56006389643387010426134430"
CSV;

        $file = UploadedFile::fake()->createWithContent('giro.csv', $content);

        $response = $this->postJson('/api/imports', [
            'file' => $file,
        ]);

        $response->assertCreated()->assertJsonPath('import.source_type', 'dkb_giro');
        $response->assertJsonPath('import.status', 'completed');
        $response->assertJsonPath('import.imported_rows', 1);
        $response->assertJsonPath('import.skipped_rows', 0);

        $this->assertDatabaseCount('finance_imports', 1);
        $this->assertDatabaseCount('accounts', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('transactions', [
            'source_system' => 'dkb_giro',
            'counterparty_name' => 'REWE',
            'direction' => 'debit',
        ]);
    }

    public function test_reimport_of_the_same_csv_is_duplicate_safe(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $content = <<<'CSV'
"Girokonto";"DE86120300000016478885"
""
"Buchungsdatum";"Wertstellung";"Status";"Zahlungspflichtige*r";"Zahlungsempfänger*in";"Verwendungszweck";"Umsatztyp";"IBAN";"Betrag (€)";"Gläubiger-ID";"Mandatsreferenz";"Kundenreferenz"
"02.04.26";"02.04.26";"Gebucht";"ISSUER";"REWE";"Girokartenumsatz";"Ausgang";"DE27700202700015820743";"-31,04";"";"";"56006389643387010426134430"
CSV;

        $firstFile = UploadedFile::fake()->createWithContent('giro.csv', $content);
        $secondFile = UploadedFile::fake()->createWithContent('giro.csv', $content);

        $this->postJson('/api/imports', [
            'file' => $firstFile,
        ])->assertCreated();

        $response = $this->postJson('/api/imports', [
            'file' => $secondFile,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('import.imported_rows', 0);
        $response->assertJsonPath('import.skipped_rows', 1);

        $this->assertDatabaseCount('finance_imports', 2);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_authenticated_user_can_fetch_import_history(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'DKB Girokonto',
            'account_type' => 'checking_account',
            'institution' => 'DKB',
            'currency' => 'EUR',
        ]);

        $import = FinanceImport::query()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'source_type' => 'dkb_giro',
            'file_name' => 'maerz.csv',
            'file_hash' => hash('sha256', 'maerz.csv'),
            'status' => 'completed',
            'imported_rows' => 3,
            'skipped_rows' => 1,
            'error_rows' => 0,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
        ]);

        Transaction::query()->create([
            'account_id' => $account->id,
            'finance_import_id' => $import->id,
            'booking_date' => '2026-03-01',
            'value_date' => '2026-03-01',
            'amount' => '-10.00',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Test',
            'description' => 'Import history',
            'transaction_hash' => hash('sha256', 'import-history-1'),
            'source_system' => 'dkb_giro',
        ]);

        Transaction::query()->create([
            'account_id' => $account->id,
            'finance_import_id' => $import->id,
            'booking_date' => '2026-03-15',
            'value_date' => '2026-03-15',
            'amount' => '-20.00',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Test 2',
            'description' => 'Import history',
            'transaction_hash' => hash('sha256', 'import-history-2'),
            'source_system' => 'dkb_giro',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/imports');

        $response->assertOk()->assertJsonStructure([
            'imports' => [[
                'id',
                'source_type',
                'file_name',
                'status',
                'imported_rows',
                'skipped_rows',
                'error_rows',
                'imported_at',
                'period_from',
                'period_to',
                'account_name',
            ]],
        ]);

        $response->assertJsonPath('imports.0.file_name', 'maerz.csv');
        $response->assertJsonPath('imports.0.period_from', '2026-03-01');
        $response->assertJsonPath('imports.0.period_to', '2026-03-15');
        $response->assertJsonPath('imports.0.account_name', 'DKB Girokonto');
    }

    public function test_guest_cannot_preview_a_csv_import(): void
    {
        $file = UploadedFile::fake()->createWithContent('giro.csv', '"Buchungsdatum";"Betrag (€)"');

        $response = $this->postJson('/api/imports/detect', [
            'file' => $file,
        ]);

        $response->assertUnauthorized();
    }
}
