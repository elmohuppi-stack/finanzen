<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

class CashWalletService
{
    public const ACCOUNT_TYPE = 'cash_wallet';

    public const MIRROR_SOURCE_SYSTEM = 'cash_mirror';

    public const MANUAL_SOURCE_SYSTEM = 'manual';

    public const MIRROR_START_DATE_KEY = 'mirror_start_date';

    private const DEFAULT_NAME = 'Bargeld';

    /**
     * Zwischenspeicher für das Startdatum je Nutzer, nur während eines Massenabgleichs aktiv.
     *
     * @var array<int, string|null>
     */
    private array $mirrorStartDates = [];

    private bool $cacheMirrorStartDates = false;

    public function resolveForUser(User $user): Account
    {
        $account = $this->findForUser($user);

        if ($account instanceof Account) {
            return $account;
        }

        return Account::query()->create([
            'user_id' => $user->id,
            'name' => self::DEFAULT_NAME,
            'account_type' => self::ACCOUNT_TYPE,
            'institution' => null,
            'currency' => 'EUR',
            'initial_balance' => '0.00',
            'current_balance' => '0.00',
            'metadata' => ['managed_by' => 'cash_wallet'],
            'is_active' => true,
        ]);
    }

    public function findForUser(User $user): ?Account
    {
        return Account::query()
            ->where('user_id', $user->id)
            ->where('account_type', self::ACCOUNT_TYPE)
            ->orderBy('id')
            ->first();
    }

    /**
     * Ab diesem Tag werden Abhebungen in die Bargeldkasse gespiegelt. Ältere bleiben unberührt.
     */
    public function setMirrorStartDate(User $user, ?string $startDate): Account
    {
        $wallet = $this->resolveForUser($user);
        $metadata = is_array($wallet->metadata) ? $wallet->metadata : [];

        if ($startDate === null) {
            unset($metadata[self::MIRROR_START_DATE_KEY]);
        } else {
            $metadata[self::MIRROR_START_DATE_KEY] = $startDate;
        }

        $wallet->forceFill(['metadata' => $metadata])->save();
        unset($this->mirrorStartDates[(int) $user->id]);

        return $wallet;
    }

    public function getMirrorStartDate(User $user): ?string
    {
        return $this->resolveMirrorStartDate((int) $user->id);
    }

    /**
     * Erzeugt, aktualisiert oder entfernt die Bargeld-Gegenbuchung zu einer Bankbuchung.
     */
    public function syncMirrorFor(Transaction $transaction): bool
    {
        $mirrorAmount = $this->resolveMirrorAmount($transaction);

        if ($mirrorAmount === null) {
            return $this->removeMirrorFor($transaction);
        }

        $user = $this->resolveAccount($transaction)?->user;

        if ($user === null) {
            return false;
        }

        $wallet = $this->resolveForUser($user);
        $existingMirror = $this->findMirrorFor($transaction);

        $payload = [
            'account_id' => $wallet->id,
            'finance_import_id' => null,
            'booking_date' => $transaction->getAttribute('booking_date')?->toDateString(),
            'value_date' => $transaction->getAttribute('value_date')?->toDateString(),
            'posted_at' => $transaction->posted_at,
            'amount' => $mirrorAmount,
            'currency' => $transaction->currency,
            'direction' => (float) $mirrorAmount < 0 ? 'debit' : 'credit',
            'counterparty_name' => $transaction->account?->name ?? 'Bankkonto',
            'description' => $this->buildMirrorDescription($transaction),
            'external_id' => null,
            'transaction_hash' => hash('sha256', self::MIRROR_SOURCE_SYSTEM . '|' . $transaction->id),
            'source_system' => self::MIRROR_SOURCE_SYSTEM,
            'source_reference' => (string) $transaction->id,
            'is_transfer' => true,
            'is_hidden_from_cashflow' => true,
            'metadata' => [
                'transfer_kind' => 'cash_withdrawal',
                'mirrored_from_transaction_id' => $transaction->id,
                'mirrored_from_account_id' => $transaction->account_id,
            ],
        ];

        if ($existingMirror instanceof Transaction) {
            $existingMirror->fill($payload);

            if (! $existingMirror->isDirty()) {
                return false;
            }

            $existingMirror->save();
            $this->refreshBalance($wallet);

            return true;
        }

        Transaction::query()->create($payload);
        $this->refreshBalance($wallet);

        return true;
    }

    public function removeMirrorFor(Transaction $transaction): bool
    {
        if ($transaction->source_system === self::MIRROR_SOURCE_SYSTEM || $this->isCashWallet($this->resolveAccount($transaction))) {
            return false;
        }

        $mirror = $this->findMirrorFor($transaction);

        if (! $mirror instanceof Transaction) {
            return false;
        }

        $wallet = $mirror->account;
        $mirror->delete();

        if ($wallet instanceof Account) {
            $this->refreshBalance($wallet);
        }

        return true;
    }

    /**
     * Gleicht alle Bargeld-Gegenbuchungen eines Nutzers ab, z. B. nach einem Import.
     *
     * @return array{created_or_updated: int, removed: int}
     */
    public function syncMirrorsForUser(User $user): array
    {
        $wallet = $this->findForUser($user);

        $transactions = Transaction::query()
            ->whereHas('account', fn($query) => $query->where('user_id', $user->id))
            ->when($wallet !== null, fn($query) => $query->where('account_id', '!=', $wallet->id))
            ->where('source_system', '!=', self::MIRROR_SOURCE_SYSTEM)
            ->with('account:id,name,user_id,account_type')
            ->get();

        $createdOrUpdated = 0;
        $removed = 0;
        $this->cacheMirrorStartDates = true;

        try {
            foreach ($transactions as $transaction) {
                $hasMirrorAmount = $this->resolveMirrorAmount($transaction) !== null;

                if (! $hasMirrorAmount) {
                    if ($this->removeMirrorFor($transaction)) {
                        $removed++;
                    }

                    continue;
                }

                if ($this->syncMirrorFor($transaction)) {
                    $createdOrUpdated++;
                }
            }
        } finally {
            $this->cacheMirrorStartDates = false;
            $this->mirrorStartDates = [];
        }

        return [
            'created_or_updated' => $createdOrUpdated,
            'removed' => $removed,
        ];
    }

    public function refreshBalance(Account $account): void
    {
        $balance = (string) ($account->transactions()->sum('amount') ?: '0.00');
        $latestBookingDate = $account->transactions()->max('booking_date');
        $metadata = is_array($account->metadata) ? $account->metadata : [];

        $metadata['managed_by'] = 'cash_wallet';

        if ($latestBookingDate !== null) {
            $metadata['balance_as_of'] = substr((string) $latestBookingDate, 0, 10);
        } else {
            unset($metadata['balance_as_of']);
        }

        $account->forceFill([
            'current_balance' => number_format((float) $balance, 2, '.', ''),
            'metadata' => $metadata,
        ])->save();
    }

    public function buildManualTransactionHash(): string
    {
        return hash('sha256', self::MANUAL_SOURCE_SYSTEM . '|' . Str::uuid()->toString());
    }

    public function isCashWallet(?Account $account): bool
    {
        return $account?->account_type === self::ACCOUNT_TYPE;
    }

    /**
     * Betrag, der aus Sicht der Bargeldkasse gebucht werden muss, oder null wenn keine Gegenbuchung nötig ist.
     */
    private function resolveMirrorAmount(Transaction $transaction): ?string
    {
        if ($transaction->source_system === self::MIRROR_SOURCE_SYSTEM) {
            return null;
        }

        $account = $this->resolveAccount($transaction);

        if ($account === null || $this->isCashWallet($account)) {
            return null;
        }

        if (! $this->isWithinMirrorWindow($transaction, (int) $account->getAttribute('user_id'))) {
            return null;
        }

        $isCashWithdrawal = $transaction->is_transfer
            && data_get($transaction->metadata, 'transfer_kind') === 'cash_withdrawal';

        if ($isCashWithdrawal) {
            return number_format((float) $transaction->amount * -1, 2, '.', '');
        }

        $cashComponent = data_get($transaction->metadata, 'cash_withdrawal_amount');

        if (is_numeric($cashComponent) && (float) $cashComponent > 0) {
            return number_format((float) $cashComponent, 2, '.', '');
        }

        return null;
    }

    private function isWithinMirrorWindow(Transaction $transaction, int $userId): bool
    {
        $startDate = $this->resolveMirrorStartDate($userId);

        if ($startDate === null) {
            return true;
        }

        $bookingDate = $transaction->getAttribute('booking_date')?->toDateString();

        return $bookingDate === null || $bookingDate >= $startDate;
    }

    private function resolveMirrorStartDate(int $userId): ?string
    {
        if ($this->cacheMirrorStartDates && array_key_exists($userId, $this->mirrorStartDates)) {
            return $this->mirrorStartDates[$userId];
        }

        $wallet = Account::query()
            ->where('user_id', $userId)
            ->where('account_type', self::ACCOUNT_TYPE)
            ->orderBy('id')
            ->first(['id', 'metadata']);

        $startDate = data_get($wallet?->metadata, self::MIRROR_START_DATE_KEY);

        $this->mirrorStartDates[$userId] = is_string($startDate) && $startDate !== ''
            ? substr($startDate, 0, 10)
            : null;

        return $this->mirrorStartDates[$userId];
    }

    /**
     * Liefert das Konto inklusive der Felder, die geladene Relationen je nach Aufrufer nicht enthalten.
     */
    private function resolveAccount(Transaction $transaction): ?Account
    {
        $account = $transaction->relationLoaded('account') ? $transaction->account : null;

        if ($account instanceof Account && $account->getAttribute('user_id') !== null && $account->getAttribute('account_type') !== null) {
            return $account;
        }

        $account = $transaction->account()->first();

        if ($account instanceof Account) {
            $transaction->setRelation('account', $account);
        }

        return $account;
    }

    private function findMirrorFor(Transaction $transaction): ?Transaction
    {
        return Transaction::query()
            ->where('source_system', self::MIRROR_SOURCE_SYSTEM)
            ->where('source_reference', (string) $transaction->id)
            ->first();
    }

    private function buildMirrorDescription(Transaction $transaction): string
    {
        $accountName = $transaction->account?->name;
        $isPartial = ! $transaction->is_transfer;

        $label = $isPartial
            ? 'Bargeldauszahlung beim Einkauf'
            : 'Bargeldabhebung';

        return $accountName !== null
            ? sprintf('%s · %s', $label, $accountName)
            : $label;
    }
}
