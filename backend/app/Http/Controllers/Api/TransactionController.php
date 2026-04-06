<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Services\DashboardCacheService;
use App\Services\TransactionTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function updateCategory(
        Request $request,
        int $transactionId,
        TransactionTransferService $transactionTransferService,
        DashboardCacheService $dashboardCacheService,
    ): JsonResponse {
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
            ->with(['account:id,name', 'splits.category:id,name,color', 'splits.categoryRule:id,name'])
            ->firstOrFail();

        $categoryId = $validated['category_id'] ?? null;
        $category = null;

        if ($categoryId === null) {
            $transaction->splits()
                ->where('split_type', 'category_assignment')
                ->delete();
        } else {
            $category = Category::query()
                ->where('id', $categoryId)
                ->where(fn($query) => $query->whereNull('user_id')->orWhere('user_id', $user->id))
                ->firstOrFail();

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
        }

        $transactionTransferService->syncTransferState($transaction, $category?->category_type);
        $dashboardCacheService->invalidateUser($user->id);

        $transaction->load(['account:id,name', 'splits.category:id,name,color', 'splits.categoryRule:id,name']);

        $primarySplit = $transaction->splits
            ->sortBy('sort_order')
            ->first(fn(TransactionSplit $split): bool => $split->category !== null);

        return response()->json([
            'transaction' => [
                'id' => $transaction->id,
                'category_id' => $primarySplit?->category_id,
                'category_name' => $primarySplit?->category?->name,
                'category_color' => $primarySplit?->category?->color,
                'category_source' => data_get($primarySplit?->metadata, 'source'),
                'category_rule_id' => $primarySplit?->category_rule_id,
                'category_rule_name' => $primarySplit?->categoryRule?->name,
                'account_name' => $transaction->account?->name,
                'is_transfer' => $transaction->is_transfer,
                'is_hidden_from_cashflow' => $transaction->is_hidden_from_cashflow,
                'transfer_group_id' => $transaction->transfer_group_id,
                'transfer_kind' => data_get($transaction->metadata, 'transfer_kind'),
            ],
        ]);
    }
}
