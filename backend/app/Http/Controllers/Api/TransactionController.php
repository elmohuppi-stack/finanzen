<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\CashWalletService;
use App\Services\CategoryRuleService;
use App\Services\DashboardCacheService;
use App\Services\TransactionTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TransactionController extends Controller
{
    public function __construct(
        private readonly CashWalletService $cashWalletService,
        private readonly TransactionTransferService $transactionTransferService,
        private readonly CategoryRuleService $categoryRuleService,
        private readonly DashboardCacheService $dashboardCacheService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $this->validatePayload($request, $user, isUpdate: false);

        $transaction = DB::transaction(function () use ($user, $validated): Transaction {
            $account = $this->resolveAccount($user, $validated['account_id'] ?? null);
            $amount = $this->resolveSignedAmount($validated);

            $transaction = Transaction::query()->create([
                'account_id' => $account->id,
                'finance_import_id' => null,
                'booking_date' => $validated['booking_date'],
                'value_date' => $validated['value_date'] ?? $validated['booking_date'],
                'posted_at' => null,
                'amount' => $amount,
                'currency' => $account->currency ?: 'EUR',
                'direction' => (float) $amount < 0 ? 'debit' : 'credit',
                'counterparty_name' => $validated['counterparty_name'],
                'description' => $validated['description'] ?? null,
                'external_id' => null,
                'transaction_hash' => $this->cashWalletService->buildManualTransactionHash(),
                'source_system' => CashWalletService::MANUAL_SOURCE_SYSTEM,
                'source_reference' => null,
                'is_transfer' => false,
                'is_hidden_from_cashflow' => false,
                'metadata' => ['entry_mode' => 'manual'],
                'raw_payload' => null,
            ]);

            $transaction->setRelation('account', $account);
            $this->applyCategory($user, $transaction, $validated, hasCategoryInput: false);

            return $transaction;
        });

        $this->afterWrite($user, $transaction);

        return response()->json([
            'message' => 'Buchung gespeichert.',
            'transaction' => $this->serializeTransaction($transaction),
        ], 201);
    }

    public function update(Request $request, int $transactionId): JsonResponse
    {
        $user = $request->user();
        $transaction = $this->findManualTransaction($user, $transactionId);
        $validated = $this->validatePayload($request, $user, isUpdate: true);

        $previousAccount = $transaction->account;

        DB::transaction(function () use ($user, $transaction, $validated): void {
            $account = $this->resolveAccount($user, $validated['account_id'] ?? null, $transaction->account);
            $amount = $this->resolveSignedAmount($validated);

            $transaction->fill([
                'account_id' => $account->id,
                'booking_date' => $validated['booking_date'],
                'value_date' => $validated['value_date'] ?? $validated['booking_date'],
                'amount' => $amount,
                'currency' => $account->currency ?: 'EUR',
                'direction' => (float) $amount < 0 ? 'debit' : 'credit',
                'counterparty_name' => $validated['counterparty_name'],
                'description' => $validated['description'] ?? null,
            ])->save();

            $transaction->setRelation('account', $account);
            $this->applyCategory($user, $transaction, $validated, hasCategoryInput: array_key_exists('category_id', $validated));
        });

        $this->afterWrite($user, $transaction, $previousAccount);

        return response()->json([
            'message' => 'Buchung aktualisiert.',
            'transaction' => $this->serializeTransaction($transaction),
        ]);
    }

    public function destroy(Request $request, int $transactionId): JsonResponse
    {
        $user = $request->user();
        $transaction = $this->findManualTransaction($user, $transactionId);
        $account = $transaction->account;

        $transaction->delete();

        if ($account instanceof Account && $this->cashWalletService->isCashWallet($account)) {
            $this->cashWalletService->refreshBalance($account);
        }

        $this->dashboardCacheService->invalidateUser($user->id);

        return response()->json([
            'deleted' => true,
        ]);
    }

    public function updateCategory(Request $request, int $transactionId): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id'),
            ],
        ]);

        $transaction = Transaction::query()
            ->whereKey($transactionId)
            ->whereHas('account', fn($query) => $query->where('user_id', $user->id))
            ->with(['account:id,name,user_id,account_type', 'splits.category:id,name,color', 'splits.categoryRule:id,name'])
            ->firstOrFail();

        $categoryId = $validated['category_id'] ?? null;
        $category = null;

        if ($categoryId === null) {
            $transaction->splits()
                ->where('split_type', 'category_assignment')
                ->delete();
        } else {
            $category = $this->resolveCategory($user, $categoryId);
            $this->writeCategorySplit($transaction, $category);
        }

        $this->transactionTransferService->syncTransferState($transaction, $category?->category_type);
        $this->dashboardCacheService->invalidateUser($user->id);

        return response()->json([
            'transaction' => $this->serializeTransaction($transaction),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, User $user, bool $isUpdate): array
    {
        return $request->validate([
            'booking_date' => ['required', 'date'],
            'value_date' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'entry_type' => ['nullable', 'in:expense,income'],
            'counterparty_name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id'),
            ],
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('user_id', $user->id),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveSignedAmount(array $validated): string
    {
        $amount = abs((float) $validated['amount']);
        $isIncome = ($validated['entry_type'] ?? 'expense') === 'income';

        return number_format($isIncome ? $amount : $amount * -1, 2, '.', '');
    }

    private function resolveAccount(User $user, ?int $accountId, ?Account $fallback = null): Account
    {
        if ($accountId !== null) {
            return Account::query()
                ->where('user_id', $user->id)
                ->whereKey($accountId)
                ->firstOrFail();
        }

        return $fallback ?? $this->cashWalletService->resolveForUser($user);
    }

    private function resolveCategory(User $user, int $categoryId): Category
    {
        return Category::query()
            ->where('id', $categoryId)
            ->where(fn($query) => $query->whereNull('user_id')->orWhere('user_id', $user->id))
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyCategory(User $user, Transaction $transaction, array $validated, bool $hasCategoryInput): void
    {
        $categoryId = $validated['category_id'] ?? null;

        if ($categoryId !== null) {
            $category = $this->resolveCategory($user, (int) $categoryId);
            $this->writeCategorySplit($transaction, $category);
            $this->transactionTransferService->syncTransferState($transaction, $category->category_type);

            return;
        }

        if ($hasCategoryInput) {
            $transaction->splits()
                ->where('split_type', 'category_assignment')
                ->delete();
            $transaction->unsetRelation('splits');
            $this->transactionTransferService->syncTransferState($transaction, null);

            return;
        }

        $existingSplit = $transaction->splits()
            ->where('split_type', 'category_assignment')
            ->first();

        if ($existingSplit instanceof TransactionSplit) {
            $existingSplit->forceFill(['amount' => $transaction->amount])->save();

            return;
        }

        $this->categoryRuleService->applyRulesToTransaction($user, $transaction);
    }

    private function writeCategorySplit(Transaction $transaction, Category $category): void
    {
        $transaction->splits()->updateOrCreate(
            [
                'split_type' => 'category_assignment',
                'sort_order' => 0,
            ],
            [
                'category_id' => $category->id,
                'category_rule_id' => null,
                'name' => $category->name,
                'amount' => $transaction->amount,
                'notes' => null,
                'metadata' => ['source' => 'manual'],
            ],
        );

        $transaction->unsetRelation('splits');
    }

    private function findManualTransaction(User $user, int $transactionId): Transaction
    {
        $transaction = Transaction::query()
            ->whereKey($transactionId)
            ->whereHas('account', fn($query) => $query->where('user_id', $user->id))
            ->with('account:id,name,user_id,account_type,currency')
            ->firstOrFail();

        if ($transaction->source_system !== CashWalletService::MANUAL_SOURCE_SYSTEM) {
            throw new HttpException(403, 'Nur manuell erfasste Buchungen können bearbeitet oder gelöscht werden.');
        }

        return $transaction;
    }

    private function afterWrite(User $user, Transaction $transaction, ?Account $previousAccount = null): void
    {
        $accounts = collect([$transaction->account, $previousAccount])
            ->filter(fn(?Account $account): bool => $account instanceof Account)
            ->unique('id');

        foreach ($accounts as $account) {
            if ($this->cashWalletService->isCashWallet($account)) {
                $this->cashWalletService->refreshBalance($account);
            }
        }

        $this->dashboardCacheService->invalidateUser($user->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTransaction(Transaction $transaction): array
    {
        $transaction->load(['account:id,name,account_type', 'splits.category:id,name,color', 'splits.categoryRule:id,name']);

        $primarySplit = $transaction->splits
            ->sortBy('sort_order')
            ->first(fn(TransactionSplit $split): bool => $split->category !== null);

        return [
            'id' => $transaction->id,
            'booking_date' => $transaction->getAttribute('booking_date')?->toDateString(),
            'value_date' => $transaction->getAttribute('value_date')?->toDateString(),
            'amount' => number_format((float) $transaction->amount, 2, '.', ''),
            'currency' => $transaction->currency,
            'direction' => $transaction->direction,
            'counterparty_name' => $transaction->counterparty_name,
            'description' => $transaction->description,
            'source_system' => $transaction->source_system,
            'category_id' => $primarySplit?->category_id,
            'category_name' => $primarySplit?->category?->name,
            'category_color' => $primarySplit?->category?->color,
            'category_source' => data_get($primarySplit?->metadata, 'source'),
            'category_rule_id' => $primarySplit?->category_rule_id,
            'category_rule_name' => $primarySplit?->categoryRule?->name,
            'account_id' => $transaction->account_id,
            'account_name' => $transaction->account?->name,
            'account_type' => $transaction->account?->account_type,
            'is_transfer' => $transaction->is_transfer,
            'is_hidden_from_cashflow' => $transaction->is_hidden_from_cashflow,
            'transfer_group_id' => $transaction->transfer_group_id,
            'transfer_kind' => data_get($transaction->metadata, 'transfer_kind'),
        ];
    }
}
