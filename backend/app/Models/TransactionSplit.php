<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionSplit extends Model
{
    protected $fillable = [
        'transaction_id',
        'category_id',
        'category_rule_id',
        'name',
        'amount',
        'split_type',
        'notes',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function categoryRule(): BelongsTo
    {
        return $this->belongsTo(CategoryRule::class);
    }
}
