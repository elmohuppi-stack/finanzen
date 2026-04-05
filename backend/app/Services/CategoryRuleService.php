<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CategoryRuleService
{
    public function __construct(
        private readonly TransactionTransferService $transactionTransferService,
    ) {}

    public function exportRulesForUser(User $user): string
    {
        $rules = CategoryRule::query()
            ->where('user_id', $user->id)
            ->with('category:id,name')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['category_name', 'pattern', 'match_field', 'priority', 'is_active', 'name']);

        foreach ($rules as $rule) {
            fputcsv($handle, [
                $rule->category?->name ?? '',
                $rule->pattern,
                $rule->match_field,
                (string) $rule->priority,
                $rule->is_active ? '1' : '0',
                $rule->name ?? '',
            ]);
        }

        rewind($handle);

        $csv = stream_get_contents($handle) ?: '';

        fclose($handle);

        return $csv;
    }

    /**
     * @return array{imported_rules: int, updated_rules: int, skipped_rows: int}
     */
    public function importRulesFromCsv(User $user, string $csvContent, string $mode = 'merge'): array
    {
        if ($mode === 'replace') {
            $this->resetRulesForUser($user);
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return [
                'imported_rules' => 0,
                'updated_rules' => 0,
                'skipped_rows' => 0,
            ];
        }

        fwrite($handle, $csvContent);
        rewind($handle);

        $header = fgetcsv($handle);

        if (! is_array($header)) {
            fclose($handle);

            return [
                'imported_rules' => 0,
                'updated_rules' => 0,
                'skipped_rows' => 0,
            ];
        }

        $normalizedHeader = array_map(static fn($value): string => trim((string) $value), $header);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null]) {
                continue;
            }

            $rows[] = collect($normalizedHeader)
                ->mapWithKeys(static fn(string $key, int $index): array => [
                    $key => trim((string) ($row[$index] ?? '')),
                ])
                ->all();
        }

        fclose($handle);

        return $this->syncRules($user, $rows);
    }

    /**
     * @return array{imported_rules: int, updated_rules: int, skipped_rows: int}
     */
    public function importDefaultRules(User $user): array
    {
        $this->ensureDefaultRuleCategoriesExist();

        return $this->syncRules($user, [
            ['category_name' => 'Gehalt', 'pattern' => 'lohn', 'match_field' => 'both', 'priority' => '280', 'is_active' => '1', 'name' => 'Gehaltseingang'],
            ['category_name' => 'Gehalt', 'pattern' => 'gehalt', 'match_field' => 'both', 'priority' => '280', 'is_active' => '1', 'name' => 'Gehaltseingang'],
            ['category_name' => 'Gehalt', 'pattern' => 'gehaltsabrechnung', 'match_field' => 'description', 'priority' => '240', 'is_active' => '1', 'name' => 'Gehaltsabrechnung'],
            ['category_name' => 'Lebensmittel', 'pattern' => 'rewe', 'match_field' => 'both', 'priority' => '150', 'is_active' => '1', 'name' => 'Supermarkt'],
            ['category_name' => 'Lebensmittel', 'pattern' => 'edeka', 'match_field' => 'both', 'priority' => '150', 'is_active' => '1', 'name' => 'Supermarkt'],
            ['category_name' => 'Lebensmittel', 'pattern' => 'lidl', 'match_field' => 'both', 'priority' => '150', 'is_active' => '1', 'name' => 'Discounter'],
            ['category_name' => 'Lebensmittel', 'pattern' => 'aldi', 'match_field' => 'both', 'priority' => '150', 'is_active' => '1', 'name' => 'Discounter'],
            ['category_name' => 'Drogerie', 'pattern' => 'rossmann', 'match_field' => 'both', 'priority' => '140', 'is_active' => '1', 'name' => 'Drogerie'],
            ['category_name' => 'Drogerie', 'pattern' => 'dm drogerie', 'match_field' => 'both', 'priority' => '140', 'is_active' => '1', 'name' => 'Drogerie'],
            ['category_name' => 'Mobilität', 'pattern' => 'aral', 'match_field' => 'counterparty', 'priority' => '140', 'is_active' => '1', 'name' => 'Tanken'],
            ['category_name' => 'Mobilität', 'pattern' => 'esso', 'match_field' => 'counterparty', 'priority' => '140', 'is_active' => '1', 'name' => 'Tanken'],
            ['category_name' => 'Mobilität', 'pattern' => 'jet', 'match_field' => 'counterparty', 'priority' => '140', 'is_active' => '1', 'name' => 'Tanken'],
            ['category_name' => 'Mobilität', 'pattern' => 'total', 'match_field' => 'counterparty', 'priority' => '140', 'is_active' => '1', 'name' => 'Tanken'],
            ['category_name' => 'Wohnen', 'pattern' => 'enstroga', 'match_field' => 'both', 'priority' => '180', 'is_active' => '1', 'name' => 'Strom'],
            ['category_name' => 'Onlinekauf', 'pattern' => 'amazon', 'match_field' => 'counterparty', 'priority' => '130', 'is_active' => '1', 'name' => 'Versandhandel'],
            ['category_name' => 'Abos', 'pattern' => 'amazon prime', 'match_field' => 'both', 'priority' => '230', 'is_active' => '1', 'name' => 'Prime-Abo'],
            ['category_name' => 'Abos', 'pattern' => 'netflix', 'match_field' => 'both', 'priority' => '230', 'is_active' => '1', 'name' => 'Streaming-Abo'],
            ['category_name' => 'Transfer', 'pattern' => 'umbuchung', 'match_field' => 'both', 'priority' => '260', 'is_active' => '1', 'name' => 'Interner Transfer'],
            ['category_name' => 'Transfer', 'pattern' => 'kreditkartenabrechnung', 'match_field' => 'description', 'priority' => '320', 'is_active' => '1', 'name' => 'Kartenabrechnung'],
            ['category_name' => 'Transfer', 'pattern' => 'ausgleich kreditkarte', 'match_field' => 'both', 'priority' => '320', 'is_active' => '1', 'name' => 'Kartenabrechnung'],
            ['category_name' => 'Transfer', 'pattern' => 'visa abrechnung', 'match_field' => 'both', 'priority' => '280', 'is_active' => '1', 'name' => 'Kartenabrechnung'],
            ['category_name' => 'Transfer', 'pattern' => 'paypal europe', 'match_field' => 'counterparty', 'priority' => '300', 'is_active' => '1', 'name' => 'PayPal-Ausgleich'],
            ['category_name' => 'Transfer', 'pattern' => 'abbuchung vom paypal-konto', 'match_field' => 'both', 'priority' => '320', 'is_active' => '1', 'name' => 'PayPal-Ausgleich'],
            ['category_name' => 'Transfer', 'pattern' => 'gutschrift auf paypal-konto', 'match_field' => 'both', 'priority' => '320', 'is_active' => '1', 'name' => 'PayPal-Ausgleich'],
        ]);
    }

    public function resetRulesForUser(User $user): int
    {
        return CategoryRule::query()
            ->where('user_id', $user->id)
            ->delete();
    }

    /**
     * @return array{summary: array{matched_transactions: int, category_name: string|null, pattern: string, match_field: string}, transactions: array<int, array<string, mixed>>}
     */
    public function previewRuleForUser(
        User $user,
        ?int $categoryId,
        string $pattern,
        string $matchField = 'both',
    ): array {
        $normalizedPattern = trim($pattern);
        $normalizedField = in_array($matchField, ['description', 'counterparty', 'both'], true)
            ? $matchField
            : 'both';
        $category = $categoryId === null
            ? null
            : Category::query()
            ->where('id', $categoryId)
            ->where(fn($query) => $query->whereNull('user_id')->orWhere('user_id', $user->id))
            ->first();

        if ($normalizedPattern === '') {
            return [
                'summary' => [
                    'matched_transactions' => 0,
                    'category_name' => $category?->name,
                    'pattern' => $normalizedPattern,
                    'match_field' => $normalizedField,
                ],
                'transactions' => [],
            ];
        }

        $transactions = $this->getUserTransactions($user)
            ->filter(fn(Transaction $transaction): bool => $this->matchesPattern($transaction, $normalizedPattern, $normalizedField))
            ->values();

        return [
            'summary' => [
                'matched_transactions' => $transactions->count(),
                'category_name' => $category?->name,
                'pattern' => $normalizedPattern,
                'match_field' => $normalizedField,
            ],
            'transactions' => $transactions
                ->map(fn(Transaction $transaction): array => $this->serializePreviewTransaction($transaction))
                ->all(),
        ];
    }

    /**
     * @return array{matched_transactions: int, updated_transactions: int, skipped_manual_transactions: int}
     */
    public function applyRulesForUser(User $user): array
    {
        $rules = CategoryRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->with('category:id,name,category_type')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return [
                'matched_transactions' => 0,
                'updated_transactions' => 0,
                'skipped_manual_transactions' => 0,
            ];
        }

        $transactions = $this->getUserTransactions($user)
            ->sortBy(['booking_date', 'id'])
            ->values();

        $matchedTransactions = 0;
        $updatedTransactions = 0;
        $skippedManualTransactions = 0;

        foreach ($transactions as $transaction) {
            $matchingRule = $this->findMatchingRule($transaction, $rules);

            if ($matchingRule === null) {
                continue;
            }

            $existingSplit = $this->getPrimaryCategorySplit($transaction);
            $source = data_get($existingSplit?->metadata, 'source');

            if ($existingSplit !== null && $source === 'manual') {
                $skippedManualTransactions++;
                continue;
            }

            $matchedTransactions++;

            if ($this->applyRuleToTransaction($transaction, $matchingRule, $existingSplit)) {
                $updatedTransactions++;
            }
        }

        return [
            'matched_transactions' => $matchedTransactions,
            'updated_transactions' => $updatedTransactions,
            'skipped_manual_transactions' => $skippedManualTransactions,
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array{imported_rules: int, updated_rules: int, skipped_rows: int}
     */
    private function syncRules(User $user, array $rows): array
    {
        $categoriesByName = Category::query()
            ->where(fn($query) => $query->whereNull('user_id')->orWhere('user_id', $user->id))
            ->get()
            ->keyBy(static fn(Category $category): string => mb_strtolower(trim($category->name)));

        $importedRules = 0;
        $updatedRules = 0;
        $skippedRows = 0;

        foreach ($rows as $row) {
            $categoryName = trim((string) ($row['category_name'] ?? ''));
            $pattern = trim((string) ($row['pattern'] ?? ''));

            if ($categoryName === '' || $pattern === '') {
                $skippedRows++;
                continue;
            }

            $category = $categoriesByName->get(mb_strtolower($categoryName));

            if (! $category instanceof Category) {
                $skippedRows++;
                continue;
            }

            $matchField = in_array(($row['match_field'] ?? 'both'), ['description', 'counterparty', 'both'], true)
                ? (string) $row['match_field']
                : 'both';
            $priority = max(0, min(1000, (int) ($row['priority'] ?? 100)));
            $isActiveRaw = mb_strtolower((string) ($row['is_active'] ?? '1'));
            $isActive = in_array($isActiveRaw, ['1', 'true', 'yes', 'ja', 'aktiv'], true);

            $rule = CategoryRule::query()->firstOrNew([
                'user_id' => $user->id,
                'category_id' => $category->id,
                'pattern' => $pattern,
                'match_field' => $matchField,
                'match_type' => 'contains',
            ]);

            $wasExisting = $rule->exists;

            $rule->fill([
                'name' => trim((string) ($row['name'] ?? '')) ?: null,
                'priority' => $priority,
                'is_active' => $isActive,
            ]);

            $rule->save();

            if ($wasExisting) {
                $updatedRules++;
            } else {
                $importedRules++;
            }
        }

        return [
            'imported_rules' => $importedRules,
            'updated_rules' => $updatedRules,
            'skipped_rows' => $skippedRows,
        ];
    }

    /**
     * @param  Collection<int, CategoryRule>  $rules
     */
    private function findMatchingRule(Transaction $transaction, Collection $rules): ?CategoryRule
    {
        foreach ($rules as $rule) {
            if ($this->matchesRule($transaction, $rule)) {
                return $rule;
            }
        }

        return null;
    }

    private function matchesRule(Transaction $transaction, CategoryRule $rule): bool
    {
        return $this->matchesPattern($transaction, $rule->pattern, $rule->match_field);
    }

    private function matchesPattern(Transaction $transaction, string $pattern, string $matchField): bool
    {
        $normalizedPattern = trim(mb_strtolower($pattern));

        if ($normalizedPattern === '') {
            return false;
        }

        $haystacks = match ($matchField) {
            'description' => [$transaction->description],
            'counterparty' => [$transaction->counterparty_name],
            default => [$transaction->description, $transaction->counterparty_name],
        };

        foreach ($haystacks as $haystack) {
            if ($haystack !== null && str_contains(mb_strtolower($haystack), $normalizedPattern)) {
                return true;
            }
        }

        return false;
    }

    private function getPrimaryCategorySplit(Transaction $transaction): ?TransactionSplit
    {
        return $transaction->splits
            ->sortBy('sort_order')
            ->first(fn(TransactionSplit $split): bool => $split->category_id !== null);
    }

    /**
     * @return EloquentCollection<int, Transaction>
     */
    private function getUserTransactions(User $user): EloquentCollection
    {
        return Transaction::query()
            ->visibleInCashflow()
            ->whereHas('account', fn($query) => $query->where('user_id', $user->id))
            ->with([
                'account:id,name',
                'splits' => fn($query) => $query
                    ->where('split_type', 'category_assignment')
                    ->with(['category:id,name,color', 'categoryRule:id,name'])
                    ->orderBy('sort_order'),
            ])
            ->orderByDesc('booking_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePreviewTransaction(Transaction $transaction): array
    {
        $primarySplit = $this->getPrimaryCategorySplit($transaction);
        $bookingDate = $transaction->getAttribute('booking_date');
        $valueDate = $transaction->getAttribute('value_date');

        return [
            'id' => $transaction->id,
            'booking_date' => $bookingDate ? (string) $bookingDate : null,
            'value_date' => $valueDate ? (string) $valueDate : null,
            'counterparty_name' => $transaction->counterparty_name,
            'description' => $transaction->description,
            'amount' => number_format((float) $transaction->amount, 2, '.', ''),
            'currency' => $transaction->currency,
            'direction' => $transaction->direction,
            'source_system' => $transaction->source_system,
            'account_name' => $transaction->account?->name,
            'is_transfer' => $transaction->is_transfer,
            'is_hidden_from_cashflow' => $transaction->is_hidden_from_cashflow,
            'transfer_group_id' => $transaction->transfer_group_id,
            'transfer_kind' => data_get($transaction->metadata, 'transfer_kind'),
            'category_id' => $primarySplit?->category_id,
            'category_name' => $primarySplit?->category?->name,
            'category_color' => $primarySplit?->category?->color,
            'category_source' => data_get($primarySplit?->metadata, 'source', 'none'),
            'category_rule_id' => $primarySplit?->category_rule_id,
            'category_rule_name' => $primarySplit?->categoryRule?->name,
        ];
    }

    private function applyRuleToTransaction(
        Transaction $transaction,
        CategoryRule $rule,
        ?TransactionSplit $existingSplit,
    ): bool {
        $payload = [
            'category_id' => $rule->category_id,
            'category_rule_id' => $rule->id,
            'name' => $rule->category?->name,
            'amount' => $transaction->amount,
            'notes' => null,
            'metadata' => [
                'source' => 'rule',
                'match_field' => $rule->match_field,
                'pattern' => $rule->pattern,
            ],
        ];

        $splitChanged = false;

        if ($existingSplit === null) {
            $transaction->splits()->create(array_merge($payload, [
                'split_type' => 'category_assignment',
                'sort_order' => 0,
            ]));

            $splitChanged = true;
        } else {
            $existingSplit->fill($payload);

            if ($existingSplit->isDirty()) {
                $existingSplit->save();
                $splitChanged = true;
            }
        }

        $transferChanged = $this->transactionTransferService->syncTransferState(
            $transaction,
            $rule->category?->category_type,
            $rule->pattern,
        );

        return $splitChanged || $transferChanged;
    }

    private function ensureDefaultRuleCategoriesExist(): void
    {
        $defaults = [
            ['name' => 'Gehalt', 'category_type' => 'income', 'color' => '#16a34a', 'sort_order' => 1],
            ['name' => 'Lebensmittel', 'category_type' => 'expense', 'color' => '#059669', 'sort_order' => 4],
            ['name' => 'Drogerie', 'category_type' => 'expense', 'color' => '#ec4899', 'sort_order' => 5],
            ['name' => 'Mobilität', 'category_type' => 'expense', 'color' => '#0ea5e9', 'sort_order' => 6],
            ['name' => 'Wohnen', 'category_type' => 'expense', 'color' => '#f59e0b', 'sort_order' => 7],
            ['name' => 'Onlinekauf', 'category_type' => 'expense', 'color' => '#6366f1', 'sort_order' => 8],
            ['name' => 'Abos', 'category_type' => 'expense', 'color' => '#7c3aed', 'sort_order' => 9],
            ['name' => 'Transfer', 'category_type' => 'transfer', 'color' => '#64748b', 'sort_order' => 20],
        ];

        foreach ($defaults as $default) {
            Category::query()->updateOrCreate(
                [
                    'user_id' => null,
                    'slug' => Str::slug($default['name']),
                ],
                [
                    'name' => $default['name'],
                    'category_type' => $default['category_type'],
                    'color' => $default['color'],
                    'is_system' => true,
                    'sort_order' => $default['sort_order'],
                ],
            );
        }
    }
}
