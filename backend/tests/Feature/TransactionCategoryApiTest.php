<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_assign_a_category_to_a_transaction(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'DKB Girokonto',
            'account_type' => 'checking_account',
            'institution' => 'DKB',
            'currency' => 'EUR',
        ]);

        $category = Category::query()->create([
            'user_id' => null,
            'name' => 'Lebensmittel',
            'slug' => 'lebensmittel',
            'category_type' => 'expense',
            'color' => '#059669',
            'is_system' => true,
            'sort_order' => 1,
        ]);

        $transaction = Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-04-10',
            'value_date' => '2026-04-10',
            'amount' => '-42.50',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'REWE',
            'description' => 'Wocheneinkauf',
            'transaction_hash' => hash('sha256', 'categorize-rewe'),
            'source_system' => 'dkb_giro',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/transactions/' . $transaction->id . '/category', [
            'category_id' => $category->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('transaction.id', $transaction->id);
        $response->assertJsonPath('transaction.category_id', $category->id);
        $response->assertJsonPath('transaction.category_name', 'Lebensmittel');

        $this->assertDatabaseHas('transaction_splits', [
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'split_type' => 'category_assignment',
        ]);
    }

    public function test_user_cannot_assign_category_to_foreign_transaction(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $account = Account::query()->create([
            'user_id' => $otherUser->id,
            'name' => 'Fremdkonto',
            'account_type' => 'checking_account',
            'institution' => 'Bank',
            'currency' => 'EUR',
        ]);

        $category = Category::query()->create([
            'user_id' => null,
            'name' => 'Sonstiges',
            'slug' => 'sonstiges',
            'category_type' => 'expense',
            'color' => '#6b7280',
            'is_system' => true,
            'sort_order' => 99,
        ]);

        $transaction = Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-04-10',
            'value_date' => '2026-04-10',
            'amount' => '-10.00',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'Test',
            'description' => 'Should stay hidden',
            'transaction_hash' => hash('sha256', 'foreign-transaction'),
            'source_system' => 'dkb_giro',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/transactions/' . $transaction->id . '/category', [
            'category_id' => $category->id,
        ])->assertNotFound();
    }
}
