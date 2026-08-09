<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashWalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_cash_expense_creates_wallet_and_updates_balance(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory('Mobilität', 'expense');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/transactions', [
            'booking_date' => '2026-08-05',
            'amount' => '480.00',
            'entry_type' => 'expense',
            'counterparty_name' => 'Autowerkstatt Müller',
            'description' => 'Bremsen vorne, bar bezahlt',
            'category_id' => $category->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('transaction.amount', '-480.00');
        $response->assertJsonPath('transaction.account_type', CashWalletService::ACCOUNT_TYPE);
        $response->assertJsonPath('transaction.category_name', 'Mobilität');
        $response->assertJsonPath('transaction.source_system', 'manual');

        $wallet = Account::query()
            ->where('user_id', $user->id)
            ->where('account_type', CashWalletService::ACCOUNT_TYPE)
            ->firstOrFail();

        $this->assertSame('Bargeld', $wallet->name);
        $this->assertSame('-480.00', $wallet->current_balance);
        $this->assertSame('2026-08-05', data_get($wallet->metadata, 'balance_as_of'));

        $this->assertDatabaseHas('transaction_splits', [
            'transaction_id' => $response->json('transaction.id'),
            'category_id' => $category->id,
            'split_type' => 'category_assignment',
            'amount' => '-480.00',
        ]);
    }

    public function test_manual_cash_expense_is_categorized_by_existing_rule(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory('Lebensmittel', 'expense');

        CategoryRule::query()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'name' => 'Wochenmarkt',
            'pattern' => 'wochenmarkt',
            'match_field' => 'counterparty',
            'priority' => 140,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/transactions', [
            'booking_date' => '2026-08-06',
            'amount' => '23.50',
            'counterparty_name' => 'Wochenmarkt Stand 4',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('transaction.category_name', 'Lebensmittel');
        $response->assertJsonPath('transaction.category_source', 'rule');
    }

    public function test_cash_withdrawal_creates_linked_counter_booking_in_wallet(): void
    {
        $user = User::factory()->create();
        $giroAccount = $this->createCheckingAccount($user);
        $transferCategory = $this->createCategory('Transfer', 'transfer');

        $withdrawal = Transaction::query()->create([
            'account_id' => $giroAccount->id,
            'booking_date' => '2026-08-04',
            'value_date' => '2026-08-04',
            'amount' => '-200.00',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Geldautomat Karlsruhe',
            'description' => 'Bargeldauszahlung Geldautomat',
            'transaction_hash' => hash('sha256', 'giro-withdrawal'),
            'source_system' => 'dkb_giro',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/transactions/' . $withdrawal->id . '/category', [
            'category_id' => $transferCategory->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('transaction.is_transfer', true);
        $response->assertJsonPath('transaction.transfer_kind', 'cash_withdrawal');

        $wallet = Account::query()
            ->where('user_id', $user->id)
            ->where('account_type', CashWalletService::ACCOUNT_TYPE)
            ->firstOrFail();

        $mirror = Transaction::query()
            ->where('account_id', $wallet->id)
            ->where('source_system', CashWalletService::MIRROR_SOURCE_SYSTEM)
            ->firstOrFail();

        $this->assertSame('200.00', $mirror->amount);
        $this->assertTrue($mirror->is_transfer);
        $this->assertTrue($mirror->is_hidden_from_cashflow);
        $this->assertSame((string) $withdrawal->id, $mirror->source_reference);
        $this->assertSame('200.00', $wallet->fresh()->current_balance);

        $this->assertNotNull($mirror->transfer_group_id);
        $this->assertSame($withdrawal->fresh()->transfer_group_id, $mirror->transfer_group_id);

        $this->assertDatabaseHas('transaction_links', [
            'link_type' => 'cash_withdrawal',
            'amount' => '200.00',
        ]);
    }

    public function test_applying_rules_creates_counter_booking_for_withdrawals(): void
    {
        $user = User::factory()->create();
        $giroAccount = $this->createCheckingAccount($user);
        $transferCategory = $this->createCategory('Transfer', 'transfer');

        CategoryRule::query()->create([
            'user_id' => $user->id,
            'category_id' => $transferCategory->id,
            'name' => 'Geldautomat',
            'pattern' => 'geldautomat',
            'match_field' => 'both',
            'priority' => 150,
            'is_active' => true,
        ]);

        Transaction::query()->create([
            'account_id' => $giroAccount->id,
            'booking_date' => '2026-08-04',
            'value_date' => '2026-08-04',
            'amount' => '-150.00',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Geldautomat Karlsruhe',
            'description' => 'Bargeldauszahlung Geldautomat',
            'transaction_hash' => hash('sha256', 'giro-withdrawal-rules'),
            'source_system' => 'dkb_giro',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/category-rules/apply')->assertOk();

        $wallet = Account::query()
            ->where('user_id', $user->id)
            ->where('account_type', CashWalletService::ACCOUNT_TYPE)
            ->firstOrFail();

        $this->assertSame('150.00', $wallet->current_balance);
        $this->assertDatabaseHas('transactions', [
            'account_id' => $wallet->id,
            'source_system' => CashWalletService::MIRROR_SOURCE_SYSTEM,
            'amount' => '150.00',
            'is_hidden_from_cashflow' => true,
        ]);
    }

    public function test_counter_booking_is_removed_when_withdrawal_is_recategorized(): void
    {
        $user = User::factory()->create();
        $giroAccount = $this->createCheckingAccount($user);
        $transferCategory = $this->createCategory('Transfer', 'transfer');
        $expenseCategory = $this->createCategory('Haushalt und Kleidung', 'expense');

        $withdrawal = Transaction::query()->create([
            'account_id' => $giroAccount->id,
            'booking_date' => '2026-08-04',
            'value_date' => '2026-08-04',
            'amount' => '-100.00',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Geldautomat',
            'description' => 'Bargeldauszahlung Geldautomat',
            'transaction_hash' => hash('sha256', 'giro-withdrawal-2'),
            'source_system' => 'dkb_giro',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/transactions/' . $withdrawal->id . '/category', [
            'category_id' => $transferCategory->id,
        ])->assertOk();

        $this->assertDatabaseCount('transactions', 2);

        $this->patchJson('/api/transactions/' . $withdrawal->id . '/category', [
            'category_id' => $expenseCategory->id,
        ])->assertOk();

        $this->assertDatabaseMissing('transactions', [
            'source_system' => CashWalletService::MIRROR_SOURCE_SYSTEM,
        ]);

        $wallet = Account::query()
            ->where('user_id', $user->id)
            ->where('account_type', CashWalletService::ACCOUNT_TYPE)
            ->firstOrFail();

        $this->assertSame('0.00', $wallet->current_balance);
    }

    public function test_mirror_start_date_limits_and_cleans_up_counter_bookings(): void
    {
        $user = User::factory()->create();
        $giroAccount = $this->createCheckingAccount($user);
        $transferCategory = $this->createCategory('Transfer', 'transfer');

        $oldWithdrawal = $this->createWithdrawal($giroAccount, '2024-05-10', '-300.00', 'giro-old');
        $newWithdrawal = $this->createWithdrawal($giroAccount, '2026-02-10', '-100.00', 'giro-new');

        Sanctum::actingAs($user);

        foreach ([$oldWithdrawal, $newWithdrawal] as $withdrawal) {
            $this->patchJson('/api/transactions/' . $withdrawal->id . '/category', [
                'category_id' => $transferCategory->id,
            ])->assertOk();
        }

        $this->assertSame(2, Transaction::query()
            ->where('source_system', CashWalletService::MIRROR_SOURCE_SYSTEM)
            ->count());

        $this->artisan('cash:sync-mirrors', ['--since' => '2026-01-01'])
            ->assertSuccessful();

        $mirrors = Transaction::query()
            ->where('source_system', CashWalletService::MIRROR_SOURCE_SYSTEM)
            ->get();

        $this->assertCount(1, $mirrors);
        $this->assertSame((string) $newWithdrawal->id, $mirrors->first()->source_reference);

        $wallet = app(CashWalletService::class)->findForUser($user);
        $this->assertSame('100.00', $wallet->current_balance);

        // Eine erneute Kategorisierung der alten Abhebung legt keine Gegenbuchung mehr an.
        $this->patchJson('/api/transactions/' . $oldWithdrawal->id . '/category', [
            'category_id' => $transferCategory->id,
        ])->assertOk();

        $this->assertSame(1, Transaction::query()
            ->where('source_system', CashWalletService::MIRROR_SOURCE_SYSTEM)
            ->count());
    }

    public function test_manual_transaction_can_be_updated_and_deleted(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory('Mobilität', 'expense');

        Sanctum::actingAs($user);

        $transactionId = $this->postJson('/api/transactions', [
            'booking_date' => '2026-08-05',
            'amount' => '480.00',
            'counterparty_name' => 'Autowerkstatt Müller',
        ])->assertCreated()->json('transaction.id');

        $updateResponse = $this->patchJson('/api/transactions/' . $transactionId, [
            'booking_date' => '2026-08-06',
            'amount' => '520.00',
            'entry_type' => 'expense',
            'counterparty_name' => 'Autowerkstatt Müller',
            'description' => 'Bremsen und Ölwechsel',
            'category_id' => $category->id,
        ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('transaction.amount', '-520.00');
        $updateResponse->assertJsonPath('transaction.booking_date', '2026-08-06');
        $updateResponse->assertJsonPath('transaction.category_name', 'Mobilität');

        $wallet = Account::query()
            ->where('user_id', $user->id)
            ->where('account_type', CashWalletService::ACCOUNT_TYPE)
            ->firstOrFail();

        $this->assertSame('-520.00', $wallet->current_balance);

        $this->deleteJson('/api/transactions/' . $transactionId)
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('transactions', ['id' => $transactionId]);
        $this->assertSame('0.00', $wallet->fresh()->current_balance);
    }

    public function test_imported_transactions_cannot_be_edited_or_deleted(): void
    {
        $user = User::factory()->create();
        $giroAccount = $this->createCheckingAccount($user);

        $transaction = Transaction::query()->create([
            'account_id' => $giroAccount->id,
            'booking_date' => '2026-08-04',
            'amount' => '-31.04',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'REWE',
            'transaction_hash' => hash('sha256', 'giro-rewe'),
            'source_system' => 'dkb_giro',
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/transactions/' . $transaction->id)->assertForbidden();

        $this->patchJson('/api/transactions/' . $transaction->id, [
            'booking_date' => '2026-08-04',
            'amount' => '10.00',
            'counterparty_name' => 'REWE',
        ])->assertForbidden();

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    public function test_user_cannot_touch_foreign_transactions(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $foreignAccount = $this->createCheckingAccount($otherUser);

        $foreignTransaction = Transaction::query()->create([
            'account_id' => $foreignAccount->id,
            'booking_date' => '2026-08-04',
            'amount' => '-12.00',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Fremd',
            'transaction_hash' => hash('sha256', 'foreign'),
            'source_system' => 'manual',
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/transactions/' . $foreignTransaction->id)->assertNotFound();

        $this->postJson('/api/transactions', [
            'booking_date' => '2026-08-05',
            'amount' => '10.00',
            'counterparty_name' => 'Kiosk',
            'account_id' => $foreignAccount->id,
        ])->assertUnprocessable();
    }

    private function createWithdrawal(Account $account, string $bookingDate, string $amount, string $hashSeed): Transaction
    {
        return Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => $bookingDate,
            'value_date' => $bookingDate,
            'amount' => $amount,
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Geldautomat',
            'description' => 'Bargeldauszahlung Geldautomat',
            'transaction_hash' => hash('sha256', $hashSeed),
            'source_system' => 'dkb_giro',
        ]);
    }

    private function createCheckingAccount(User $user): Account
    {
        return Account::query()->create([
            'user_id' => $user->id,
            'name' => 'DKB Girokonto',
            'account_type' => 'checking_account',
            'institution' => 'DKB',
            'currency' => 'EUR',
        ]);
    }

    private function createCategory(string $name, string $categoryType): Category
    {
        return Category::query()->create([
            'user_id' => null,
            'name' => $name,
            'slug' => str($name)->slug()->value(),
            'category_type' => $categoryType,
            'color' => '#64748b',
            'is_system' => true,
            'sort_order' => 10,
        ]);
    }
}
