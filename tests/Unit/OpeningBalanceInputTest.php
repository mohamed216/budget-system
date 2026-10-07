<?php

namespace Tests\Unit;

use App\Accounting\OpeningBalanceInput;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OpeningBalanceInputTest extends TestCase
{
    public function test_header_requires_strict_date_and_configured_currency(): void
    {
        $currency = config('accounting.currency');
        $this->assertSame(['opening_date' => '2026-01-01', 'currency' => $currency],
            OpeningBalanceInput::header('2026-01-01', $currency));

        foreach ([['2026-02-30', $currency], ['2026-1-1', $currency], ['2026-01-01', 'usd'],
            ['2026-01-01', $currency === 'USD' ? 'SAR' : 'USD']] as [$date, $code]) {
            try {
                OpeningBalanceInput::header($date, $code);
                $this->fail('Expected invalid opening balance header.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_empty_draft_lines_are_allowed_without_database_access(): void
    {
        $this->assertSame([], OpeningBalanceInput::lines(new User, []));
    }

    public function test_invalid_money_and_duplicate_accounts_fail_before_database_access(): void
    {
        foreach ([
            [['chart_account_id' => 1, 'debit' => 0.01, 'credit' => '0']],
            [['chart_account_id' => 1, 'debit' => '0', 'credit' => '0']],
            [['chart_account_id' => 1, 'debit' => '1', 'credit' => '1']],
            [['chart_account_id' => 1, 'debit' => '10000000000000.00', 'credit' => '0']],
            [['chart_account_id' => 1, 'debit' => '1', 'credit' => '0'],
                ['chart_account_id' => 1, 'debit' => '0', 'credit' => '1']],
        ] as $lines) {
            try {
                OpeningBalanceInput::lines(new User, $lines);
                $this->fail('Expected invalid opening balance lines.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }
}
