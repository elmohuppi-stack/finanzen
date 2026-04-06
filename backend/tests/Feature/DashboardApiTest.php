<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FinanceImport;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_dashboard_data(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'DKB Girokonto',
            'account_type' => 'checking_account',
            'institution' => 'DKB',
            'currency' => 'EUR',
        ]);

        $otherAccount = Account::query()->create([
            'user_id' => $otherUser->id,
            'name' => 'Fremdkonto',
            'account_type' => 'checking_account',
            'institution' => 'Bank',
            'currency' => 'EUR',
        ]);

        $import = FinanceImport::query()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'source_type' => 'dkb_giro',
            'file_name' => 'april.csv',
            'file_hash' => hash('sha256', 'april.csv'),
            'status' => 'completed',
            'imported_rows' => 2,
            'skipped_rows' => 0,
            'error_rows' => 0,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        Transaction::query()->create([
            'account_id' => $account->id,
            'finance_import_id' => $import->id,
            'booking_date' => '2026-04-03',
            'value_date' => '2026-04-03',
            'amount' => '-31.04',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'REWE',
            'description' => 'Einkauf',
            'transaction_hash' => hash('sha256', 'user-1-rewe'),
            'source_system' => 'dkb_giro',
        ]);

        Transaction::query()->create([
            'account_id' => $account->id,
            'finance_import_id' => $import->id,
            'booking_date' => '2026-04-04',
            'value_date' => '2026-04-04',
            'amount' => '1250.00',
            'currency' => 'EUR',
            'direction' => 'credit',
            'counterparty_name' => 'Arbeitgeber',
            'description' => 'Gehalt',
            'transaction_hash' => hash('sha256', 'user-1-salary'),
            'source_system' => 'dkb_giro',
        ]);

        Transaction::query()->create([
            'account_id' => $account->id,
            'finance_import_id' => $import->id,
            'booking_date' => '2026-03-28',
            'value_date' => '2026-03-28',
            'amount' => '-9.99',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Bäcker',
            'description' => 'März',
            'transaction_hash' => hash('sha256', 'user-1-march'),
            'source_system' => 'dkb_giro',
        ]);

        Transaction::query()->create([
            'account_id' => $otherAccount->id,
            'booking_date' => '2026-04-05',
            'value_date' => '2026-04-05',
            'amount' => '-999.99',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Should not appear',
            'description' => 'Other user',
            'transaction_hash' => hash('sha256', 'user-2-hidden'),
            'source_system' => 'dkb_giro',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard?view=month&month=2026-04');

        $response->assertOk()->assertJsonStructure([
            'summary' => ['account_count', 'transaction_count', 'income', 'expenses', 'net'],
            'filters' => ['selected_view', 'selected_month', 'available_months'],
            'accounts',
            'transactions',
            'imports',
        ]);

        $response->assertJsonPath('summary.account_count', 1);
        $response->assertJsonPath('summary.transaction_count', 2);
        $response->assertJsonPath('summary.income', '1250.00');
        $response->assertJsonPath('summary.expenses', '31.04');
        $response->assertJsonPath('summary.net', '1218.96');
        $response->assertJsonPath('filters.selected_view', 'month');
        $response->assertJsonPath('filters.selected_month', '2026-04');
        $response->assertJsonCount(2, 'transactions');
        $response->assertJsonPath('transactions.0.counterparty_name', 'Arbeitgeber');
        $response->assertJsonPath('imports.0.file_name', 'april.csv');
        $response->assertJsonPath('imports.0.period_from', '2026-03-28');
        $response->assertJsonPath('imports.0.period_to', '2026-04-04');
    }

    public function test_dashboard_excludes_cashback_portions_from_cashflow_expenses(): void
    {
        $user = User::factory()->create();

        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'DKB Girokonto',
            'account_type' => 'checking_account',
            'institution' => 'DKB',
            'currency' => 'EUR',
        ]);

        $transaction = Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-04-02',
            'value_date' => '2026-04-02',
            'amount' => '-304.06',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'LIDL SAGT DANKE',
            'description' => 'Debitk.5 2027-12 Bargeldausz. 200,00 EUR • Ausgang',
            'transaction_hash' => hash('sha256', 'cashback-expense-summary'),
            'source_system' => 'dkb_giro',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard?view=month&month=2026-04');

        $response->assertOk();
        $response->assertJsonPath('summary.expenses', '104.06');
        $response->assertJsonPath('summary.net', '-104.06');
        $response->assertJsonPath('transactions.0.amount', '-304.06');
        $response->assertJsonPath('transactions.0.cashflow_amount', '-104.06');
        $response->assertJsonPath('transactions.0.cash_withdrawal_amount', '200.00');
    }

    public function test_dashboard_returns_stored_balance_snapshots_for_accounts_and_months(): void
    {
        $user = User::factory()->create();

        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'DKB Girokonto',
            'account_type' => 'checking_account',
            'institution' => 'DKB',
            'currency' => 'EUR',
            'current_balance' => '11256.27',
            'metadata' => [
                'balance_as_of' => '2026-04-04',
            ],
        ]);

        Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-03-10',
            'value_date' => '2026-03-10',
            'amount' => '-100.00',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Supermarkt',
            'description' => 'März Einkauf',
            'transaction_hash' => hash('sha256', 'march-balance-check'),
            'source_system' => 'dkb_giro',
        ]);

        Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-04-02',
            'value_date' => '2026-04-02',
            'amount' => '-31.04',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'REWE',
            'description' => 'April Einkauf',
            'transaction_hash' => hash('sha256', 'april-balance-check'),
            'source_system' => 'dkb_giro',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard?view=all');

        $response->assertOk();
        $response->assertJsonPath('summary.total_balance', '11256.27');
        $response->assertJsonPath('summary.balance_as_of', '2026-04-04');
        $response->assertJsonPath('accounts.0.current_balance', '11256.27');
        $response->assertJsonPath('accounts.0.balance_as_of', '2026-04-04');
        $response->assertJsonPath('monthly_balances.2.opening_balance', '11387.31');
        $response->assertJsonPath('monthly_balances.2.closing_balance', '11287.31');
        $response->assertJsonPath('monthly_balances.3.opening_balance', '11287.31');
        $response->assertJsonPath('monthly_balances.3.closing_balance', '11256.27');
    }

    public function test_dashboard_can_select_any_available_year_for_balance_development(): void
    {
        $user = User::factory()->create();

        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'DKB Girokonto',
            'account_type' => 'checking_account',
            'institution' => 'DKB',
            'currency' => 'EUR',
            'current_balance' => '2500.00',
            'metadata' => [
                'balance_as_of' => '2026-04-04',
            ],
        ]);

        Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2025-06-10',
            'value_date' => '2025-06-10',
            'amount' => '-50.00',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Altjahr',
            'description' => '2025 Test',
            'transaction_hash' => hash('sha256', 'year-2025'),
            'source_system' => 'dkb_giro',
        ]);

        Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-04-02',
            'value_date' => '2026-04-02',
            'amount' => '-25.00',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Aktuell',
            'description' => '2026 Test',
            'transaction_hash' => hash('sha256', 'year-2026'),
            'source_system' => 'dkb_giro',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard?view=all&year=2025');

        $response->assertOk();
        $response->assertJsonPath('filters.selected_year', 2025);
        $response->assertJsonPath('filters.available_years.0', 2026);
        $response->assertJsonPath('filters.available_years.1', 2025);
        $response->assertJsonPath('monthly_balances.5.month', '2025-06');
        $response->assertJsonPath('monthly_balances.5.expenses', '50.00');
        $response->assertJsonPath('monthly_balances.5.closing_balance', '2525.00');
    }

    public function test_dashboard_can_filter_transactions_by_account_and_search_term(): void
    {
        $user = User::factory()->create();

        $giroAccount = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'DKB Girokonto',
            'account_type' => 'checking_account',
            'institution' => 'DKB',
            'currency' => 'EUR',
        ]);

        $paypalAccount = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'PayPal',
            'account_type' => 'paypal_account',
            'institution' => 'PayPal',
            'currency' => 'EUR',
        ]);

        Transaction::query()->create([
            'account_id' => $giroAccount->id,
            'booking_date' => '2026-04-10',
            'value_date' => '2026-04-10',
            'amount' => '-42.50',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'REWE',
            'description' => 'Wocheneinkauf',
            'transaction_hash' => hash('sha256', 'filter-rewe'),
            'source_system' => 'dkb_giro',
        ]);

        Transaction::query()->create([
            'account_id' => $paypalAccount->id,
            'booking_date' => '2026-04-11',
            'value_date' => '2026-04-11',
            'amount' => '-12.99',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Steam',
            'description' => 'Game purchase',
            'transaction_hash' => hash('sha256', 'filter-steam'),
            'source_system' => 'paypal',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard?view=all&account_id=' . $giroAccount->id . '&query=rewe');

        $response->assertOk();
        $response->assertJsonPath('filters.selected_view', 'all');
        $response->assertJsonPath('filters.selected_account_id', $giroAccount->id);
        $response->assertJsonPath('filters.search_query', 'rewe');
        $response->assertJsonCount(1, 'transactions');
        $response->assertJsonPath('transactions.0.counterparty_name', 'REWE');
        $response->assertJsonPath('summary.transaction_count', 1);
        $response->assertJsonPath('summary.expenses', '42.50');
    }

    public function test_guest_cannot_access_dashboard_data(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_can_filter_by_year_view(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Account',
            'account_type' => 'checking_account',
            'institution' => 'Test',
            'currency' => 'EUR',
        ]);

        // Transactions in 2025 and 2026
        Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2025-12-31',
            'value_date' => '2025-12-31',
            'amount' => '-50.00',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Test',
            'description' => '2025 expense',
            'transaction_hash' => hash('sha256', '2025-expense'),
            'source_system' => 'test',
        ]);
        Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-01-01',
            'value_date' => '2026-01-01',
            'amount' => '100.00',
            'currency' => 'EUR',
            'direction' => 'credit',
            'counterparty_name' => 'Test',
            'description' => '2026 income',
            'transaction_hash' => hash('sha256', '2026-income'),
            'source_system' => 'test',
        ]);

        $response = $this->getJson('/api/dashboard?view=year&year=2026');

        $response->assertOk();
        $data = $response->json();
        $this->assertEquals('year', $data['filters']['selected_view']);
        $this->assertEquals(2026, $data['filters']['selected_year']);
        $this->assertContains(2026, $data['filters']['available_years']);
        $this->assertContains(2025, $data['filters']['available_years']);
        // Should only include 2026 transaction
        $this->assertCount(1, $data['transactions']);
        $this->assertEquals('100.00', $data['transactions'][0]['amount']);
    }

    public function test_dashboard_can_filter_by_custom_range_view(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Account',
            'account_type' => 'checking_account',
            'institution' => 'Test',
            'currency' => 'EUR',
        ]);

        // Transactions in different dates
        Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-03-01',
            'value_date' => '2026-03-01',
            'amount' => '-25.00',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Test',
            'description' => 'March expense',
            'transaction_hash' => hash('sha256', 'march-expense'),
            'source_system' => 'test',
        ]);
        Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-04-01',
            'value_date' => '2026-04-01',
            'amount' => '200.00',
            'currency' => 'EUR',
            'direction' => 'credit',
            'counterparty_name' => 'Test',
            'description' => 'April income',
            'transaction_hash' => hash('sha256', 'april-income'),
            'source_system' => 'test',
        ]);
        Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-05-01',
            'value_date' => '2026-05-01',
            'amount' => '-75.00',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Test',
            'description' => 'May expense',
            'transaction_hash' => hash('sha256', 'may-expense'),
            'source_system' => 'test',
        ]);

        $response = $this->getJson('/api/dashboard?view=range&date_from=2026-03-15&date_to=2026-04-15');

        $response->assertOk();
        $data = $response->json();
        $this->assertEquals('range', $data['filters']['selected_view']);
        $this->assertEquals('2026-03-15', $data['filters']['selected_date_from']);
        $this->assertEquals('2026-04-15', $data['filters']['selected_date_to']);
        // Should only include April transaction
        $this->assertCount(1, $data['transactions']);
        $this->assertEquals('200.00', $data['transactions'][0]['amount']);
    }
}
