<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_update_and_delete_custom_category(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/categories', [
            'name' => 'Sport & Fitness',
            'category_type' => 'expense',
            'color' => '#f97316',
        ]);

        $createResponse->assertCreated();
        $categoryId = $createResponse->json('category.id');

        $this->assertDatabaseHas('categories', [
            'id' => $categoryId,
            'user_id' => $user->id,
            'name' => 'Sport & Fitness',
            'slug' => 'sport-fitness',
            'category_type' => 'expense',
            'color' => '#f97316',
            'is_system' => false,
        ]);

        $updateResponse = $this->patchJson('/api/categories/' . $categoryId, [
            'name' => 'Fitness',
            'category_type' => 'expense',
            'color' => '#ea580c',
        ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('category.name', 'Fitness');
        $updateResponse->assertJsonPath('category.slug', 'fitness');

        $deleteResponse = $this->deleteJson('/api/categories/' . $categoryId);

        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('deleted', true);
        $this->assertDatabaseMissing('categories', [
            'id' => $categoryId,
        ]);
    }

    public function test_user_cannot_manage_system_or_foreign_categories(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $systemCategory = Category::query()->create([
            'user_id' => null,
            'name' => 'Lebensmittel',
            'slug' => 'lebensmittel',
            'category_type' => 'expense',
            'color' => '#059669',
            'is_system' => true,
            'sort_order' => 10,
        ]);

        $foreignCategory = Category::query()->create([
            'user_id' => $otherUser->id,
            'name' => 'Reisen',
            'slug' => 'reisen',
            'category_type' => 'expense',
            'color' => '#2563eb',
            'is_system' => false,
            'sort_order' => 20,
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/categories/' . $systemCategory->id, [
            'name' => 'Soll gesperrt bleiben',
        ])->assertNotFound();

        $this->deleteJson('/api/categories/' . $systemCategory->id)->assertNotFound();

        $this->patchJson('/api/categories/' . $foreignCategory->id, [
            'name' => 'Fremd',
        ])->assertNotFound();

        $this->deleteJson('/api/categories/' . $foreignCategory->id)->assertNotFound();
    }

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

    public function test_assigning_transfer_category_marks_transaction_as_hidden_from_cashflow(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'DKB Visa',
            'account_type' => 'credit_card',
            'institution' => 'DKB',
            'currency' => 'EUR',
        ]);

        $category = Category::query()->create([
            'user_id' => null,
            'name' => 'Transfer',
            'slug' => 'transfer',
            'category_type' => 'transfer',
            'color' => '#6b7280',
            'is_system' => true,
            'sort_order' => 2,
        ]);

        $transaction = Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-04-10',
            'value_date' => '2026-04-10',
            'amount' => '-42.50',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'DKB',
            'description' => 'KREDITKARTENABRECHNUNG',
            'transaction_hash' => hash('sha256', 'transfer-assignment'),
            'source_system' => 'dkb_giro',
        ]);

        $visaAccount = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'DKB Visa',
            'account_type' => 'credit_card',
            'institution' => 'DKB',
            'currency' => 'EUR',
        ]);

        $counterTransaction = Transaction::query()->create([
            'account_id' => $visaAccount->id,
            'booking_date' => '2026-04-11',
            'value_date' => '2026-04-11',
            'amount' => '42.50',
            'currency' => 'EUR',
            'direction' => 'credit',
            'counterparty_name' => 'DKB Visa',
            'description' => 'Ausgleich Kreditkarte gem. Lastschrift',
            'transaction_hash' => hash('sha256', 'transfer-counter-assignment'),
            'source_system' => 'dkb_visa',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/transactions/' . $transaction->id . '/category', [
            'category_id' => $category->id,
        ]);

        $this->patchJson('/api/transactions/' . $counterTransaction->id . '/category', [
            'category_id' => $category->id,
        ])->assertOk();

        $response->assertOk();
        $response->assertJsonPath('transaction.category_name', 'Transfer');

        $transaction->refresh();
        $counterTransaction->refresh();

        $this->assertTrue($transaction->is_transfer);
        $this->assertTrue($transaction->is_hidden_from_cashflow);
        $this->assertSame('credit_card_settlement', data_get($transaction->metadata, 'transfer_kind'));
        $this->assertNotNull($transaction->transfer_group_id);
        $this->assertSame($transaction->transfer_group_id, $counterTransaction->transfer_group_id);

        $this->assertDatabaseHas('transaction_links', [
            'link_type' => 'credit_card_settlement',
            'amount' => '42.50',
        ]);

        $link = TransactionLink::query()
            ->where('link_type', 'credit_card_settlement')
            ->first();

        $this->assertNotNull($link);
        $this->assertContains($transaction->id, [$link->from_transaction_id, $link->to_transaction_id]);
        $this->assertContains($counterTransaction->id, [$link->from_transaction_id, $link->to_transaction_id]);
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
