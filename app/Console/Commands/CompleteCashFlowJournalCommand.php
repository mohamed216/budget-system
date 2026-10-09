<?php

namespace App\Console\Commands;

use App\Accounting\Actions\CompleteHistoricalCashFlowJournal;
use Illuminate\Validation\ValidationException;
use JsonException;

final class CompleteCashFlowJournalCommand extends CashFlowOperatorCommand
{
    protected $signature = 'accounting:cash-flow-complete {user : Owner user ID} {journal : Owned posted journal ID} {file : JSON allocation list file}';

    protected $description = 'Seal explicit historical cash-flow allocations for one posted journal';

    public function handle(CompleteHistoricalCashFlowJournal $complete): int
    {
        return $this->safely(function () use ($complete): void {
            $owner = $this->owner();
            $journalId = $this->positiveId('journal');
            $path = (string) $this->argument('file');
            if (! is_file($path) || ! is_readable($path) || filesize($path) > 1048576) {
                throw ValidationException::withMessages(['file' => 'Provide a readable JSON file up to 1 MiB.']);
            }
            try {
                $rows = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw ValidationException::withMessages(['file' => 'Provide valid JSON.']);
            }
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw ValidationException::withMessages(['file' => 'Provide a JSON list of allocations.']);
            }
            $outcome = $complete->execute($owner, $journalId, $rows);
            $this->line('Journal #'.$journalId.': '.$outcome->name);
        });
    }
}
