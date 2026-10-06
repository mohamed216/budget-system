<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use OwnedByUser;

    protected $fillable = ['name', 'type', 'balance', 'currency'];

    protected $casts = ['balance' => 'decimal:2'];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function getTotalIncomeAttribute()
    {
        return $this->transactions()->where('user_id', $this->user_id)->where('type', 'income')->sum('amount');
    }

    public function getTotalExpenseAttribute()
    {
        return $this->transactions()->where('user_id', $this->user_id)->where('type', 'expense')->sum('amount');
    }
}
