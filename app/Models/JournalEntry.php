<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JournalEntry extends Model
{
    use OwnedByUser;

    protected $fillable = ['entry_date', 'currency', 'reference', 'description'];

    protected $attributes = ['status' => 'draft', 'version' => 1];

    // Preserve the precision of posted_at DATETIME(6) when saving date casts.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'immutable_datetime',
        'version' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_number');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
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
