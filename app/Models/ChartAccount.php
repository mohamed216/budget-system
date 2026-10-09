<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartAccount extends Model
{
    use OwnedByUser;

    protected $table = 'chart_of_accounts';

    protected $fillable = ['parent_id', 'code', 'name', 'type', 'is_active', 'cash_role'];

    protected $casts = ['is_active' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'chart_account_id');
    }

    public function openingBalanceLines(): HasMany
    {
        return $this->hasMany(OpeningBalanceLine::class, 'chart_account_id');
    }

    public function fiscalYearClosesAsRetainedEarnings(): HasMany
    {
        return $this->hasMany(FiscalYearClose::class, 'retained_earnings_account_id');
    }
}
