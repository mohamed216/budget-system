<?php

namespace App\Accounting\Actions;

use App\Models\OpeningBalanceBatch;
use App\Models\User;

final class CreateOpeningBalanceDraft
{
    public function execute(User $actor, string $openingDate, string $currency, array $lines): OpeningBalanceBatch
    {
        return (new SaveOpeningBalanceDraft)->execute($actor, $openingDate, $currency, $lines);
    }
}
