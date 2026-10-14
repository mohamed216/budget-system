<?php

namespace App\OperationalAccounting;

/** Pure decision from current facts already locked by the posting action. */
final class OperationalAssetReservation
{
    /** @param array<int, true> $accountTargets Chart IDs reached through selected account mappings only. */
    public function touchesReservedAsset(int $ownerId, ?string $integrationState, array $accountTargets, iterable $charts): bool
    {
        if ($integrationState !== 'active') {
            return false;
        }
        foreach ($charts as $chart) {
            if ((string) $chart->user_id === (string) $ownerId && $chart->type === 'asset'
                && isset($accountTargets[$chart->id])) {
                return true;
            }
        }

        return false;
    }
}
