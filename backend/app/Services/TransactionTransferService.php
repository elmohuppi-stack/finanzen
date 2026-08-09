<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionLink;
use Illuminate\Support\Str;

class TransactionTransferService
{
    public function __construct(private readonly CashWalletService $cashWalletService) {}

    public function syncTransferState(
        Transaction $transaction,
        ?string $categoryType,
        ?string $matchedPattern = null,
    ): bool {
        $isTransfer = $categoryType === 'transfer';
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];

        $transaction->is_transfer = $isTransfer;
        $transaction->is_hidden_from_cashflow = $isTransfer;

        if ($isTransfer) {
            $metadata['transfer_kind'] = $this->detectTransferKind($transaction, $matchedPattern);
        } else {
            unset($metadata['transfer_kind']);
        }

        $transaction->metadata = array_filter(
            $metadata,
            static fn(mixed $value): bool => $value !== null && $value !== '',
        );

        $transactionChanged = $transaction->isDirty();

        if ($transactionChanged) {
            $transaction->save();
        }

        $mirrorChanged = $this->cashWalletService->syncMirrorFor($transaction);

        $linkChanged = $isTransfer
            ? $this->syncTransferLinks($transaction)
            : $this->clearTransferLinks($transaction);

        return $transactionChanged || $mirrorChanged || $linkChanged;
    }

    public function detectTransferKind(Transaction $transaction, ?string $matchedPattern = null): string
    {
        $text = mb_strtolower(trim(implode(' ', array_filter([
            $matchedPattern,
            $transaction->description,
            $transaction->counterparty_name,
        ]))));

        return match (true) {
            str_contains($text, 'kreditkartenabrechnung'),
            str_contains($text, 'ausgleich kreditkarte'),
            str_contains($text, 'visa abrechnung') => 'credit_card_settlement',
            str_contains($text, 'paypal') => 'paypal_settlement',
            str_contains($text, 'bar'), str_contains($text, 'geldautomat') => 'cash_withdrawal',
            default => 'internal_transfer',
        };
    }

    private function syncTransferLinks(Transaction $transaction): bool
    {
        $transferKind = data_get($transaction->metadata, 'transfer_kind');

        if (! is_string($transferKind) || $transferKind === '') {
            return false;
        }

        $counterTransaction = $this->findCounterTransaction($transaction, $transferKind);

        if (! $counterTransaction instanceof Transaction) {
            return $this->refreshTransferGroupId($transaction);
        }

        $groupId = $transaction->transfer_group_id
            ?: $counterTransaction->transfer_group_id
            ?: (string) Str::uuid();

        $changed = $this->assignTransferGroup($transaction, $groupId);
        $changed = $this->assignTransferGroup($counterTransaction, $groupId) || $changed;

        [$fromId, $toId] = $transaction->id < $counterTransaction->id
            ? [$transaction->id, $counterTransaction->id]
            : [$counterTransaction->id, $transaction->id];

        $staleLinkCount = TransactionLink::query()
            ->where('link_type', $transferKind)
            ->where(function ($query) use ($transaction): void {
                $query
                    ->where('from_transaction_id', $transaction->id)
                    ->orWhere('to_transaction_id', $transaction->id);
            })
            ->where(function ($query) use ($fromId, $toId): void {
                $query
                    ->where('from_transaction_id', '!=', $fromId)
                    ->orWhere('to_transaction_id', '!=', $toId);
            })
            ->delete();

        if ($staleLinkCount > 0) {
            $changed = true;
        }

        $link = TransactionLink::query()->updateOrCreate(
            [
                'from_transaction_id' => $fromId,
                'to_transaction_id' => $toId,
                'link_type' => $transferKind,
            ],
            [
                'amount' => number_format(abs((float) $transaction->amount), 2, '.', ''),
                'confidence' => $this->calculateLinkConfidence($transaction, $counterTransaction),
                'metadata' => [
                    'transfer_kind' => $transferKind,
                    'date_distance_days' => $this->calculateBookingDateDistance($transaction, $counterTransaction),
                    'account_names' => array_values(array_filter([
                        $transaction->account?->name,
                        $counterTransaction->account?->name,
                    ])),
                ],
            ],
        );

        return $changed || $link->wasRecentlyCreated || $link->wasChanged();
    }

    private function clearTransferLinks(Transaction $transaction): bool
    {
        $linkedIds = TransactionLink::query()
            ->where('from_transaction_id', $transaction->id)
            ->orWhere('to_transaction_id', $transaction->id)
            ->get(['from_transaction_id', 'to_transaction_id'])
            ->flatMap(fn(TransactionLink $link): array => [$link->from_transaction_id, $link->to_transaction_id])
            ->unique()
            ->reject(fn(int $id): bool => $id === $transaction->id)
            ->values();

        $deletedCount = TransactionLink::query()
            ->where('from_transaction_id', $transaction->id)
            ->orWhere('to_transaction_id', $transaction->id)
            ->delete();

        $changed = $deletedCount > 0;
        $changed = $this->assignTransferGroup($transaction, null) || $changed;

        if ($linkedIds->isNotEmpty()) {
            Transaction::query()
                ->whereIn('id', $linkedIds->all())
                ->get()
                ->each(function (Transaction $linkedTransaction) use (&$changed): void {
                    $changed = $this->refreshTransferGroupId($linkedTransaction) || $changed;
                });
        }

        return $changed;
    }

    private function findCounterTransaction(Transaction $transaction, string $transferKind): ?Transaction
    {
        $account = $transaction->account()->first(['id', 'user_id']);

        if ($account === null) {
            return null;
        }

        $expectedAmount = (float) $transaction->amount * -1;
        $bookingDate = $transaction->getAttribute('booking_date');
        $dateRangeDays = match ($transferKind) {
            'paypal_settlement' => 3,
            'cash_withdrawal' => 2,
            default => 7,
        };

        return Transaction::query()
            ->whereKeyNot($transaction->id)
            ->whereHas('account', fn($query) => $query->where('user_id', $account->user_id))
            ->where('account_id', '!=', $transaction->account_id)
            ->where('is_transfer', true)
            ->where('currency', $transaction->currency)
            ->whereBetween('amount', [$expectedAmount - 0.01, $expectedAmount + 0.01])
            ->when($bookingDate !== null, function ($query) use ($bookingDate, $dateRangeDays): void {
                $query->whereBetween('booking_date', [
                    $bookingDate->copy()->subDays($dateRangeDays)->toDateString(),
                    $bookingDate->copy()->addDays($dateRangeDays)->toDateString(),
                ]);
            })
            ->with('account:id,name,user_id')
            ->get()
            ->filter(function (Transaction $candidate) use ($transferKind): bool {
                return $this->detectTransferKind($candidate) === $transferKind;
            })
            ->sortBy([
                fn(Transaction $candidate): int => $candidate->is_transfer ? 0 : 1,
                fn(Transaction $candidate): int => $this->calculateBookingDateDistance($transaction, $candidate),
                fn(Transaction $candidate): int => $candidate->id,
            ])
            ->first();
    }

    private function calculateLinkConfidence(Transaction $transaction, Transaction $counterTransaction): string
    {
        $distance = $this->calculateBookingDateDistance($transaction, $counterTransaction);

        $confidence = match (true) {
            $distance === 0 => 0.98,
            $distance <= 1 => 0.95,
            $distance <= 3 => 0.90,
            default => 0.82,
        };

        return number_format($confidence, 2, '.', '');
    }

    private function calculateBookingDateDistance(Transaction $transaction, Transaction $counterTransaction): int
    {
        $left = $transaction->getAttribute('booking_date');
        $right = $counterTransaction->getAttribute('booking_date');

        if ($left === null || $right === null) {
            return 99;
        }

        return abs($left->diffInDays($right));
    }

    private function refreshTransferGroupId(Transaction $transaction): bool
    {
        $hasLinks = TransactionLink::query()
            ->where('from_transaction_id', $transaction->id)
            ->orWhere('to_transaction_id', $transaction->id)
            ->exists();

        if ($hasLinks) {
            return false;
        }

        return $this->assignTransferGroup($transaction, null);
    }

    private function assignTransferGroup(Transaction $transaction, ?string $groupId): bool
    {
        if ($transaction->transfer_group_id === $groupId) {
            return false;
        }

        $transaction->transfer_group_id = $groupId;
        $transaction->save();

        return true;
    }
}
