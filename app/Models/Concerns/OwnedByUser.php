<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait OwnedByUser
{
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where($this->qualifyColumn('user_id'), $user->getKey());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
