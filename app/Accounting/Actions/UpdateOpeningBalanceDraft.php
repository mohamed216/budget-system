<?php

namespace App\Accounting\Actions;

use App\Models\OpeningBalanceBatch;
use App\Models\User;

final class UpdateOpeningBalanceDraft
{
    public function execute(User $actor, int $batchId, string $openingDate, string $currency, array $lines): OpeningBalanceBatch
    {
        return (new SaveOpeningBalanceDraft)->execute($actor, $openingDate, $currency, $lines, $batchId);
    }
}
