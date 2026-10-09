<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Queries\GeneralLedgerQuery;
use App\Accounting\Queries\TrialBalanceQuery;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class AccountingReportingQueryTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;

    private User $other;

    private ChartAccount $asset;

    private ChartAccount $liability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->asset = $this->account('1000', 'asset');
        $this->liability = $this->account('2000', 'liability');
    }

    private function account(string $code, string $type, ?User $actor = null): ChartAccount
    {
        $account = (new CreateChartAccount)->execute($actor ?? $this->owner, $code, 'Account '.$code, $type);
        $account->update(['cash_role' => 'non_cash']);

        return $account;
    }

    private function line(ChartAccount $account, string $debit, string $credit, ?string $description = 'Line description'): array
    {
        return ['chart_account_id' => $account->id, 'debit' => $debit, 'credit' => $credit, 'description' => $description];
    }

    private function journal(string $date, array $lines, bool $post = true, ?User $actor = null): JournalEntry
    {
        $actor ??= $this->owner;
        $journal = (new SaveJournalDraft)->execute($actor, $date, config('accounting.currency'), $lines, 'REF', 'Journal description');

        return $post ? (new PostJournalEntry)->execute($actor, $journal->id) : $journal;
    }

    private function transfer(string $date, string $amount, bool $reverse = false, bool $post = true): JournalEntry
    {
        return $this->journal($date, [
            $this->line($this->asset, $reverse ? '0' : $amount, $reverse ? $amount : '0'),
            $this->line($this->liability, $reverse ? $amount : '0', $reverse ? '0' : $amount),
        ], $post);
    }

    public function test_general_ledger_rejects_foreign_and_missing_accounts(): void
    {
        $foreign = $this->account('1000', 'asset', $this->other);
        foreach ([$foreign->id, ((int) ChartAccount::max('id')) + 1] as $id) {
            try {
                (new GeneralLedgerQuery)->execute($this->owner, $id);
                $this->fail('Foreign/missing account must be rejected.');
            } catch (ModelNotFoundException $exception) {
                $this->assertSame(ChartAccount::class, $exception->getModel());
            }
        }
    }

    public function test_unused_account_has_exact_zero_values_and_no_movements(): void
    {
        $ledger = (new GeneralLedgerQuery)->execute($this->owner, $this->asset->id);
        $this->assertSame(['id' => $this->asset->id, 'code' => '1000', 'name' => 'Account 1000', 'type' => 'asset'], $ledger['account']);
        $this->assertSame('0.00', $ledger['opening_balance']);
        $this->assertSame(['total_debit' => '0.00', 'total_credit' => '0.00', 'net_movement' => '0.00', 'closing_balance' => '0.00'], $ledger['period']);
        $this->assertSame([], $ledger['movements']);
    }

    public function test_opening_period_running_and_closing_balances_use_inclusive_dates_exactly(): void
    {
        $this->transfer('2026-01-01', '0.10');
        $first = $this->transfer('2026-02-01', '0.20');
        $second = $this->transfer('2026-02-28', '0.05', true);
        $this->transfer('2026-03-01', '99');
        $this->transfer('2026-02-10', '500', post: false);
        $ledger = (new GeneralLedgerQuery)->execute($this->owner, $this->asset->id, '2026-02-01', '2026-02-28');
        $this->assertSame('0.10', $ledger['opening_balance']);
        $this->assertSame(['total_debit' => '0.20', 'total_credit' => '0.05', 'net_movement' => '0.15', 'closing_balance' => '0.25'], $ledger['period']);
        $this->assertSame([$first->id, $second->id], array_column($ledger['movements'], 'journal_entry_id'));
        $this->assertSame(['0.30', '0.25'], array_column($ledger['movements'], 'running_balance'));
        $this->assertSame(['2026-02-01', '2026-02-28'], array_column($ledger['movements'], 'entry_date'));
        $this->assertSame('REF', $ledger['movements'][0]['reference']);
        $this->assertSame('Journal description', $ledger['movements'][0]['journal_description']);
        $this->assertSame('Line description', $ledger['movements'][0]['line_description']);
        $this->assertSame('0.20', $ledger['movements'][0]['debit']);
        $this->assertSame('0.05', $ledger['movements'][1]['credit']);
        $openingOnly = (new GeneralLedgerQuery)->execute($this->owner, $this->asset->id, '2026-02-02', '2026-02-02');
        $this->assertSame('0.30', $openingOnly['opening_balance']);
        $this->assertSame('0.30', $openingOnly['period']['closing_balance']);
        $this->assertSame([], $openingOnly['movements']);
        $noFrom = (new GeneralLedgerQuery)->execute($this->owner, $this->asset->id, dateTo: '2026-01-01');
        $this->assertSame('0.00', $noFrom['opening_balance']);
        $this->assertSame('0.10', $noFrom['period']['closing_balance']);
    }

    public function test_general_ledger_stable_order_and_negative_running_balances(): void
    {
        $first = $this->journal('2026-02-01', [
            $this->line($this->asset, '0', '0.10'), $this->line($this->asset, '0.20', '0'),
            $this->line($this->liability, '0', '0.10'),
        ]);
        $second = $this->transfer('2026-02-01', '0.30', true);
        $earlierDate = $this->transfer('2026-01-01', '0.10', true);
        $ledger = (new GeneralLedgerQuery)->execute($this->owner, $this->asset->id);
        $this->assertSame([$earlierDate->id, $first->id, $first->id, $second->id], array_column($ledger['movements'], 'journal_entry_id'));
        $this->assertSame([1, 1, 2, 1], array_column($ledger['movements'], 'line_number'));
        $this->assertSame(['-0.10', '-0.20', '0.00', '-0.30'], array_column($ledger['movements'], 'running_balance'));
        $this->assertSame('-0.30', $ledger['period']['closing_balance']);
        $fromOnly = (new GeneralLedgerQuery)->execute($this->owner, $this->asset->id, '2026-02-01');
        $this->assertSame('-0.10', $fromOnly['opening_balance']);
        $this->assertSame(['-0.20', '0.00', '-0.30'], array_column($fromOnly['movements'], 'running_balance'));
        $this->assertSame('-0.30', $fromOnly['period']['closing_balance']);
    }

    public function test_inactive_accounts_keep_history_and_foreign_journals_do_not_leak(): void
    {
        $this->transfer('2026-01-01', '1.23');
        $foreign = $this->account('1000', 'asset', $this->other);
        $this->journal('2026-01-01', [$this->line($foreign, '900', '0'), $this->line($foreign, '0', '900')], actor: $this->other);
        $this->asset->update(['is_active' => false]);
        $ledger = (new GeneralLedgerQuery)->execute($this->owner, $this->asset->id);
        $this->assertSame('1.23', $ledger['period']['total_debit']);
        $this->assertCount(1, $ledger['movements']);
        $balance = (new TrialBalanceQuery)->execute($this->owner);
        $this->assertSame([$this->asset->id, $this->liability->id], array_column($balance['accounts'], 'id'));
        $this->assertSame('1.23', $balance['totals']['total_debits']);
    }

    public function test_invalid_dates_and_ranges_are_rejected(): void
    {
        foreach ([['2026-02-30', null], ['not-date', null], [null, '2026-13-01'], ['2026-02-01', '2026-01-01']] as [$from, $to]) {
            try {
                (new GeneralLedgerQuery)->execute($this->owner, $this->asset->id, $from, $to);
                $this->fail('Invalid ledger date/range must fail.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        try {
            (new TrialBalanceQuery)->execute($this->owner, '2026-02-30');
            $this->fail('Invalid cutoff must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('as_of', $exception->errors());
        }
    }

    public function test_trial_balance_includes_all_types_zero_accounts_and_contrary_balances_in_code_order(): void
    {
        $expense = $this->account('5000', 'expense');
        $revenue = $this->account('4000', 'revenue');
        $equity = $this->account('3000', 'equity');
        $equity->update(['is_active' => false]);
        $this->journal('2026-01-01', [$this->line($revenue, '0.10', '0'), $this->line($expense, '0', '0.10')]);
        $this->transfer('2026-01-02', '0.20', true);
        $this->transfer('2026-01-03', '100', post: false);
        $balance = (new TrialBalanceQuery)->execute($this->owner);
        $this->assertSame(['1000', '2000', '3000', '4000', '5000'], array_column($balance['accounts'], 'code'));
        $this->assertSame(['asset', 'liability', 'equity', 'revenue', 'expense'], array_column($balance['accounts'], 'type'));
        $accounts = array_column($balance['accounts'], null, 'code');
        $this->assertSame('-0.20', $accounts['1000']['signed_net']);
        $this->assertSame('0.20', $accounts['1000']['credit_balance']);
        $this->assertSame('0.20', $accounts['2000']['debit_balance']);
        $this->assertSame('0.00', $accounts['3000']['signed_net']);
        $this->assertSame('0.00', $accounts['3000']['debit_total']);
        $this->assertSame('0.10', $accounts['4000']['debit_balance']);
        $this->assertSame('0.10', $accounts['5000']['credit_balance']);
        $this->assertSame(['total_debits' => '0.30', 'total_credits' => '0.30', 'total_debit_balances' => '0.30', 'total_credit_balances' => '0.30'], $balance['totals']);
    }

    public function test_trial_balance_cutoff_is_inclusive_and_excludes_future_and_draft_entries(): void
    {
        $this->transfer('2026-01-01', '0.10');
        $this->transfer('2026-01-31', '0.20');
        $this->transfer('2026-02-01', '50');
        $this->transfer('2026-01-15', '100', post: false);
        $balance = (new TrialBalanceQuery)->execute($this->owner, '2026-01-31');
        $this->assertSame('0.30', $balance['totals']['total_debits']);
        $this->assertSame('0.30', $balance['totals']['total_credits']);
        $this->assertSame('0.30', $balance['accounts'][0]['debit_balance']);
        $this->assertSame('0.30', $balance['accounts'][1]['credit_balance']);
        $before = (new TrialBalanceQuery)->execute($this->owner, '2025-12-31');
        $this->assertSame('0.00', $before['totals']['total_debits']);
        $this->assertCount(2, $before['accounts']);
    }

    public function test_actor_without_accounts_gets_zero_trial_balance(): void
    {
        $balance = (new TrialBalanceQuery)->execute($this->other);
        $this->assertSame([], $balance['accounts']);
        $this->assertSame(['total_debits' => '0.00', 'total_credits' => '0.00', 'total_debit_balances' => '0.00', 'total_credit_balances' => '0.00'], $balance['totals']);
    }

    public function test_unbalanced_posted_ledger_raises_domain_error_without_repair(): void
    {
        $journal = $this->journal('2026-01-01', [$this->line($this->asset, '1', '0')], post: false);
        // The trigger intentionally does not validate posting balance; a raw transition is constructible.
        DB::table('journal_entries')->where('id', $journal->id)->update(['status' => 'posted', 'posted_at' => now()]);
        $before = DB::table('journal_lines')->where('journal_entry_id', $journal->id)->get()->toJson();
        try {
            (new TrialBalanceQuery)->execute($this->owner);
            $this->fail('Unbalanced posted ledger must fail.');
        } catch (AccountingConflict $exception) {
            $this->assertStringContainsString('out of balance', $exception->getMessage());
        }
        $this->assertSame($before, DB::table('journal_lines')->where('journal_entry_id', $journal->id)->get()->toJson());
        $this->assertSame('posted', $journal->fresh()->status);
    }

    public function test_general_ledger_rejects_mixed_and_case_distinct_currencies_in_its_opening_and_period_scope(): void
    {
        $configured = config('accounting.currency');
        try {
            $this->transfer('2026-01-01', '0.01');
            $this->assertSame($configured, (new GeneralLedgerQuery)->execute($this->owner, $this->asset->id)['currency']);

            config(['accounting.currency' => $configured === 'SAR' ? 'USD' : 'SAR']);
            $this->transfer('2026-02-01', '9999999999999.99');
            $ledger = new GeneralLedgerQuery;
            $this->assertSame($configured, $ledger->execute($this->owner, $this->asset->id, dateTo: '2026-01-01')['currency']);
            try {
                $ledger->execute($this->owner, $this->asset->id);
                $this->fail('Mixed currencies must be rejected.');
            } catch (AccountingConflict $exception) {
                $this->assertStringContainsString('different currencies', $exception->getMessage());
            }
            try {
                $ledger->execute($this->owner, $this->asset->id, '2026-02-01', '2026-02-01');
                $this->fail('The opening balance must participate in the currency check.');
            } catch (AccountingConflict $exception) {
                $this->assertStringContainsString('different currencies', $exception->getMessage());
            }

            $foreignAsset = $this->account('1000', 'asset', $this->other);
            $foreignLiability = $this->account('2000', 'liability', $this->other);
            $this->journal('2026-01-01', [
                $this->line($foreignAsset, '1.00', '0'), $this->line($foreignLiability, '0', '1.00'),
            ], actor: $this->other);
            $this->assertSame($configured, $ledger->execute($this->owner, $this->asset->id, dateTo: '2026-01-01')['currency']);
        } finally {
            config(['accounting.currency' => $configured]);
        }
    }

    public function test_general_ledger_rejects_currency_codes_that_differ_only_by_case(): void
    {
        $this->transfer('2026-01-01', '0.01');
        $draft = $this->transfer('2026-01-02', '0.01', post: false);
        DB::table('journal_entries')->where('id', $draft->id)->update([
            'currency' => strtolower(config('accounting.currency')), 'status' => 'posted', 'posted_at' => now(),
        ]);

        $this->expectException(AccountingConflict::class);
        $this->expectExceptionMessage('different currencies');
        (new GeneralLedgerQuery)->execute($this->owner, $this->asset->id);
    }

    public function test_general_ledger_currency_check_keeps_the_selected_account_scope(): void
    {
        $configured = config('accounting.currency');
        try {
            $this->transfer('2026-01-01', '0.01');
            $otherAsset = $this->account('1100', 'asset');
            config(['accounting.currency' => $configured === 'SAR' ? 'USD' : 'SAR']);
            $this->journal('2026-01-02', [
                $this->line($otherAsset, '1.00', '0'), $this->line($this->liability, '0', '1.00'),
            ]);

            $ledger = (new GeneralLedgerQuery)->execute($this->owner, $this->asset->id);
            $this->assertSame($configured, $ledger['currency']);
            $this->assertSame('0.01', $ledger['period']['closing_balance']);
        } finally {
            config(['accounting.currency' => $configured]);
        }
    }

    public function test_trial_balance_rejects_mixed_and_case_distinct_currencies_but_is_owner_scoped(): void
    {
        $configured = config('accounting.currency');
        try {
            $this->transfer('2026-01-01', '0.01');
            config(['accounting.currency' => $configured === 'SAR' ? 'USD' : 'SAR']);
            $this->transfer('2026-02-01', '9999999999999.99');
            $query = new TrialBalanceQuery;
            $this->assertSame($configured, $query->execute($this->owner, '2026-01-01')['currency']);
            $this->assertSame('0.01', $query->execute($this->owner, '2026-01-01')['totals']['total_debits']);
            try {
                $query->execute($this->owner, '2026-02-01');
                $this->fail('Mixed currencies must be rejected.');
            } catch (AccountingConflict $exception) {
                $this->assertStringContainsString('different currencies', $exception->getMessage());
            }

            $foreignAsset = $this->account('1000', 'asset', $this->other);
            $foreignLiability = $this->account('2000', 'liability', $this->other);
            $this->journal('2026-01-01', [
                $this->line($foreignAsset, '1.00', '0'), $this->line($foreignLiability, '0', '1.00'),
            ], actor: $this->other);
            $this->assertSame($configured, $query->execute($this->owner, '2026-01-01')['currency']);
        } finally {
            config(['accounting.currency' => $configured]);
        }

    }

    public function test_trial_balance_rejects_currency_codes_that_differ_only_by_case(): void
    {
        $this->transfer('2026-01-01', '0.01');
        $draft = $this->transfer('2026-01-02', '0.01', post: false);
        DB::table('journal_entries')->where('id', $draft->id)->update([
            'currency' => strtolower(config('accounting.currency')), 'status' => 'posted', 'posted_at' => now(),
        ]);
        $this->expectException(AccountingConflict::class);
        $this->expectExceptionMessage('different currencies');
        (new TrialBalanceQuery)->execute($this->owner, '2026-01-02');
    }

    public function test_trial_balance_rejects_individually_unbalanced_journals_even_when_their_errors_offset(): void
    {
        $this->transfer('2026-01-01', '0.01');
        $debitOnly = $this->journal('2026-01-02', [$this->line($this->asset, '1.00', '0')], post: false);
        $creditOnly = $this->journal('2026-01-02', [$this->line($this->liability, '0', '1.00')], post: false);
        DB::table('journal_entries')->whereIn('id', [$debitOnly->id, $creditOnly->id])
            ->update(['status' => 'posted', 'posted_at' => now()]);
        $query = new TrialBalanceQuery;
        $this->assertSame('0.01', $query->execute($this->owner, '2026-01-01')['totals']['total_debits']);
        $totals = DB::table('journal_lines')->where('user_id', $this->owner->id)
            ->selectRaw('SUM(debit) AS debit_total, SUM(credit) AS credit_total')->first();
        $this->assertSame('1.01', $totals->debit_total);
        $this->assertSame($totals->debit_total, $totals->credit_total);
        try {
            $query->execute($this->owner, '2026-01-02');
            $this->fail('Offsetting corrupt journals must be rejected individually.');
        } catch (AccountingConflict $exception) {
            $this->assertStringContainsString('out of balance', $exception->getMessage());
        }
    }

    public function test_reports_use_one_nonlocking_statement_each_without_n_plus_one_reads(): void
    {
        $this->transfer('2026-01-01', '1');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            (new GeneralLedgerQuery)->execute($this->owner, $this->asset->id);
            $queries = DB::getQueryLog();
            $this->assertCount(1, $queries);
            $this->assertStringNotContainsString('for update', $queries[0]['query']);
            $this->assertStringNotContainsString('for share', $queries[0]['query']);
            $this->assertStringContainsString('`movement`.`journal_line_id` asc', $queries[0]['query']);
            DB::flushQueryLog();
            (new TrialBalanceQuery)->execute($this->owner);
            $queries = DB::getQueryLog();
            $this->assertCount(1, $queries);
            $this->assertStringNotContainsString('for update', $queries[0]['query']);
            $this->assertStringNotContainsString('for share', $queries[0]['query']);
            $this->assertStringContainsString('`chart_of_accounts`.`code` asc, `chart_of_accounts`.`id` asc', $queries[0]['query']);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_large_sql_totals_and_running_balances_exceed_native_integer_minor_units(): void
    {
        $journal = $this->journal('2026-01-01', [], post: false);
        // One bulk SQL fixture avoids 20,000 Eloquent writes/posting iterations.
        DB::statement("INSERT INTO journal_lines (user_id, journal_entry_id, chart_account_id, line_number, debit, credit)
            SELECT ?, ?, IF(sequence.n <= 10000, ?, ?), sequence.n,
                IF(sequence.n <= 10000, 9999999999999.99, 0.00), IF(sequence.n > 10000, 9999999999999.99, 0.00)
            FROM JSON_TABLE(?, '$[*]' COLUMNS (n FOR ORDINALITY)) AS sequence", [
            $this->owner->id, $journal->id, $this->asset->id, $this->liability->id, json_encode(array_fill(0, 20000, 0)),
        ]);
        DB::table('journal_entries')->where('id', $journal->id)->update(['status' => 'posted', 'posted_at' => now()]);
        $ledger = (new GeneralLedgerQuery)->execute($this->owner, $this->asset->id);
        $this->assertSame('99999999999999900.00', $ledger['period']['total_debit']);
        $this->assertSame('99999999999999900.00', $ledger['period']['closing_balance']);
        $this->assertCount(10000, $ledger['movements']);
        $this->assertSame('99999999999999900.00', $ledger['movements'][9999]['running_balance']);
        $balance = (new TrialBalanceQuery)->execute($this->owner);
        $this->assertSame('99999999999999900.00', $balance['totals']['total_debits']);
        $this->assertSame($balance['totals']['total_debits'], $balance['totals']['total_credits']);
        $this->assertSame('99999999999999900.00', $balance['totals']['total_debit_balances']);
        $this->assertSame($balance['totals']['total_debit_balances'], $balance['totals']['total_credit_balances']);
    }
}
