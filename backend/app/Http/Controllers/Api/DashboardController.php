<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\FinanceImport;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'view' => ['nullable', 'in:month,all'],
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'account_id' => ['nullable', 'integer'],
            'query' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        $selectedView = $validated['view'] ?? 'month';
        $selectedAccountId = isset($validated['account_id']) ? (int) $validated['account_id'] : null;
        $searchQuery = trim((string) ($validated['query'] ?? ''));

        $accountScopedTransactionQuery = Transaction::query()
            ->whereHas('account', fn($query) => $query->where('user_id', $user->id));

        $cashflowTransactionQuery = Transaction::query()
            ->visibleInCashflow()
            ->whereHas('account', fn($query) => $query->where('user_id', $user->id));

        if ($selectedAccountId !== null) {
            $accountScopedTransactionQuery->where('account_id', $selectedAccountId);
            $cashflowTransactionQuery->where('account_id', $selectedAccountId);
        }

        $baseTransactionQuery = clone $accountScopedTransactionQuery;
        $cashflowBaseTransactionQuery = clone $cashflowTransactionQuery;

        if ($searchQuery !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchQuery) . '%';

            $applySearch = function ($query) use ($like): void {
                $query->where(function ($nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('counterparty_name', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('source_system', 'like', $like)
                        ->orWhereHas('account', fn($accountQuery) => $accountQuery->where('name', 'like', $like));
                });
            };

            $applySearch($baseTransactionQuery);
            $applySearch($cashflowBaseTransactionQuery);
        }

        $balanceTransactions = (clone $accountScopedTransactionQuery)
            ->orderBy('booking_date')
            ->orderBy('id')
            ->get(['id', 'account_id', 'booking_date', 'amount']);

        $availableMonths = $balanceTransactions
            ->pluck('booking_date')
            ->map(static fn($date): string => substr((string) $date, 0, 7))
            ->filter()
            ->unique()
            ->sortDesc()
            ->values();

        $selectedMonth = $validated['month'] ?? ($availableMonths->first() ?: now()->format('Y-m'));
        $listTransactionQuery = clone $baseTransactionQuery;
        $filteredTransactionQuery = clone $cashflowBaseTransactionQuery;

        if ($selectedView === 'month') {
            $month = CarbonImmutable::createFromFormat('Y-m', $selectedMonth);
            $range = [
                $month->startOfMonth()->toDateString(),
                $month->endOfMonth()->toDateString(),
            ];

            $listTransactionQuery->whereBetween('booking_date', $range);
            $filteredTransactionQuery->whereBetween('booking_date', $range);
        }

        $income = (float) (clone $filteredTransactionQuery)
            ->where('amount', '>', 0)
            ->sum('amount');

        $expenseSum = (float) (clone $filteredTransactionQuery)
            ->where('amount', '<', 0)
            ->sum('amount');

        $expenses = abs($expenseSum);

        $accountModels = Account::query()
            ->where('user_id', $user->id)
            ->withCount('transactions')
            ->withSum('transactions as booked_balance', 'amount')
            ->orderBy('name')
            ->get();

        $balanceDates = $accountModels
            ->map(fn(Account $account): ?string => data_get($account->metadata, 'balance_as_of'))
            ->filter()
            ->values();

        $availableYears = $balanceTransactions
            ->pluck('booking_date')
            ->map(static fn($date): ?int => $date ? (int) substr((string) $date, 0, 4) : null)
            ->merge($balanceDates->map(static fn(string $date): int => (int) substr($date, 0, 4)))
            ->filter()
            ->unique()
            ->sortDesc()
            ->values();

        $summaryBalanceAsOf = $balanceDates->max();
        $selectedYear = isset($validated['year'])
            ? (int) $validated['year']
            : ($availableYears->first() ?: (int) now()->format('Y'));

        $totalBalance = $accountModels->reduce(function (float $sum, Account $account): float {
            $hasStoredSnapshot = data_get($account->metadata, 'balance_as_of') !== null;
            $balance = $hasStoredSnapshot
                ? (float) ($account->current_balance ?? 0)
                : (float) ($account->booked_balance ?? 0);

            return $sum + $balance;
        }, 0.0);

        $accounts = $accountModels
            ->map(fn(Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'account_type' => $account->account_type,
                'institution' => $account->institution,
                'currency' => $account->currency,
                'transaction_count' => $account->transactions_count,
                'booked_balance' => number_format((float) ($account->booked_balance ?? 0), 2, '.', ''),
                'current_balance' => number_format((float) ($account->current_balance ?? $account->booked_balance ?? 0), 2, '.', ''),
                'balance_as_of' => data_get($account->metadata, 'balance_as_of'),
                'statement_period_from' => data_get($account->metadata, 'statement_period_from'),
                'statement_period_to' => data_get($account->metadata, 'statement_period_to'),
            ])
            ->values();

        $transactions = (clone $listTransactionQuery)
            ->with(['account:id,name', 'splits.category:id,name,color', 'splits.categoryRule:id,name'])
            ->orderByDesc('booking_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (Transaction $transaction): array {
                $bookingDate = $transaction->getAttribute('booking_date');
                $valueDate = $transaction->getAttribute('value_date');
                $primarySplit = $transaction->splits
                    ->sortBy('sort_order')
                    ->first(fn(TransactionSplit $split): bool => $split->category !== null);

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
                    'category_source' => data_get($primarySplit?->metadata, 'source'),
                    'category_rule_id' => $primarySplit?->category_rule_id,
                    'category_rule_name' => $primarySplit?->categoryRule?->name,
                ];
            })
            ->values();

        $imports = FinanceImport::query()
            ->where('user_id', $user->id)
            ->with('account:id,name,account_type')
            ->withMin('transactions', 'booking_date')
            ->withMax('transactions', 'booking_date')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn(FinanceImport $import): array => [
                'id' => $import->id,
                'source_type' => $import->source_type,
                'file_name' => $import->file_name,
                'status' => $import->status,
                'imported_rows' => $import->imported_rows,
                'skipped_rows' => $import->skipped_rows,
                'error_rows' => $import->error_rows,
                'imported_at' => $import->finished_at?->toIso8601String() ?? $import->started_at?->toIso8601String(),
                'period_from' => $import->transactions_min_booking_date
                    ? substr((string) $import->transactions_min_booking_date, 0, 10)
                    : null,
                'period_to' => $import->transactions_max_booking_date
                    ? substr((string) $import->transactions_max_booking_date, 0, 10)
                    : null,
                'account_name' => $import->account?->name,
                'account_type' => $import->account?->account_type,
            ])
            ->values();

        $categories = Category::query()
            ->where(fn($query) => $query->whereNull('user_id')->orWhere('user_id', $user->id))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn(Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'category_type' => $category->category_type,
                'color' => $category->color,
                'is_system' => $category->is_system,
            ])
            ->values();

        return response()->json([
            'summary' => [
                'account_count' => $accounts->count(),
                'transaction_count' => $transactions->count(),
                'income' => number_format($income, 2, '.', ''),
                'expenses' => number_format($expenses, 2, '.', ''),
                'net' => number_format($income - $expenses, 2, '.', ''),
                'total_balance' => number_format($totalBalance, 2, '.', ''),
                'balance_as_of' => $summaryBalanceAsOf,
                'balance_year' => $selectedYear,
            ],
            'filters' => [
                'selected_view' => $selectedView,
                'selected_month' => $selectedView === 'month' ? $selectedMonth : null,
                'selected_account_id' => $selectedAccountId,
                'search_query' => $searchQuery,
                'available_months' => $availableMonths,
                'selected_year' => $selectedYear,
                'available_years' => $availableYears,
            ],
            'accounts' => $accounts,
            'categories' => $categories,
            'transactions' => $transactions,
            'recent_transactions' => $transactions->take(10)->values(),
            'imports' => $imports,
            'monthly_balances' => $this->buildMonthlyBalances($accountModels, $balanceTransactions, $selectedYear),
        ]);
    }

    private function buildMonthlyBalances($accountModels, $balanceTransactions, int $year)
    {
        $transactionsByAccount = $balanceTransactions->groupBy('account_id');

        return collect(range(1, 12))
            ->map(function (int $month) use ($accountModels, $transactionsByAccount, $year): array {
                $monthStart = CarbonImmutable::create($year, $month, 1)->startOfMonth();
                $monthEnd = $monthStart->endOfMonth();
                $income = 0.0;
                $expenses = 0.0;
                $openingBalance = 0.0;
                $closingBalance = 0.0;
                $hasBalanceSnapshot = false;

                foreach ($accountModels as $account) {
                    $accountTransactions = $transactionsByAccount->get($account->id, collect());
                    $monthlyTransactions = $accountTransactions->filter(function (Transaction $transaction) use ($monthStart, $monthEnd): bool {
                        $bookingDate = $transaction->booking_date ? CarbonImmutable::parse((string) $transaction->booking_date) : null;

                        return $bookingDate !== null && $bookingDate->betweenIncluded($monthStart, $monthEnd);
                    });

                    $monthlyIncome = (float) $monthlyTransactions
                        ->filter(fn(Transaction $transaction): bool => (float) $transaction->amount > 0)
                        ->sum('amount');
                    $monthlyExpenseSum = (float) $monthlyTransactions
                        ->filter(fn(Transaction $transaction): bool => (float) $transaction->amount < 0)
                        ->sum('amount');

                    $income += $monthlyIncome;
                    $expenses += abs($monthlyExpenseSum);

                    $balanceAsOf = data_get($account->metadata, 'balance_as_of');

                    if ($balanceAsOf === null) {
                        continue;
                    }

                    $snapshotDate = CarbonImmutable::parse($balanceAsOf)->endOfDay();

                    if ($monthStart->gt($snapshotDate)) {
                        continue;
                    }

                    $afterMonthSum = (float) $accountTransactions
                        ->filter(function (Transaction $transaction) use ($monthEnd, $snapshotDate): bool {
                            $bookingDate = $transaction->booking_date ? CarbonImmutable::parse((string) $transaction->booking_date) : null;

                            return $bookingDate !== null && $bookingDate->gt($monthEnd) && $bookingDate->lte($snapshotDate);
                        })
                        ->sum('amount');

                    $monthNet = (float) $monthlyTransactions->sum('amount');
                    $accountClosingBalance = (float) $account->current_balance - $afterMonthSum;
                    $accountOpeningBalance = $accountClosingBalance - $monthNet;

                    $closingBalance += $accountClosingBalance;
                    $openingBalance += $accountOpeningBalance;
                    $hasBalanceSnapshot = true;
                }

                return [
                    'month' => $monthStart->format('Y-m'),
                    'label' => $monthStart->locale('de')->translatedFormat('F Y'),
                    'income' => number_format($income, 2, '.', ''),
                    'expenses' => number_format($expenses, 2, '.', ''),
                    'net' => number_format($income - $expenses, 2, '.', ''),
                    'opening_balance' => $hasBalanceSnapshot ? number_format($openingBalance, 2, '.', '') : null,
                    'closing_balance' => $hasBalanceSnapshot ? number_format($closingBalance, 2, '.', '') : null,
                ];
            })
            ->values();
    }
}
