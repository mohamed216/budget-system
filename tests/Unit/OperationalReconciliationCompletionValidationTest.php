<?php

namespace Tests\Unit;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\OperationalAccounting\OperationalReconciliationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsReconciliationCompletionSnapshots;

class OperationalReconciliationCompletionValidationTest extends TestCase
{
    use BuildsReconciliationCompletionSnapshots;

    private function approve(array $s): void
    {
        $v = new OperationalReconciliationValidator;
        $v->validate($s, $s['review']['reference'], $v->evidenceDigest($s['review'], $s['evidence']));
        $this->addToAssertionCount(1);
    }

    public function test_both_strategies_and_reviewed_empty_scope_are_valid(): void
    {
        $this->approve($this->completionSnapshot());
        $this->approve($this->completionSnapshot('historical_backfill'));
        $s = $this->completionSnapshot();
        foreach (['accounts', 'categories', 'charts', 'journals', 'attributions'] as $table) {
            $s['evidence'][$table] = [];
        }
        $s['sources']['operational_accounting_account_scope'] = [];
        $s['sources']['operational_accounting_category_scope'] = [];
        $this->approve($s); // Unselected balances/mappings/history never select scope.
    }

    public static function invalidSnapshots(): array
    {
        $cases = [
            'bound gate' => [['gate', 'reconciliation_reference'], 'already-bound'],
            'ready gate' => [['gate', 'state'], 'ready'],
            'completed review' => [['review', 'completed_at'], '2026-10-10 10:00:00.123456'],
            'strategy mismatch' => [['gate', 'historical_transaction_strategy'], 'historical_backfill'],
            'currency mismatch' => [['gate', 'accounting_currency'], 'USD'],
            'boundary mismatch' => [['gate', 'transaction_high_water_id'], 0],
            'scope time mismatch' => [['gate', 'scope_reviewed_at'], '2026-10-10 09:00:01'],
            'unreviewed scope' => [['gate', 'scope_reviewed_at'], null],
            'stale account mapping' => [['sources', 'operational_account_mappings', 0, 'chart_account_id'], 11],
            'stale category mapping' => [['sources', 'operational_category_mappings', 0, 'chart_account_id'], 11],
            'activity changed' => [['sources', 'operational_category_mappings', 0, 'cash_flow_activity'], 'investing'],
            'mapping reviewer missing' => [['sources', 'operational_category_mappings', 0, 'reviewed_by'], null],
            'role changed' => [['sources', 'chart_of_accounts', 0, 'cash_role'], 'cash'],
            'role unreviewed' => [['sources', 'chart_of_accounts', 0, 'cash_role'], null],
            'inactive chart' => [['sources', 'chart_of_accounts', 0, 'is_active'], 0],
            'wrong type' => [['sources', 'chart_of_accounts', 0, 'type'], 'liability'],
            'foreign chart' => [['sources', 'chart_of_accounts', 0, 'user_id'], 2],
            'balance changed' => [['sources', 'accounts', 0, 'balance'], '100.01'],
            'account currency changed' => [['sources', 'accounts', 0, 'currency'], 'USD'],
            'unexpected category' => [['sources', 'categories', 0, 'type'], 'transfer'],
            'post boundary activity' => [['sources', 'transactions', 0, 'id'], 2],
            'draft journal' => [['sources', 'journal_entries', 0, 'status'], 'draft'],
            'foreign journal' => [['sources', 'journal_entries', 0, 'user_id'], 2],
            'wrong journal currency' => [['sources', 'journal_entries', 0, 'currency'], 'USD'],
            'unbalanced journal' => [['sources', 'journal_lines', 1, 'credit'], '99.99'],
            'two sided line' => [['sources', 'journal_lines', 0, 'credit'], '0.01'],
            'undercoverage' => [['evidence', 'attributions', 0, 'amount'], '99.99'],
            'overcoverage' => [['evidence', 'attributions', 0, 'amount'], '100.01'],
            'wrong attribution target' => [['evidence', 'attributions', 0, 'account_id'], 2],
            'blank rationale' => [['evidence', 'attributions', 0, 'rationale'], '  '],
            'invalid purpose' => [['evidence', 'attributions', 0, 'purpose'], 'historical_transaction'],
            'blank disclosure' => [['review', 'balance_only_disclosure_text'], '  '],
            'wrong disclosure code' => [['review', 'balance_only_disclosure_code'], 'other'],
            'missing supersession' => [['review', 'supersedes_review_id'], 2],
        ];

        return array_map(fn ($case) => $case, $cases);
    }

    #[DataProvider('invalidSnapshots')]
    public function test_invalid_authoritative_snapshots_reject(array $path, mixed $value): void
    {
        $s = $this->completionSnapshot();
        $node = &$s;
        foreach ($path as $key) {
            $node = &$node[$key];
        }
        $node = $value;
        $this->expectException(AccountingConflict::class);
        $this->approve($s);
    }

    public static function evidenceSets(): array
    {
        return [['accounts'], ['categories'], ['charts'], ['journals'], ['attributions']];
    }

    #[DataProvider('evidenceSets')]
    public function test_missing_and_duplicate_extra_evidence_is_rejected(string $table): void
    {
        foreach (['missing', 'duplicate'] as $mode) {
            $s = $this->completionSnapshot();
            if ($mode === 'missing') {
                $s['evidence'][$table] = [];
            } else {
                $s['evidence'][$table][] = $s['evidence'][$table][0];
            }
            try {
                $this->approve($s);
                $this->fail('Incomplete or duplicate evidence accepted.');
            } catch (AccountingConflict $e) {
                $this->assertContains($e->reason, [AccountingConflictReason::ReconciliationEvidenceInvalid, AccountingConflictReason::ReconciliationSnapshotStale]);
            }
        }
    }

    #[DataProvider('evidenceSets')]
    public function test_distinct_extra_evidence_cannot_expand_the_reviewed_scope(string $table): void
    {
        $s = $this->completionSnapshot();
        $row = $s['evidence'][$table][0];
        if ($table === 'accounts') {
            $s['sources']['accounts'][] = ['id' => 3, 'user_id' => 1, 'balance' => '0.00', 'currency' => 'SAR'];
            $row['account_id'] = 3;
            $row['stored_balance_snapshot'] = '0.00';
        } elseif ($table === 'categories') {
            $s['sources']['categories'][] = ['id' => 3, 'user_id' => 1, 'type' => 'income'];
            $row['category_id'] = 3;
        } elseif ($table === 'charts') {
            $row['chart_account_id'] = 11;
            $row['type_snapshot'] = 'equity';
        } elseif ($table === 'journals') {
            $s['sources']['journal_entries'][] = array_replace($s['sources']['journal_entries'][0], ['id' => 21]);
            $row['journal_entry_id'] = 21;
        } else {
            $row['id'] = 2;
            $row['purpose'] = 'existing_history';
            $row['amount'] = '0.01';
        }
        $s['evidence'][$table][] = $row;
        $this->expectException(AccountingConflict::class);
        $this->approve($s);
    }

    public function test_digest_is_order_independent_type_normalized_and_sensitive_to_decisions(): void
    {
        $s = $this->completionSnapshot();
        $v = new OperationalReconciliationValidator;
        $digest = $v->evidenceDigest($s['review'], $s['evidence']);
        $e = $s['evidence'];
        foreach ($e as &$rows) {
            $rows = array_reverse($rows);
            foreach ($rows as &$row) {
                foreach ($row as $key => &$value) {
                    if (is_int($value)) {
                        $value = (string) $value;
                    }
                }
            }
            unset($row, $value);
        }
        unset($rows);
        $this->assertSame($digest, $v->evidenceDigest($s['review'], $e));
        foreach ([['attributions', 'rationale'], ['attributions', 'purpose'], ['accounts', 'rationale']] as [$table, $field]) {
            $changed = $e;
            $changed[$table][0][$field] = 'Another explicit decision';
            $this->assertNotSame($digest, $v->evidenceDigest($s['review'], $changed));
        }
        $review = $s['review'];
        $review['balance_only_disclosure_text'] .= ' Additional disclosure.';
        $this->assertNotSame($digest, $v->evidenceDigest($review, $e));
        $review = $s['review'];
        $review['reviewed_by'] = 1;
        $review['completed_at'] = '2026-10-10 12:00:00.123456';
        $this->assertSame($digest, $v->evidenceDigest($review, $e));
        $s['evidence']['accounts'][0]['rationale'] = 'Changed after approval';
        try {
            $v->validate($s, $s['review']['reference'], $digest);
            $this->fail('Stale approval accepted.');
        } catch (AccountingConflict $e) {
            $this->assertSame(AccountingConflictReason::ReconciliationDigestMismatch, $e->reason);
        }
    }

    public function test_digest_rejects_float_values(): void
    {
        $s = $this->completionSnapshot();
        $s['evidence']['accounts'][0]['stored_balance_snapshot'] = 100.0;
        $this->expectException(AccountingConflict::class);
        (new OperationalReconciliationValidator)->evidenceDigest($s['review'], $s['evidence']);
    }

    public function test_shared_target_requires_explicit_full_allocation(): void
    {
        $s = $this->completionSnapshot();
        $s['sources']['accounts'][0]['balance'] = '60.00';
        $s['evidence']['accounts'][0]['stored_balance_snapshot'] = '60.00';
        $s['sources']['accounts'][] = ['id' => 3, 'user_id' => 1, 'balance' => '40.00', 'currency' => 'SAR'];
        $s['sources']['operational_account_mappings'][] = ['account_id' => 3, 'user_id' => 1, 'chart_account_id' => 10];
        $s['sources']['operational_accounting_account_scope'][] = ['account_id' => 3, 'user_id' => 1];
        $s['evidence']['accounts'][] = array_replace($s['evidence']['accounts'][0], ['account_id' => 3, 'stored_balance_snapshot' => '40.00']);
        $s['evidence']['attributions'][0]['amount'] = '60.00';
        $s['evidence']['attributions'][] = array_replace($s['evidence']['attributions'][0], ['id' => 2, 'account_id' => 3, 'amount' => '40.00']);
        $this->approve($s);
        $s['evidence']['attributions'] = [array_replace($s['evidence']['attributions'][0], ['amount' => '100.00'])];
        $this->expectException(AccountingConflict::class);
        $this->approve($s);
    }

    public function test_negative_balances_and_wide_aggregate_arithmetic(): void
    {
        $s = $this->completionSnapshot();
        $s['sources']['accounts'][0]['balance'] = '-100.00';
        $s['evidence']['accounts'][0]['stored_balance_snapshot'] = '-100.00';
        foreach ($s['sources']['journal_lines'] as &$line) {
            [$line['debit'], $line['credit']] = [$line['credit'], $line['debit']];
        }
        unset($line);
        $this->approve($s);
        $s = $this->completionSnapshot();
        $s['sources']['accounts'][0]['balance'] = '0.00';
        $s['evidence']['accounts'][0]['stored_balance_snapshot'] = '0.00';
        $s['sources']['journal_lines'] = [];
        $s['evidence']['attributions'] = [];
        foreach ([1, 2, 3, 4] as $i) {
            $debit = $i <= 2;
            $s['sources']['journal_lines'][] = ['id' => $i + 30, 'user_id' => 1, 'journal_entry_id' => 20, 'line_number' => $i,
                'chart_account_id' => 10, 'debit' => $debit ? '9999999999999.99' : '0.00', 'credit' => $debit ? '0.00' : '9999999999999.99'];
            $s['evidence']['attributions'][] = array_replace($this->completionSnapshot()['evidence']['attributions'][0],
                ['id' => $i, 'journal_line_id' => $i + 30, 'amount' => '9999999999999.99']);
        }
        $this->approve($s);
    }

    public function test_backfill_omitted_scope_invalid_link_and_excluded_movement_reject(): void
    {
        foreach (['scope', 'link', 'attribution'] as $failure) {
            $s = $this->completionSnapshot('historical_backfill');
            if ($failure === 'scope') {
                $s['sources']['operational_accounting_category_scope'] = [];
                $s['evidence']['categories'] = [];
                $s['evidence']['charts'] = [$s['evidence']['charts'][0]];
            }
            if ($failure === 'link') {
                $s['sources']['operational_transaction_journals'][0]['account_id'] = 999;
            }
            if ($failure === 'attribution') {
                $s['evidence']['attributions'][0]['purpose'] = 'opening_balance';
            }
            try {
                $this->approve($s);
                $this->fail('Invalid historical coverage accepted.');
            } catch (AccountingConflict $e) {
                $this->assertSame(AccountingConflictReason::ReconciliationEvidenceInvalid, $e->reason);
            }
        }
    }

    public function test_undiscovered_source_signals_retry_without_database_discovery(): void
    {
        $s = $this->completionSnapshot();
        $s['evidence']['charts'][0]['chart_account_id'] = 999;
        try {
            $this->approve($s);
            $this->fail('Undiscovered source accepted.');
        } catch (AccountingConflict $e) {
            $this->assertSame(AccountingConflictReason::ReconciliationDiscoveryConflict, $e->reason);
        }
    }

    public function test_closed_period_is_not_a_new_posting_and_supersession_is_checked(): void
    {
        $s = $this->completionSnapshot();
        $s['sources']['accounting_periods'][] = ['id' => 1, 'user_id' => 1, 'status' => 'closed'];
        $s['review']['supersedes_review_id'] = 2;
        $s['predecessor'] = ['id' => 2, 'user_id' => 1, 'reviewed_by' => 1, 'completed_at' => '2026-10-09 10:00:00'];
        $this->approve($s);
        foreach ([['user_id' => 2], ['completed_at' => null], ['reviewed_by' => null]] as $changes) {
            $bad = $s;
            $bad['predecessor'] = array_replace($bad['predecessor'], $changes);
            try {
                $this->approve($bad);
                $this->fail('Invalid supersession accepted.');
            } catch (AccountingConflict $e) {
                $this->assertSame(AccountingConflictReason::ReconciliationEvidenceInvalid, $e->reason);
            }
        }
    }

    public function test_opening_fiscal_close_and_reversal_provenance_is_reconstructed(): void
    {
        $s = $this->completionSnapshot();
        $s['sources']['opening_balance_batches'] = [['id' => 40, 'user_id' => 1, 'journal_entry_id' => 20,
            'status' => 'posted', 'opening_date' => '2026-10-06', 'currency' => 'SAR', 'posted_at' => '2026-10-10 09:00:00']];
        foreach ($s['sources']['journal_lines'] as $line) {
            $s['sources']['opening_balance_lines'][] = ['id' => $line['id'], 'user_id' => 1, 'batch_id' => 40,
                'chart_account_id' => $line['chart_account_id'], 'debit' => $line['debit'], 'credit' => $line['credit']];
        }
        $this->approve($s);
        $bad = $s;
        $bad['sources']['opening_balance_lines'][0]['debit'] = '99.99';
        try {
            $this->approve($bad);
            $this->fail('Invalid opening provenance accepted.');
        } catch (AccountingConflict $e) {
            $this->assertSame(AccountingConflictReason::ReconciliationEvidenceInvalid, $e->reason);
        }
        $s = $this->completionSnapshot();
        $s['sources']['accounts'][0]['balance'] = '0.00';
        $s['evidence']['accounts'][0]['stored_balance_snapshot'] = '0.00';
        $s['sources']['journal_entries'][] = array_replace($s['sources']['journal_entries'][0], ['id' => 21, 'reversal_of_id' => 20]);
        foreach (array_slice($s['sources']['journal_lines'], 0, 2) as $line) {
            $s['sources']['journal_lines'][] = array_replace($line, ['id' => $line['id'] + 2, 'journal_entry_id' => 21,
                'debit' => $line['credit'], 'credit' => $line['debit']]);
        }
        $s['evidence']['journals'][] = ['review_id' => 1, 'user_id' => 1, 'journal_entry_id' => 21];
        $s['evidence']['attributions'][] = array_replace($s['evidence']['attributions'][0], ['id' => 2, 'journal_entry_id' => 21, 'journal_line_id' => 32]);
        $this->approve($s);
        $bad = $s;
        $bad['sources']['journal_entries'][1]['reversal_of_id'] = 999;
        try {
            $this->approve($bad);
            $this->fail('Missing reversal source accepted.');
        } catch (AccountingConflict $e) {
            $this->assertSame(AccountingConflictReason::ReconciliationEvidenceInvalid, $e->reason);
        }
        $s = $this->completionSnapshot();
        $s['sources']['journal_lines'][1]['chart_account_id'] = 12;
        $s['sources']['journal_entries'][] = array_replace($s['sources']['journal_entries'][0], ['id' => 21, 'entry_date' => '2026-10-31']);
        $s['sources']['journal_lines'][] = ['id' => 32, 'user_id' => 1, 'journal_entry_id' => 21, 'chart_account_id' => 12, 'line_number' => 1, 'debit' => '100.00', 'credit' => '0.00'];
        $s['sources']['journal_lines'][] = ['id' => 33, 'user_id' => 1, 'journal_entry_id' => 21, 'chart_account_id' => 11, 'line_number' => 2, 'debit' => '0.00', 'credit' => '100.00'];
        $s['sources']['fiscal_year_closes'][] = ['id' => 40, 'user_id' => 1, 'journal_entry_id' => 21,
            'currency' => 'SAR', 'start_date' => '2026-01-01', 'end_date' => '2026-10-31', 'closed_at' => '2026-11-01 00:00:00', 'retained_earnings_account_id' => 11];
        $s['evidence']['journals'][] = ['review_id' => 1, 'user_id' => 1, 'journal_entry_id' => 21];
        $this->approve($s);
        $bad = $s;
        $bad['sources']['journal_lines'][2]['debit'] = '200.00';
        $bad['sources']['journal_lines'][3]['credit'] = '200.00';
        try {
            $this->approve($bad);
            $this->fail('Balanced but incorrect fiscal-close amount accepted.');
        } catch (AccountingConflict $e) {
            $this->assertSame(AccountingConflictReason::ReconciliationEvidenceInvalid, $e->reason);
        }
    }

    public function test_cash_reversal_must_inherit_original_classification_exactly(): void
    {
        $s = $this->completionSnapshot();
        $s['sources']['accounts'][0]['balance'] = '0.00';
        $s['evidence']['accounts'][0]['stored_balance_snapshot'] = '0.00';
        $s['sources']['chart_of_accounts'][0]['cash_role'] = 'cash';
        $s['evidence']['charts'][0]['cash_role_snapshot'] = 'cash';
        $s['sources']['journal_entries'][] = array_replace($s['sources']['journal_entries'][0], ['id' => 21, 'reversal_of_id' => 20]);
        foreach (array_slice($s['sources']['journal_lines'], 0, 2) as $line) {
            $s['sources']['journal_lines'][] = array_replace($line, ['id' => $line['id'] + 2, 'journal_entry_id' => 21,
                'debit' => $line['credit'], 'credit' => $line['debit']]);
        }
        $s['evidence']['journals'][] = ['review_id' => 1, 'user_id' => 1, 'journal_entry_id' => 21];
        $s['evidence']['attributions'][] = array_replace($s['evidence']['attributions'][0], ['id' => 2, 'journal_entry_id' => 21, 'journal_line_id' => 32]);
        $s['sources']['journal_line_allocations'] = [
            ['id' => 1, 'user_id' => 1, 'journal_entry_id' => 20, 'debit_line_id' => 30, 'credit_line_id' => 31, 'amount' => '100.00', 'category' => 'operating'],
            ['id' => 2, 'user_id' => 1, 'journal_entry_id' => 21, 'debit_line_id' => 33, 'credit_line_id' => 32, 'amount' => '100.00', 'category' => 'operating'],
        ];
        $s['sources']['cash_flow_journal_completions'] = [
            ['id' => 1, 'user_id' => 1, 'journal_entry_id' => 20, 'completed_at' => '2026-10-10 09:00:00'],
            ['id' => 2, 'user_id' => 1, 'journal_entry_id' => 21, 'completed_at' => '2026-10-10 09:00:00'],
        ];
        $this->approve($s);
        $s['sources']['journal_line_allocations'][1]['category'] = 'investing';
        $this->expectException(AccountingConflict::class);
        $this->approve($s);
    }

    public function test_cash_backfill_needs_full_classified_allocations_and_completion(): void
    {
        $s = $this->completionSnapshot('historical_backfill');
        $s['sources']['chart_of_accounts'][0]['cash_role'] = 'cash';
        $s['evidence']['charts'][0]['cash_role_snapshot'] = 'cash';
        $s['sources']['journal_line_allocations'] = [['id' => 1, 'user_id' => 1, 'journal_entry_id' => 20,
            'debit_line_id' => 30, 'credit_line_id' => 31, 'amount' => '100.00', 'category' => 'operating']];
        $s['sources']['cash_flow_journal_completions'] = [['id' => 1, 'user_id' => 1, 'journal_entry_id' => 20, 'completed_at' => '2026-10-10 09:00:00']];
        $this->approve($s);
        foreach (['missing completion', 'undercoverage', 'wrong activity'] as $failure) {
            $bad = $s;
            if ($failure === 'missing completion') {
                $bad['sources']['cash_flow_journal_completions'] = [];
            }
            if ($failure === 'undercoverage') {
                $bad['sources']['journal_line_allocations'][0]['amount'] = '99.99';
            }
            if ($failure === 'wrong activity') {
                $bad['sources']['journal_line_allocations'][0]['category'] = 'investing';
            }
            try {
                $this->approve($bad);
                $this->fail('Invalid cash evidence accepted.');
            } catch (AccountingConflict $e) {
                $this->assertSame(AccountingConflictReason::ReconciliationEvidenceInvalid, $e->reason);
            }
        }
    }

    public function test_exclusion_requires_rationale_and_balance_only_rejects_backfill(): void
    {
        $s = $this->completionSnapshot();
        $s['sources']['accounts'][0]['balance'] = '0.00';
        $s['evidence']['accounts'][0]['stored_balance_snapshot'] = '0.00';
        $s['evidence']['attributions'][0] = array_replace($s['evidence']['attributions'][0], ['target_kind' => 'excluded',
            'account_id' => null, 'purpose' => null, 'rationale' => 'Reviewed history attributable to a nonparticipating account.']);
        $this->approve($s);
        $bad = $s;
        $bad['evidence']['attributions'][0]['rationale'] = " \t\n";
        try {
            $this->approve($bad);
            $this->fail('Unjustified exclusion accepted.');
        } catch (AccountingConflict $e) {
            $this->assertSame(AccountingConflictReason::ReconciliationEvidenceInvalid, $e->reason);
        }
        $bad = $this->completionSnapshot();
        $bad['sources']['operational_transaction_journals'] = $this->completionSnapshot('historical_backfill')['sources']['operational_transaction_journals'];
        $this->expectException(AccountingConflict::class);
        $this->approve($bad);
    }
}
