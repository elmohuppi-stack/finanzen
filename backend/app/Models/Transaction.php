<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    protected $fillable = [
        'account_id',
        'finance_import_id',
        'booking_date',
        'value_date',
        'posted_at',
        'amount',
        'currency',
        'direction',
        'counterparty_name',
        'description',
        'external_id',
        'transaction_hash',
        'source_system',
        'source_reference',
        'transfer_group_id',
        'is_transfer',
        'is_hidden_from_cashflow',
        'metadata',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'value_date' => 'date',
            'posted_at' => 'datetime',
            'amount' => 'decimal:2',
            'is_transfer' => 'boolean',
            'is_hidden_from_cashflow' => 'boolean',
            'metadata' => 'array',
            'raw_payload' => 'array',
        ];
    }

    public function scopeVisibleInCashflow(Builder $query): Builder
    {
        return $query->where('is_hidden_from_cashflow', false);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function financeImport(): BelongsTo
    {
        return $this->belongsTo(FinanceImport::class);
    }

    public function splits(): HasMany
    {
        return $this->hasMany(TransactionSplit::class);
    }

    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(TransactionLink::class, 'from_transaction_id');
    }

    public function incomingLinks(): HasMany
    {
        return $this->hasMany(TransactionLink::class, 'to_transaction_id');
    }
}
