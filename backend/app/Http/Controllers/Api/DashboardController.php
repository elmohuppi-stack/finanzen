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
            'account_id' => ['nullable', 'integer'],
            'query' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        $selectedView = $validated['view'] ?? 'month';
        $selectedAccountId = isset($validated['account_id']) ? (int) $validated['account_id'] : null;
        $searchQuery = trim((string) ($validated['query'] ?? ''));

        $baseTransactionQuery = Transaction::query()
            ->visibleInCashflow()
            ->whereHas('account', fn($query) => $query->where('user_id', $user->id));

        if ($selectedAccountId !== null) {
            $baseTransactionQuery->where('account_id', $selectedAccountId);
        }

        if ($searchQuery !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchQuery) . '%';

            $baseTransactionQuery->where(function ($query) use ($like): void {
                $query
                    ->where('counterparty_name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('source_system', 'like', $like)
                    ->orWhereHas('account', fn($accountQuery) => $accountQuery->where('name', 'like', $like));
            });
        }

        $availableMonths = (clone $baseTransactionQuery)
            ->orderByDesc('booking_date')
            ->pluck('booking_date')
            ->map(static fn($date): string => substr((string) $date, 0, 7))
            ->filter()
            ->unique()
            ->values();

        $selectedMonth = $validated['month'] ?? ($availableMonths->first() ?: now()->format('Y-m'));
        $filteredTransactionQuery = clone $baseTransactionQuery;

        if ($selectedView === 'month') {
            $month = CarbonImmutable::createFromFormat('Y-m', $selectedMonth);
            $filteredTransactionQuery->whereBetween('booking_date', [
                $month->startOfMonth()->toDateString(),
                $month->endOfMonth()->toDateString(),
            ]);
        }

        $income = (float) (clone $filteredTransactionQuery)
            ->where('amount', '>', 0)
            ->sum('amount');

        $expenseSum = (float) (clone $filteredTransactionQuery)
            ->where('amount', '<', 0)
            ->sum('amount');

        $expenses = abs($expenseSum);

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->withCount('transactions')
            ->withSum('transactions as booked_balance', 'amount')
            ->orderBy('name')
            ->get()
            ->map(fn(Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'account_type' => $account->account_type,
                'institution' => $account->institution,
                'currency' => $account->currency,
                'transaction_count' => $account->transactions_count,
                'booked_balance' => number_format((float) ($account->booked_balance ?? 0), 2, '.', ''),
            ])
            ->values();

        $transactions = (clone $filteredTransactionQuery)
            ->with(['account:id,name', 'splits.category:id,name,color'])
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
                    'category_id' => $primarySplit?->category_id,
                    'category_name' => $primarySplit?->category?->name,
                    'category_color' => $primarySplit?->category?->color,
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
            ],
            'filters' => [
                'selected_view' => $selectedView,
                'selected_month' => $selectedView === 'month' ? $selectedMonth : null,
                'selected_account_id' => $selectedAccountId,
                'search_query' => $searchQuery,
                'available_months' => $availableMonths,
            ],
            'accounts' => $accounts,
            'categories' => $categories,
            'transactions' => $transactions,
            'recent_transactions' => $transactions->take(10)->values(),
            'imports' => $imports,
        ]);
    }
}
