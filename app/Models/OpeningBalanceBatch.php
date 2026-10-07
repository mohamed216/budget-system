<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpeningBalanceBatch extends Model
{
    use OwnedByUser;

    protected $fillable = ['opening_date', 'currency'];

    protected $attributes = ['status' => 'draft'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'opening_date' => 'date',
        'posted_at' => 'immutable_datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningBalanceLine::class, 'batch_id')->orderBy('id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }
}
