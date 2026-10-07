<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    use OwnedByUser;

    protected $fillable = ['start_date', 'end_date'];

    protected $attributes = ['status' => 'open'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'first_closed_at' => 'immutable_datetime',
    ];

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function wasEverClosed(): bool
    {
        return $this->first_closed_at !== null;
    }
}
