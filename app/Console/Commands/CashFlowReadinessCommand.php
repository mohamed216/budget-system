<?php

namespace App\Console\Commands;

use App\Accounting\Queries\CashFlowOperatorReadiness;

final class CashFlowReadinessCommand extends CashFlowOperatorCommand
{
    protected $signature = 'accounting:cash-flow-readiness {user : Owner user ID} {through : YYYY-MM-DD cutoff}';

    protected $description = 'List unresolved accounts and unsealed historical cash journals without changing data';

    public function handle(CashFlowOperatorReadiness $readiness): int
    {
        return $this->safely(function () use ($readiness): void {
            $result = $readiness->inspect($this->owner(), (string) $this->argument('through'));
            $this->line('Unresolved accounts:');
            foreach ($result['unresolved_accounts'] as $account) {
                $this->line(json_encode($account, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR));
            }
            $this->line('Incomplete historical ordinary cash journals through '.$this->argument('through').':');
            foreach ($result['incomplete_journals'] as $journal) {
                $this->line(json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR));
            }
            if (array_filter($result['incomplete_journals'], fn ($journal) => ! $journal['manual_completion_supported'])) {
                $this->warn('Unsealed ordinary reversals cannot be manually completed by this command; investigate before reporting.');
            }
            if ($result['unresolved_accounts'] !== []) {
                $this->warn('Review unresolved roles before treating the journal list as complete.');
            }
        });
    }
}
