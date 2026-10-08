<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalYearClose extends Model
{
    use OwnedByUser;

    protected $fillable = ['start_date', 'end_date', 'currency'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'immutable_datetime',
    ];

    public function retainedEarningsAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'retained_earnings_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
