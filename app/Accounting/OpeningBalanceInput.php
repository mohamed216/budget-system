<?php

namespace App\Accounting;

use App\Models\ChartAccount;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class OpeningBalanceInput
{
    public static function header(string $openingDate, string $currency): array
    {
        $input = Validator::make([
            'opening_date' => $openingDate,
            'currency' => $currency,
        ], [
            'opening_date' => ['required', 'date_format:Y-m-d'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/D'],
        ])->validate();

        if ($currency !== config('accounting.currency')) {
            throw ValidationException::withMessages(['currency' => 'The currency must match the configured accounting currency.']);
        }

        return $input;
    }

    /** Normalize only draft line input; the composite foreign keys remain the final ownership guard. */
    public static function lines(User $owner, array $lines): array
    {
        if (! array_is_list($lines)) {
            throw ValidationException::withMessages(['lines' => 'Lines must be a list.']);
        }

        Validator::make(['lines' => $lines], [
            'lines' => ['array'],
            'lines.*' => ['required', 'array:chart_account_id,debit,credit'],
            'lines.*.chart_account_id' => ['required', 'integer', 'min:1'],
            'lines.*.debit' => ['required'],
            'lines.*.credit' => ['required'],
        ])->validate();

        $normalized = [];
        $accountIds = [];
        foreach ($lines as $index => $line) {
            $accountId = (int) $line['chart_account_id'];
            if (isset($accountIds[$accountId])) {
                throw ValidationException::withMessages(["lines.$index.chart_account_id" => 'An account may appear only once in an opening balance batch.']);
            }
            $accountIds[$accountId] = true;

            try {
                $debit = DecimalAmount::fromString($line['debit']);
                $credit = DecimalAmount::fromString($line['credit']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["lines.$index" => 'Amounts must be exact decimal strings within DECIMAL(15,2).']);
            }
            if ($debit->isPositive() === $credit->isPositive()) {
                throw ValidationException::withMessages(["lines.$index" => 'Exactly one side must be positive.']);
            }

            $normalized[] = [
                'chart_account_id' => $accountId,
                'debit' => $debit->toDecimal(),
                'credit' => $credit->toDecimal(),
            ];
        }

        if ($accountIds !== [] && ChartAccount::ownedBy($owner)->whereIn('id', array_keys($accountIds))->count() !== count($accountIds)) {
            throw ValidationException::withMessages(['lines' => 'Every account must belong to the batch owner.']);
        }

        return $normalized;
    }
}
