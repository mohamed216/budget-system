<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningBalanceLine extends Model
{
    use OwnedByUser;

    protected $fillable = ['chart_account_id', 'debit', 'credit'];

    protected $casts = ['debit' => 'decimal:2', 'credit' => 'decimal:2'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceBatch::class, 'batch_id');
    }

    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class);
    }
}
