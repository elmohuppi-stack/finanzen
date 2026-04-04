<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
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
            ->with(['account:id,name', 'splits.category:id,name,color', 'splits.categoryRule:id,name'])
            ->firstOrFail();

        $categoryId = $validated['category_id'] ?? null;

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
            ],
        ]);
    }
}
