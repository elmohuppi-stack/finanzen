<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryRuleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_and_list_category_rules(): void
    {
        $user = User::factory()->create();

        $category = Category::query()->create([
            'user_id' => $user->id,
            'name' => 'Gehalt',
            'slug' => 'gehalt',
            'category_type' => 'income',
            'color' => '#2563eb',
            'is_system' => false,
            'sort_order' => 10,
        ]);

        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/category-rules', [
            'category_id' => $category->id,
            'pattern' => 'lohn',
            'match_field' => 'description',
            'match_type' => 'contains',
            'priority' => 50,
            'is_active' => true,
        ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('rule.category_id', $category->id);
        $createResponse->assertJsonPath('rule.category_name', 'Gehalt');
        $createResponse->assertJsonPath('rule.pattern', 'lohn');
        $createResponse->assertJsonPath('rule.match_field', 'description');

        $listResponse = $this->getJson('/api/category-rules');

        $listResponse->assertOk();
        $listResponse->assertJsonPath('categories.0.name', 'Gehalt');
        $listResponse->assertJsonPath('rules.0.pattern', 'lohn');
        $listResponse->assertJsonPath('rules.0.category_name', 'Gehalt');
    }

    public function test_user_can_export_import_and_reset_category_rules_as_csv(): void
    {
        $user = User::factory()->create();

        $salaryCategory = Category::query()->create([
            'user_id' => $user->id,
            'name' => 'Gehalt',
            'slug' => 'gehalt',
            'category_type' => 'income',
            'color' => '#2563eb',
            'is_system' => false,
            'sort_order' => 10,
        ]);

        $foodCategory = Category::query()->create([
            'user_id' => $user->id,
            'name' => 'Lebensmittel',
            'slug' => 'lebensmittel',
            'category_type' => 'expense',
            'color' => '#059669',
            'is_system' => false,
            'sort_order' => 20,
        ]);

        CategoryRule::query()->create([
            'user_id' => $user->id,
            'category_id' => $salaryCategory->id,
            'pattern' => 'lohn',
            'match_field' => 'both',
            'match_type' => 'contains',
            'priority' => 280,
            'is_active' => true,
        ]);

        CategoryRule::query()->create([
            'user_id' => $user->id,
            'category_id' => $foodCategory->id,
            'name' => 'Supermarkt',
            'pattern' => 'rewe',
            'match_field' => 'both',
            'match_type' => 'contains',
            'priority' => 150,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $exportResponse = $this->get('/api/category-rules/export');

        $exportResponse->assertOk();
        $exportResponse->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $exportResponse->assertSee('category_name,pattern,match_field,priority,is_active,name', false);
        $exportResponse->assertSee('Gehalt,lohn,both,280,1,', false);
        $exportResponse->assertSee('Lebensmittel,rewe,both,150,1,Supermarkt', false);

        $resetResponse = $this->deleteJson('/api/category-rules/reset');

        $resetResponse->assertOk();
        $resetResponse->assertJsonPath('deleted_rules', 2);
        $this->assertDatabaseCount('category_rules', 0);

        $importResponse = $this->postJson('/api/category-rules/import', [
            'csv_content' => $exportResponse->getContent(),
            'mode' => 'replace',
        ]);

        $importResponse->assertOk();
        $importResponse->assertJsonPath('summary.imported_rules', 2);
        $importResponse->assertJsonPath('summary.updated_rules', 0);
        $importResponse->assertJsonPath('summary.skipped_rows', 0);

        $this->assertDatabaseHas('category_rules', [
            'user_id' => $user->id,
            'category_id' => $salaryCategory->id,
            'pattern' => 'lohn',
        ]);
        $this->assertDatabaseHas('category_rules', [
            'user_id' => $user->id,
            'category_id' => $foodCategory->id,
            'pattern' => 'rewe',
        ]);
    }

    public function test_user_can_import_default_category_rules_without_creating_duplicates(): void
    {
        $user = User::factory()->create();

        Category::query()->create([
            'user_id' => $user->id,
            'name' => 'Gehalt',
            'slug' => 'gehalt',
            'category_type' => 'income',
            'color' => '#2563eb',
            'is_system' => false,
            'sort_order' => 10,
        ]);

        Category::query()->create([
            'user_id' => $user->id,
            'name' => 'Lebensmittel',
            'slug' => 'lebensmittel',
            'category_type' => 'expense',
            'color' => '#059669',
            'is_system' => false,
            'sort_order' => 20,
        ]);

        Category::query()->create([
            'user_id' => $user->id,
            'name' => 'Transfer',
            'slug' => 'transfer',
            'category_type' => 'transfer',
            'color' => '#6b7280',
            'is_system' => false,
            'sort_order' => 30,
        ]);

        Sanctum::actingAs($user);

        $firstImport = $this->postJson('/api/category-rules/import-defaults');
        $secondImport = $this->postJson('/api/category-rules/import-defaults');

        $firstImport->assertOk();
        $firstImport->assertJsonPath('summary.imported_rules', 7);
        $firstImport->assertJsonPath('summary.updated_rules', 0);

        $secondImport->assertOk();
        $secondImport->assertJsonPath('summary.imported_rules', 0);
        $secondImport->assertJsonPath('summary.updated_rules', 7);

        $this->assertDatabaseHas('category_rules', [
            'user_id' => $user->id,
            'pattern' => 'lohn',
        ]);
        $this->assertDatabaseHas('category_rules', [
            'user_id' => $user->id,
            'pattern' => 'rewe',
        ]);
        $this->assertDatabaseHas('category_rules', [
            'user_id' => $user->id,
            'pattern' => 'visa abrechnung',
        ]);
    }

    public function test_user_can_preview_matching_transactions_for_a_rule_before_saving(): void
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
            'user_id' => $user->id,
            'name' => 'Gehalt',
            'slug' => 'gehalt',
            'category_type' => 'income',
            'color' => '#2563eb',
            'is_system' => false,
            'sort_order' => 10,
        ]);

        $matchingTransaction = Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-04-29',
            'value_date' => '2026-04-29',
            'amount' => '3550.00',
            'currency' => 'EUR',
            'direction' => 'credit',
            'counterparty_name' => 'Hepp Elmar',
            'description' => 'Lohn / Gehalt Abrechnung 04/2026',
            'transaction_hash' => hash('sha256', 'preview-salary-april'),
            'source_system' => 'dkb_giro',
        ]);

        Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-04-25',
            'value_date' => '2026-04-25',
            'amount' => '-42.50',
            'currency' => 'EUR',
            'direction' => 'debit',
            'counterparty_name' => 'REWE',
            'description' => 'Wocheneinkauf',
            'transaction_hash' => hash('sha256', 'preview-groceries'),
            'source_system' => 'dkb_giro',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/category-rules/preview', [
            'category_id' => $category->id,
            'pattern' => 'lohn',
            'match_field' => 'description',
            'match_type' => 'contains',
        ]);

        $response->assertOk();
        $response->assertJsonPath('summary.matched_transactions', 1);
        $response->assertJsonPath('summary.category_name', 'Gehalt');
        $response->assertJsonPath('transactions.0.id', $matchingTransaction->id);
        $response->assertJsonPath('transactions.0.counterparty_name', 'Hepp Elmar');
        $response->assertJsonPath('transactions.0.category_source', 'none');
    }

    public function test_applying_category_rules_categorizes_matching_transactions_and_preserves_manual_overrides(): void
    {
        $user = User::factory()->create();

        $account = Account::query()->create([
            'user_id' => $user->id,
            'name' => 'DKB Girokonto',
            'account_type' => 'checking_account',
            'institution' => 'DKB',
            'currency' => 'EUR',
        ]);

        $salaryCategory = Category::query()->create([
            'user_id' => $user->id,
            'name' => 'Gehalt',
            'slug' => 'gehalt',
            'category_type' => 'income',
            'color' => '#2563eb',
            'is_system' => false,
            'sort_order' => 10,
        ]);

        $bonusCategory = Category::query()->create([
            'user_id' => $user->id,
            'name' => 'Bonus',
            'slug' => 'bonus',
            'category_type' => 'income',
            'color' => '#7c3aed',
            'is_system' => false,
            'sort_order' => 20,
        ]);

        $marchSalary = Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-03-28',
            'value_date' => '2026-03-28',
            'amount' => '3500.00',
            'currency' => 'EUR',
            'direction' => 'credit',
            'counterparty_name' => 'Hepp Elmar',
            'description' => 'Hepp Elmar Lohn - Gehalt Abrechnung 03/2026 • Eingang',
            'transaction_hash' => hash('sha256', 'salary-march'),
            'source_system' => 'dkb_giro',
        ]);

        $aprilSalary = Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-04-29',
            'value_date' => '2026-04-29',
            'amount' => '3550.00',
            'currency' => 'EUR',
            'direction' => 'credit',
            'counterparty_name' => 'Hepp Elmar',
            'description' => 'Lohn / Gehalt Abrechnung 04/2026',
            'transaction_hash' => hash('sha256', 'salary-april'),
            'source_system' => 'dkb_giro',
        ]);

        $manualTransaction = Transaction::query()->create([
            'account_id' => $account->id,
            'booking_date' => '2026-04-30',
            'value_date' => '2026-04-30',
            'amount' => '500.00',
            'currency' => 'EUR',
            'direction' => 'credit',
            'counterparty_name' => 'Hepp Elmar',
            'description' => 'Lohn Nachzahlung / Bonus',
            'transaction_hash' => hash('sha256', 'salary-bonus'),
            'source_system' => 'dkb_giro',
        ]);

        $rule = CategoryRule::query()->create([
            'user_id' => $user->id,
            'category_id' => $salaryCategory->id,
            'pattern' => 'lohn',
            'match_field' => 'description',
            'match_type' => 'contains',
            'priority' => 50,
            'is_active' => true,
        ]);

        TransactionSplit::query()->create([
            'transaction_id' => $manualTransaction->id,
            'category_id' => $bonusCategory->id,
            'category_rule_id' => null,
            'name' => $bonusCategory->name,
            'amount' => $manualTransaction->amount,
            'split_type' => 'category_assignment',
            'notes' => null,
            'sort_order' => 0,
            'metadata' => ['source' => 'manual'],
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/category-rules/apply');

        $response->assertOk();
        $response->assertJsonPath('summary.matched_transactions', 2);
        $response->assertJsonPath('summary.updated_transactions', 2);
        $response->assertJsonPath('summary.skipped_manual_transactions', 1);

        $marchSplit = TransactionSplit::query()
            ->where('transaction_id', $marchSalary->id)
            ->where('split_type', 'category_assignment')
            ->firstOrFail();
        $aprilSplit = TransactionSplit::query()
            ->where('transaction_id', $aprilSalary->id)
            ->where('split_type', 'category_assignment')
            ->firstOrFail();
        $manualSplit = TransactionSplit::query()
            ->where('transaction_id', $manualTransaction->id)
            ->where('split_type', 'category_assignment')
            ->firstOrFail();

        $this->assertSame($salaryCategory->id, $marchSplit->category_id);
        $this->assertSame($salaryCategory->id, $aprilSplit->category_id);
        $this->assertSame($rule->id, $marchSplit->category_rule_id);
        $this->assertSame($rule->id, $aprilSplit->category_rule_id);
        $this->assertSame('rule', data_get($marchSplit->metadata, 'source'));
        $this->assertSame('rule', data_get($aprilSplit->metadata, 'source'));

        $this->assertSame($bonusCategory->id, $manualSplit->category_id);
        $this->assertNull($manualSplit->category_rule_id);
        $this->assertSame('manual', data_get($manualSplit->metadata, 'source'));
    }
}
