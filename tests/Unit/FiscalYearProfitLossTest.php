<?php

namespace Tests\Unit;

use App\Accounting\FiscalYearProfitLoss;
use App\Models\ChartAccount;
use App\Models\JournalLine;
use InvalidArgumentException;
use Tests\TestCase;

class FiscalYearProfitLossTest extends TestCase
{
    private function account(int $id, string $type): ChartAccount
    {
        $account = new ChartAccount(['type' => $type]);
        $account->id = $id;

        return $account;
    }

    private function line(int $accountId, string $debit, string $credit): JournalLine
    {
        $line = new JournalLine(['chart_account_id' => $accountId, 'debit' => $debit, 'credit' => $credit]);
        $line->syncOriginal();

        return $line;
    }

    public function test_exact_profit_loss_contra_and_zero_profit_lines(): void
    {
        $accounts = collect([$this->account(1, 'revenue'), $this->account(2, 'expense')]);
        $calculate = new FiscalYearProfitLoss;
        $this->assertSame([
            ['chart_account_id' => 1, 'debit' => '100.01', 'credit' => '0.00'],
            ['chart_account_id' => 2, 'debit' => '0.00', 'credit' => '40.00'],
            ['chart_account_id' => 3, 'debit' => '0.00', 'credit' => '60.01'],
        ], $calculate->closingLines(collect([$this->line(1, '0.00', '100.01'), $this->line(2, '40.00', '0.00')]), $accounts, 3));
        $this->assertSame([
            ['chart_account_id' => 1, 'debit' => '0.00', 'credit' => '20.00'],
            ['chart_account_id' => 2, 'debit' => '5.00', 'credit' => '0.00'],
            ['chart_account_id' => 3, 'debit' => '15.00', 'credit' => '0.00'],
        ], $calculate->closingLines(collect([$this->line(1, '20.00', '0.00'), $this->line(2, '0.00', '5.00')]), $accounts, 3));
        $this->assertSame([
            ['chart_account_id' => 1, 'debit' => '1.00', 'credit' => '0.00'],
            ['chart_account_id' => 2, 'debit' => '0.00', 'credit' => '1.00'],
        ], $calculate->closingLines(collect([$this->line(1, '0.00', '1.00'), $this->line(2, '1.00', '0.00')]), $accounts, 3));
        $this->assertSame([], $calculate->closingLines(collect(), $accounts, 3));
    }

    public function test_maximum_decimal_is_exact_and_unrepresentable_line_is_rejected(): void
    {
        $accounts = collect([$this->account(1, 'revenue')]);
        $calculate = new FiscalYearProfitLoss;
        $this->assertSame([
            ['chart_account_id' => 1, 'debit' => '9999999999999.99', 'credit' => '0.00'],
            ['chart_account_id' => 3, 'debit' => '0.00', 'credit' => '9999999999999.99'],
        ], $calculate->closingLines(collect([$this->line(1, '0.00', '9999999999999.99')]), $accounts, 3));
        $this->expectException(InvalidArgumentException::class);
        $calculate->closingLines(collect([
            $this->line(1, '0.00', '9999999999999.99'),
            $this->line(1, '0.00', '0.01'),
        ]), $accounts, 3);
    }
}
