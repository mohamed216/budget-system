<?php

namespace App\OperationalAccounting;

use App\Accounting\DecimalAmount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Accounting\SignedDecimalAmount;
use InvalidArgumentException;

/** Pure validation of a source-closed, current-read, already-locked snapshot. */
class OperationalReconciliationValidator
{
    private const EVIDENCE_FIELDS = [
        'review' => ['id', 'user_id', 'reference', 'historical_transaction_strategy', 'accounting_currency',
            'transaction_high_water_id', 'scope_reviewed_at', 'supersedes_review_id', 'balance_only_disclosure_code',
            'balance_only_disclosure_text', 'created_at'],
        'charts' => ['review_id', 'user_id', 'chart_account_id', 'type_snapshot', 'cash_role_snapshot', 'is_active_snapshot'],
        'accounts' => ['review_id', 'user_id', 'account_id', 'chart_account_id', 'stored_balance_snapshot', 'currency_snapshot', 'rationale'],
        'categories' => ['review_id', 'user_id', 'category_id', 'category_type_snapshot', 'chart_account_id', 'cash_flow_activity'],
        'journals' => ['review_id', 'user_id', 'journal_entry_id'],
        'attributions' => ['id', 'review_id', 'user_id', 'journal_entry_id', 'journal_line_id', 'target_kind', 'account_id', 'purpose', 'amount', 'rationale'],
    ];

    /** Length-framed UTF-8 bytes, explicit NULL marker, canonical scalar values;
     * sorted encoded rows make retrieval order irrelevant. No JSON/float hashing.
     */
    public function evidenceDigest(array $review, array $evidence): string
    {
        $bytes = 'operational-reconciliation-evidence-v1';
        foreach (self::EVIDENCE_FIELDS as $table => $fields) {
            $rows = $table === 'review' ? [$review] : ($evidence[$table] ?? []);
            $encoded = [];
            foreach ($rows as $row) {
                $value = '';
                foreach ($fields as $field) {
                    if (! array_key_exists($field, $row)) {
                        $this->invalid('Incomplete normalized evidence field: '.$table.'.'.$field);
                    }
                    $scalar = $row[$field];
                    if ($scalar === null) {
                        $value .= 'N;';

                        continue;
                    }
                    if (! is_string($scalar) && ! is_int($scalar) && ! is_bool($scalar)) {
                        $this->invalid('Evidence must use exact normalized scalar values.');
                    }
                    $scalar = (string) $scalar;
                    if ($field === 'id' || str_ends_with($field, '_id')) {
                        if (! preg_match('/\A[0-9]+\z/', $scalar)) {
                            $this->invalid('Invalid evidence identity.');
                        }
                        $scalar = ltrim($scalar, '0') ?: '0';
                    } elseif (in_array($field, ['stored_balance_snapshot', 'amount'], true)) {
                        if (! is_string($row[$field])) {
                            $this->invalid('Evidence money must be an exact decimal string.');
                        }
                        $scalar = SignedDecimalAmount::fromAggregateString($scalar)->toDecimal();
                    } elseif ($field === 'is_active_snapshot') {
                        $scalar = $row[$field] === false ? '0' : $scalar;
                        if (! in_array($scalar, ['0', '1'], true)) {
                            $this->invalid('Invalid active snapshot.');
                        }
                    } elseif ($field === 'created_at') {
                        $scalar = $this->timestamp($scalar, true);
                    } elseif ($field === 'scope_reviewed_at') {
                        $scalar = $this->timestamp($scalar, false);
                    }
                    $value .= strlen($scalar).':'.$scalar.';';
                }
                $encoded[] = $value;
            }
            sort($encoded, SORT_STRING);
            $bytes .= strlen($table).':'.$table.';'.count($encoded).';';
            foreach ($encoded as $row) {
                $bytes .= strlen($row).':'.$row.';';
            }
        }

        return hash('sha256', $bytes);
    }

    public function validate(array $snapshot, string $reference, string $expectedDigest): void
    {
        try {
            $this->validateSnapshot($snapshot, $reference, $expectedDigest);
        } catch (InvalidArgumentException $exception) {
            throw new AccountingConflict('Invalid exact reconciliation amount.', previous: $exception,
                reason: AccountingConflictReason::ReconciliationEvidenceInvalid);
        }
    }

    /** Completed evidence is immutable; no new reviewer approval token is needed. */
    public function validateBound(array $snapshot, string $expectedState): void
    {
        try {
            $reference = $snapshot['gate']['reconciliation_reference'] ?? null;
            if (! is_string($reference)) {
                $this->conflict('Readiness requires a completed binding.', AccountingConflictReason::ReconciliationState);
            }
            $this->validateSnapshot($snapshot, $reference, null, $expectedState);
        } catch (InvalidArgumentException $exception) {
            throw new AccountingConflict('Invalid exact reconciliation amount.', previous: $exception,
                reason: AccountingConflictReason::ReconciliationEvidenceInvalid);
        }
    }

    private function validateSnapshot(array $s, string $reference, ?string $digest, ?string $boundState = null): void
    {
        $r = $s['review'];
        $g = $s['gate'];
        $owner = (string) $s['owner_id'];
        if ($boundState !== null) {
            if ($g === null || $g['state'] !== $boundState || $g['reconciliation_reference'] === null || $g['reconciled_at'] === null) {
                $this->conflict('Readiness requires a bound integration in the expected state.', AccountingConflictReason::ReconciliationState);
            }
            if ($r['completed_at'] === null || $r['reviewed_by'] === null
                || (string) $r['reviewed_by'] !== $owner || $r['reference'] !== $g['reconciliation_reference']
                || $r['completed_at'] !== $g['reconciled_at']) {
                $this->invalid('Binding requires the exact owned completed self-review.');
            }
        } elseif ($r['completed_at'] !== null || $r['reviewed_by'] !== null) {
            $this->conflict('Review is already completed; it cannot be rebound.', AccountingConflictReason::ReconciliationAlreadyCompleted);
        }
        if ($boundState === null && ($g === null || $g['state'] !== 'reconciling' || $g['reconciliation_reference'] !== null || $g['reconciled_at'] !== null)) {
            $this->conflict('Completion requires an unbound reconciling integration.', AccountingConflictReason::ReconciliationState);
        }
        if ((string) $r['user_id'] !== $owner || (string) $g['user_id'] !== $owner || $r['reference'] !== $reference
            || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $reference)) {
            $this->invalid('Invalid owned canonical review identity.');
        }
        if (! in_array($r['historical_transaction_strategy'], ['balance_only', 'historical_backfill'], true)
            || $r['accounting_currency'] !== $s['currency'] || ! preg_match('/\A[A-Z]{3}\z/', $r['accounting_currency'])
            || $r['scope_reviewed_at'] === null || $r['transaction_high_water_id'] === null) {
            $this->stale('Review plan is incomplete or currency conflicts.');
        }
        foreach (['historical_transaction_strategy', 'accounting_currency', 'transaction_high_water_id', 'scope_reviewed_at'] as $field) {
            if ($g[$field] === null || (string) $g[$field] !== (string) $r[$field]) {
                $this->stale('Integration and review plan disagree: '.$field);
            }
        }
        if ($r['supersedes_review_id'] !== null) {
            $p = $s['predecessor'];
            if ($p === null || (string) $p['user_id'] !== $owner || (string) $p['id'] !== (string) $r['supersedes_review_id']
                || (string) $p['id'] === (string) $r['id'] || $p['completed_at'] === null || $p['reviewed_by'] === null) {
                $this->invalid('Supersession requires an owned completed predecessor.');
            }
        }
        $backfill = $r['historical_transaction_strategy'] === 'historical_backfill';
        if ($backfill ? ($r['balance_only_disclosure_code'] !== null || $r['balance_only_disclosure_text'] !== null)
            : ($r['balance_only_disclosure_code'] !== 'balance_only_legacy_history_v1' || ! $this->nonblank($r['balance_only_disclosure_text']))) {
            $this->invalid('Invalid historical-strategy disclosure.');
        }
        $actualDigest = $this->evidenceDigest($r, $s['evidence']);
        if ($boundState === null && ! hash_equals($digest, $actualDigest)) {
            $this->conflict('Reviewed evidence changed; approval token does not match.', AccountingConflictReason::ReconciliationDigestMismatch);
        }
        $e = $s['evidence'];
        foreach ($e as $rows) {
            foreach ($rows as $row) {
                if ((string) $row['user_id'] !== $owner || (string) $row['review_id'] !== (string) $r['id']) {
                    $this->invalid('Foreign or reassigned evidence.');
                }
            }
        }
        $src = $s['sources'];
        foreach ($src as $rows) {
            foreach ($rows as $row) {
                if ((string) $row['user_id'] !== $owner) {
                    $this->invalid('Source manifest contains a foreign or unassigned record.');
                }
            }
        }
        foreach ($src['transactions'] as $transaction) {
            if ($this->idGreater($transaction['id'], $r['transaction_high_water_id'])) {
                $this->conflict('Owner activity exists above the boundary; explicit recapture is required.', AccountingConflictReason::ReconciliationBoundaryStale);
            }
        }
        // A validator cannot go back to the database for a source omitted by the
        // locking phase. Signal whole-transaction rediscovery, never lock backwards.
        foreach (['charts' => ['chart_account_id', 'chart_of_accounts'], 'accounts' => ['account_id', 'accounts'],
            'categories' => ['category_id', 'categories'], 'journals' => ['journal_entry_id', 'journal_entries'],
            'attributions' => ['journal_line_id', 'journal_lines']] as $suffix => [$key, $table]) {
            $available = array_map('strval', array_column($src[$table], 'id'));
            foreach ($e[$suffix] as $row) {
                if (! in_array((string) $row[$key], $available, true)) {
                    $this->conflict('An evidence source was not in the locked manifest.', AccountingConflictReason::ReconciliationDiscoveryConflict);
                }
            }
        }
        $accounts = $this->index($src['accounts'], 'id');
        $categories = $this->index($src['categories'], 'id');
        $charts = $this->index($src['chart_of_accounts'], 'id');
        $am = $this->index($src['operational_account_mappings'], 'account_id');
        $cm = $this->index($src['operational_category_mappings'], 'category_id');
        $ea = $this->index($e['accounts'], 'account_id');
        $ec = $this->index($e['categories'], 'category_id');
        $ech = $this->index($e['charts'], 'chart_account_id');
        $this->sameSet(array_column($src['operational_accounting_account_scope'], 'account_id'), array_keys($ea), 'account scope');
        $this->sameSet(array_column($src['operational_accounting_category_scope'], 'category_id'), array_keys($ec), 'category scope');
        $requiredCharts = [];
        foreach ($ea as $id => $row) {
            $a = $accounts[$id] ?? null;
            if ($a === null || ! isset($am[$id]) || (string) $am[$id]['chart_account_id'] !== (string) $row['chart_account_id']
                || $a['currency'] !== $row['currency_snapshot'] || $a['currency'] !== $r['accounting_currency']
                || ! SignedDecimalAmount::fromAggregateString($a['balance'])->equals(SignedDecimalAmount::fromAggregateString($row['stored_balance_snapshot']))) {
                $this->stale('Operational account or mapping no longer matches reviewed evidence.');
            }
            if ($row['rationale'] !== null && ! $this->nonblank($row['rationale'])) {
                $this->invalid('Account rationale must be nonblank when supplied.');
            }
            $this->chart($charts, $ech, $row['chart_account_id'], 'asset');
            $requiredCharts[] = $row['chart_account_id'];
        }
        foreach ($ec as $id => $row) {
            $c = $categories[$id] ?? null;
            $m = $cm[$id] ?? null;
            if ($c === null || $m === null || $c['type'] !== $row['category_type_snapshot']
                || ! in_array($c['type'], ['income', 'expense'], true)
                || (string) $m['chart_account_id'] !== (string) $row['chart_account_id']
                || $m['cash_flow_activity'] !== $row['cash_flow_activity'] || $m['reviewed_by'] === null || $m['reviewed_at'] === null
                || ! in_array($row['cash_flow_activity'], ['operating', 'investing', 'financing'], true)) {
                $this->stale('Operational category classification or mapping is stale.');
            }
            $this->chart($charts, $ech, $row['chart_account_id'], $c['type'] === 'income' ? 'revenue' : 'expense');
            $requiredCharts[] = $row['chart_account_id'];
        }
        $this->sameSet(array_unique($requiredCharts), array_keys($ech), 'reviewed chart targets');
        $journals = $this->index($src['journal_entries'], 'id');
        $lines = $this->index($src['journal_lines'], 'id');
        $byJournal = [];
        $membership = [];
        foreach ($lines as $line) {
            $journal = $journals[$line['journal_entry_id']] ?? null;
            if ($journal === null) {
                $this->invalid('Line source is unavailable.');
            }
            $byJournal[$journal['id']][] = $line;
            if ($journal['status'] === 'posted' && isset($ech[$line['chart_account_id']])) {
                $membership[] = $journal['id'];
            }
        }
        $ej = $this->index($e['journals'], 'journal_entry_id');
        $this->sameSet(array_unique($membership), array_keys($ej), 'complete posted journal membership');
        foreach ($ej as $id => $row) {
            $journal = $journals[$id] ?? null;
            if ($journal === null || $journal['status'] !== 'posted' || $journal['currency'] !== $r['accounting_currency']
                || $journal['posted_at'] === null || ! $this->date($journal['entry_date'])) {
                $this->invalid('Evidence requires owned posted same-currency journals.');
            }
            $this->journal($journal, $byJournal[$id] ?? [], $charts, $src);
        }
        $this->attributions($e, $lines, $ej, $ea, $ech);
        $this->history($src, $accounts, $categories, $ea, $ec, $am, $cm, $journals, $byJournal, $e, $r, $backfill);
    }

    private function chart(array $charts, array $evidence, int|string $id, string $type): void
    {
        $c = $charts[$id] ?? null;
        $e = $evidence[$id] ?? null;
        if ($c === null || $e === null || ! $c['is_active'] || ! $e['is_active_snapshot'] || $c['type'] !== $type
            || $c['type'] !== $e['type_snapshot'] || $c['cash_role'] === null || $c['cash_role'] !== $e['cash_role_snapshot']
            || ! in_array($c['cash_role'], ['non_cash', 'cash', 'cash_equivalent'], true)
            || ($type !== 'asset' && $c['cash_role'] !== 'non_cash')) {
            $this->stale('Chart target activity, type or reviewed cash role changed.');
        }
    }

    private function journal(array $journal, array $lines, array $charts, array $src): void
    {
        $debit = DecimalAmount::fromAggregateString('0');
        $credit = DecimalAmount::fromAggregateString('0');
        $facts = [];
        foreach ($lines as $line) {
            $d = DecimalAmount::fromString($line['debit']);
            $c = DecimalAmount::fromString($line['credit']);
            if ($d->isPositive() === $c->isPositive() || ! isset($charts[$line['chart_account_id']])) {
                $this->invalid('Invalid journal line or chart source.');
            }
            $debit = $debit->add($d);
            $credit = $credit->add($c);
            $role = $charts[$line['chart_account_id']]['cash_role'];
            $facts[$line['id']] = ['cash' => in_array($role, ['cash', 'cash_equivalent'], true), 'role' => $role,
                'side' => $d->isPositive() ? 'debit' : 'credit', 'amount' => $d->isPositive() ? $d : $c];
        }
        if (count($lines) < 2 || ! $debit->equals($credit)) {
            $this->invalid('Journal is not individually balanced.');
        }
        $openings = array_values(array_filter($src['opening_balance_batches'], fn ($b) => (string) $b['journal_entry_id'] === (string) $journal['id']));
        $closes = array_values(array_filter($src['fiscal_year_closes'], fn ($c) => (string) $c['journal_entry_id'] === (string) $journal['id']));
        if (count($openings) > 1 || count($closes) > 1 || ($openings !== [] && $closes !== [])
            || (($openings !== [] || $closes !== []) && $journal['reversal_of_id'] !== null)) {
            $this->invalid('Ambiguous special journal provenance.');
        }
        foreach ($openings as $batch) {
            if ($batch['status'] !== 'posted' || $batch['currency'] !== $journal['currency'] || $batch['opening_date'] !== $journal['entry_date']
                || $batch['posted_at'] === null) {
                $this->invalid('Opening journal provenance is inconsistent.');
            }
            $openingLines = array_filter($src['opening_balance_lines'], fn ($l) => (string) $l['batch_id'] === (string) $batch['id']);
            $actual = array_map(fn ($l) => [(string) $l['chart_account_id'], $l['debit'], $l['credit']], $lines);
            $expected = array_map(fn ($l) => [(string) $l['chart_account_id'], $l['debit'], $l['credit']], array_values($openingLines));
            $actual = array_map(fn ($row) => implode("\0", $row), $actual);
            $expected = array_map(fn ($row) => implode("\0", $row), $expected);
            sort($actual, SORT_STRING);
            sort($expected, SORT_STRING);
            if ($actual !== $expected) {
                $this->invalid('Opening batch lines do not match immutable journal lines.');
            }
        }
        foreach ($closes as $close) {
            if ($close['currency'] !== $journal['currency'] || $close['end_date'] !== $journal['entry_date']
                || $close['closed_at'] === null || ! isset($charts[$close['retained_earnings_account_id']])
                || $charts[$close['retained_earnings_account_id']]['type'] !== 'equity') {
                $this->invalid('Fiscal close provenance is inconsistent.');
            }
            foreach ($lines as $line) {
                if ((string) $line['chart_account_id'] !== (string) $close['retained_earnings_account_id']
                    && ! in_array($charts[$line['chart_account_id']]['type'], ['revenue', 'expense'], true)) {
                    $this->invalid('Fiscal close uses an invalid chart counterpart.');
                }
            }
            $this->fiscalClose($close, $journal, $lines, $charts, $src);
        }
        $openingReversal = false;
        if ($journal['reversal_of_id'] !== null) {
            $originals = $this->index($src['journal_entries'], 'id');
            $original = $originals[$journal['reversal_of_id']] ?? null;
            if ($original === null || $original['status'] !== 'posted' || $original['reversal_of_id'] !== null
                || $original['currency'] !== $journal['currency']) {
                $this->invalid('Reversal source is unavailable or inconsistent.');
            }
            foreach ($src['fiscal_year_closes'] as $close) {
                if ((string) $close['journal_entry_id'] === (string) $original['id']) {
                    $this->invalid('Fiscal-close journals cannot have generic reversal provenance.');
                }
            }
            $originalLines = array_filter($src['journal_lines'], fn ($l) => (string) $l['journal_entry_id'] === (string) $original['id']);
            $actual = array_map(fn ($l) => [(string) $l['chart_account_id'], (string) $l['line_number'], $l['debit'], $l['credit']], $lines);
            $expected = array_map(fn ($l) => [(string) $l['chart_account_id'], (string) $l['line_number'], $l['credit'], $l['debit']], array_values($originalLines));
            $actual = array_map(fn ($row) => implode("\0", $row), $actual);
            $expected = array_map(fn ($row) => implode("\0", $row), $expected);
            sort($actual, SORT_STRING);
            sort($expected, SORT_STRING);
            if ($actual !== $expected) {
                $this->invalid('Reversal lines are not the exact inverse of the original.');
            }
            $openingReversal = array_filter($src['opening_balance_batches'], fn ($b) => (string) $b['journal_entry_id'] === (string) $original['id']) !== [];
        }
        // Opening journals follow Phase 2's special reporting rules, not ordinary
        // operating allocations. Fiscal-close journals must be noncash.
        $allocations = array_filter($src['journal_line_allocations'], fn ($a) => (string) $a['journal_entry_id'] === (string) $journal['id']);
        $completions = array_filter($src['cash_flow_journal_completions'], fn ($c) => (string) $c['journal_entry_id'] === (string) $journal['id']);
        if ($openings !== [] || $closes !== [] || $openingReversal) {
            if ($allocations !== [] || $completions !== []) {
                $this->invalid('Special journal cannot use ordinary cash-flow completion.');
            }

            return;
        }
        $covered = [];
        foreach ($allocations as $a) {
            $d = $facts[$a['debit_line_id']] ?? null;
            $c = $facts[$a['credit_line_id']] ?? null;
            if ($d === null || $c === null || $d['side'] !== 'debit' || $c['side'] !== 'credit'
                || $d['role'] === null || $c['role'] === null || (! $d['cash'] && ! $c['cash'])
                || (($d['cash'] xor $c['cash']) ? ! in_array($a['category'], ['operating', 'investing', 'financing'], true) : $a['category'] !== null)) {
                $this->invalid('Invalid cash-flow allocation.');
            }
            $amount = DecimalAmount::fromString($a['amount']);
            if (! $amount->isPositive()) {
                $this->invalid('Allocation must be positive.');
            }
            foreach ([$a['debit_line_id'], $a['credit_line_id']] as $id) {
                $covered[$id] = ($covered[$id] ?? DecimalAmount::fromAggregateString('0'))->add($amount);
                if ($covered[$id]->compare($facts[$id]['amount']) > 0) {
                    $this->invalid('Cash-flow allocation overcovers a line.');
                }
            }
        }
        $cash = array_filter($facts, fn ($f) => $f['cash']);
        if ($cash !== [] && count($completions) !== 1) {
            $this->invalid('Cash journal requires immutable completion.');
        }
        if ($cash === [] && ($allocations !== [] || $completions !== [])) {
            $this->invalid('Noncash journal has unexpected cash-flow evidence.');
        }
        if ($cash !== []) {
            foreach ($facts as $id => $fact) {
                if ($fact['role'] === null || ($fact['cash'] && (! isset($covered[$id]) || ! $covered[$id]->equals($fact['amount'])))) {
                    $this->invalid('Cash journal has unreviewed roles or incomplete allocation.');
                }
            }
        }
        if ($journal['reversal_of_id'] !== null) {
            $byNumber = [];
            foreach ($lines as $line) {
                $byNumber[$line['line_number']] = $line['id'];
            }
            $originalById = $this->index(array_values(array_filter($src['journal_lines'],
                fn ($l) => (string) $l['journal_entry_id'] === (string) $journal['reversal_of_id'])), 'id');
            $inherited = [];
            foreach ($src['journal_line_allocations'] as $allocation) {
                if ((string) $allocation['journal_entry_id'] !== (string) $journal['reversal_of_id']) {
                    continue;
                }
                $originalDebit = $originalById[$allocation['debit_line_id']] ?? null;
                $originalCredit = $originalById[$allocation['credit_line_id']] ?? null;
                if ($originalDebit === null || $originalCredit === null) {
                    $this->invalid('Reversal allocation original source is unavailable.');
                }
                $inherited[] = implode("\0", [(string) $byNumber[$originalCredit['line_number']],
                    (string) $byNumber[$originalDebit['line_number']], $allocation['amount'], $allocation['category'] === null ? 'NULL' : $allocation['category']]);
            }
            $actual = array_map(fn ($a) => implode("\0", [(string) $a['debit_line_id'], (string) $a['credit_line_id'],
                $a['amount'], $a['category'] === null ? 'NULL' : $a['category']]), array_values($allocations));
            sort($inherited, SORT_STRING);
            sort($actual, SORT_STRING);
            if ($actual !== $inherited) {
                $this->invalid('Reversal cash-flow classification must inherit the original allocations.');
            }
        }
    }

    private function fiscalClose(array $close, array $closingJournal, array $closingLines, array $charts, array $src): void
    {
        if (! $this->date($close['start_date']) || ! $this->date($close['end_date']) || $close['start_date'] > $close['end_date']) {
            $this->invalid('Invalid fiscal-close date range.');
        }
        $headers = $this->index($src['journal_entries'], 'id');
        $byJournal = [];
        foreach ($src['journal_lines'] as $line) {
            $byJournal[$line['journal_entry_id']][] = $line;
        }
        $nominal = [];
        foreach ($headers as $header) {
            if ($header['status'] !== 'posted' || (string) $header['id'] === (string) $closingJournal['id']
                || $header['entry_date'] < $close['start_date'] || $header['entry_date'] > $close['end_date']) {
                continue;
            }
            if ($header['currency'] !== $close['currency'] || $header['posted_at'] === null
                || $this->timestamp($header['posted_at'], true) > $this->timestamp($close['closed_at'], true)) {
                $this->invalid('Fiscal-close source history has incompatible currency or posting chronology.');
            }
            $debit = DecimalAmount::fromAggregateString('0');
            $credit = DecimalAmount::fromAggregateString('0');
            foreach ($byJournal[$header['id']] ?? [] as $line) {
                $d = DecimalAmount::fromString($line['debit']);
                $c = DecimalAmount::fromString($line['credit']);
                if ($d->isPositive() === $c->isPositive() || ! isset($charts[$line['chart_account_id']])) {
                    $this->invalid('Fiscal close has invalid source lines.');
                }
                $debit = $debit->add($d);
                $credit = $credit->add($c);
                if (in_array($charts[$line['chart_account_id']]['type'], ['revenue', 'expense'], true)) {
                    $nominal[$line['chart_account_id']] = ($nominal[$line['chart_account_id']] ?? SignedDecimalAmount::zero())
                        ->add(SignedDecimalAmount::difference($line['debit'], $line['credit']));
                }
            }
            if (count($byJournal[$header['id']] ?? []) < 2 || ! $debit->equals($credit)) {
                $this->invalid('Fiscal-close source journal is unbalanced.');
            }
        }
        $expected = [];
        $retained = SignedDecimalAmount::zero();
        foreach ($nominal as $id => $movement) {
            if (! $movement->equals(SignedDecimalAmount::zero())) {
                $expected[(string) $id] = $movement->negate()->toDecimal();
            }
            $retained = $retained->add($movement);
        }
        if (! $retained->equals(SignedDecimalAmount::zero())) {
            $expected[(string) $close['retained_earnings_account_id']] = $retained->toDecimal();
        }
        $actual = [];
        foreach ($closingLines as $line) {
            if (isset($actual[(string) $line['chart_account_id']])) {
                $this->invalid('Fiscal close duplicates a chart target.');
            }
            $actual[(string) $line['chart_account_id']] = SignedDecimalAmount::difference($line['debit'], $line['credit'])->toDecimal();
        }
        ksort($actual, SORT_STRING);
        ksort($expected, SORT_STRING);
        if ($actual !== $expected) {
            $this->invalid('Fiscal-close journal does not clear the exact posted nominal history.');
        }
    }

    private function attributions(array $e, array $lines, array $journals, array $accounts, array $charts): void
    {
        $coverage = [];
        $balances = [];
        $keys = [];
        foreach ($e['attributions'] as $a) {
            $line = $lines[$a['journal_line_id']] ?? null;
            if ($line === null || ! isset($journals[$a['journal_entry_id']])
                || (string) $line['journal_entry_id'] !== (string) $a['journal_entry_id']
                || ($charts[$line['chart_account_id']]['type_snapshot'] ?? null) !== 'asset') {
                $this->invalid('Attribution has an invalid represented asset source.');
            }
            $amount = DecimalAmount::fromString($a['amount']);
            if (! $amount->isPositive()) {
                $this->invalid('Attribution must be positive.');
            }
            if ($a['target_kind'] === 'account') {
                $target = $accounts[$a['account_id']] ?? null;
                if ($target === null || (string) $target['chart_account_id'] !== (string) $line['chart_account_id']
                    || ! in_array($a['purpose'], ['opening_balance', 'existing_history'], true)
                    || ($a['rationale'] !== null && ! $this->nonblank($a['rationale']))) {
                    $this->invalid('Attribution account/purpose/rationale is invalid.');
                }
                $key = $a['account_id'].':'.$a['purpose'];
                $movement = SignedDecimalAmount::fromAggregateString($amount->toDecimal());
                if (DecimalAmount::fromString($line['credit'])->isPositive()) {
                    $movement = $movement->negate();
                }
                $balances[$a['account_id']] = ($balances[$a['account_id']] ?? SignedDecimalAmount::zero())->add($movement);
            } elseif ($a['target_kind'] === 'excluded' && $a['account_id'] === null && $a['purpose'] === null && $this->nonblank($a['rationale'])) {
                $key = 'excluded';
            } else {
                $this->invalid('Excluded attribution needs explicit nonblank rationale and no account/purpose.');
            }
            $unique = $a['journal_line_id'].':'.$key;
            if (isset($keys[$unique])) {
                $this->invalid('Duplicate attribution target.');
            }
            $keys[$unique] = true;
            $coverage[$line['id']] = ($coverage[$line['id']] ?? DecimalAmount::fromAggregateString('0'))->add($amount);
        }
        foreach ($lines as $line) {
            if (! isset($journals[$line['journal_entry_id']]) || ($charts[$line['chart_account_id']]['type_snapshot'] ?? null) !== 'asset') {
                continue;
            }
            $magnitude = DecimalAmount::fromString($line['debit'])->add(DecimalAmount::fromString($line['credit']));
            if (! isset($coverage[$line['id']]) || ! $coverage[$line['id']]->equals($magnitude)) {
                $this->invalid('Asset-line attribution must cover the entire magnitude exactly.');
            }
        }
        foreach ($accounts as $id => $account) {
            if (! ($balances[$id] ?? SignedDecimalAmount::zero())->equals(SignedDecimalAmount::fromAggregateString($account['stored_balance_snapshot']))) {
                $this->conflict('Per-account attribution does not reconcile to the stored balance.', AccountingConflictReason::ReconciliationLedgerMismatch);
            }
        }
    }

    private function history(array $src, array $accounts, array $categories, array $ea, array $ec, array $am, array $cm,
        array $journals, array $lines, array $e, array $review, bool $backfill): void
    {
        $links = $this->index($src['operational_transaction_journals'], 'transaction_id');
        $transactions = $this->index($src['transactions'], 'id');
        $historicalJournals = [];
        foreach ($transactions as $t) {
            if ($this->idGreater($t['id'], $review['transaction_high_water_id'])) {
                $this->conflict('Owner activity exists above the boundary; explicit recapture is required.', AccountingConflictReason::ReconciliationBoundaryStale);
            }
            if (! $backfill) {
                if (isset($links[$t['id']])) {
                    $this->invalid('Balance-only review contains contradictory historical backfill links.');
                }

                continue;
            }
            // Every owner income/expense row is historical coverage: omission from
            // explicit scope cannot exempt it from the backfill contract.
            if (! in_array($t['type'], ['income', 'expense'], true) || ! isset($accounts[$t['account_id']], $categories[$t['category_id']],
                $ea[$t['account_id']], $ec[$t['category_id']], $am[$t['account_id']], $cm[$t['category_id']], $links[$t['id']])) {
                $this->invalid('Historical transaction lacks owned scoped parents, mappings or link.');
            }
            $link = $links[$t['id']];
            $journal = $journals[$link['journal_entry_id']] ?? null;
            if ($journal === null || $journal['status'] !== 'posted' || $journal['currency'] !== $review['accounting_currency']
                || $journal['entry_date'] !== $t['date'] || $journal['reversal_of_id'] !== null
                || (string) $link['account_id'] !== (string) $t['account_id'] || (string) $link['category_id'] !== (string) $t['category_id']
                || isset($historicalJournals[$journal['id']]) || $categories[$t['category_id']]['type'] !== $t['type']) {
                $this->invalid('Historical operational journal link is inconsistent.');
            }
            $historicalJournals[$journal['id']] = true;
            foreach (['opening_balance_batches', 'fiscal_year_closes'] as $table) {
                foreach ($src[$table] as $special) {
                    if ((string) $special['journal_entry_id'] === (string) $journal['id']) {
                        $this->invalid('Historical transaction cannot link special accounting history.');
                    }
                }
            }
            foreach ($journals as $candidate) {
                if ((string) $candidate['reversal_of_id'] === (string) $journal['id']) {
                    $this->invalid('Historical transaction journal has reversal history.');
                }
            }
            $jl = $lines[$journal['id']] ?? [];
            if (count($jl) !== 2) {
                $this->invalid('Historical transaction requires exactly two journal lines.');
            }
            $asset = $am[$t['account_id']]['chart_account_id'];
            $counterpart = $cm[$t['category_id']]['chart_account_id'];
            $assetLine = null;
            foreach ($jl as $line) {
                $isAsset = (string) $line['chart_account_id'] === (string) $asset;
                $target = $isAsset ? $asset : $counterpart;
                $debit = ($t['type'] === 'income') === $isAsset;
                if ((string) $line['chart_account_id'] !== (string) $target
                    || ! DecimalAmount::fromString($line[$debit ? 'debit' : 'credit'])->equals(DecimalAmount::fromString($t['amount']))
                    || ! DecimalAmount::fromString($line[$debit ? 'credit' : 'debit'])->isZero()) {
                    $this->invalid('Historical journal direction/amount/targets disagree.');
                }
                if ($isAsset) {
                    $assetLine = $line;
                }
            }
            if ($assetLine === null) {
                $this->invalid('Historical journal has no operational asset line.');
            }
            $attrs = array_values(array_filter($e['attributions'], fn ($a) => (string) $a['journal_line_id'] === (string) $assetLine['id']));
            if (count($attrs) !== 1 || $attrs[0]['target_kind'] !== 'account' || $attrs[0]['purpose'] !== 'existing_history'
                || (string) $attrs[0]['account_id'] !== (string) $t['account_id']
                || ! DecimalAmount::fromString($attrs[0]['amount'])->equals(DecimalAmount::fromString($t['amount']))) {
                $this->invalid('Backfill asset line must be fully attributed to its actual operational account.');
            }
            foreach ($src['journal_line_allocations'] as $allocation) {
                if ((string) $allocation['journal_entry_id'] === (string) $journal['id']
                    && $allocation['category'] !== $cm[$t['category_id']]['cash_flow_activity']) {
                    $this->invalid('Historical cash allocation activity disagrees with category review.');
                }
            }
        }
        foreach ($links as $link) {
            if (! isset($transactions[$link['transaction_id']])) {
                $this->invalid('Operational link source transaction is unavailable.');
            }
        }
    }

    private function index(array $rows, string $key): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (isset($result[$row[$key]])) {
                $this->invalid('Duplicate source/evidence identity.');
            }
            $result[$row[$key]] = $row;
        }

        return $result;
    }

    private function sameSet(array $left, array $right, string $label): void
    {
        $left = array_map('strval', $left);
        $right = array_map('strval', $right);
        sort($left, SORT_STRING);
        sort($right, SORT_STRING);
        if ($left !== $right) {
            $this->invalid('Missing or extra evidence: '.$label);
        }
    }

    private function nonblank(?string $value): bool
    {
        return $value !== null && preg_match('/[^\s]/u', $value) === 1;
    }

    private function timestamp(string $value, bool $microseconds): string
    {
        if (! preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/', $value)) {
            $this->invalid('Invalid normalized evidence timestamp.');
        }
        $parts = explode('.', $value, 2);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $parts[0], new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $parts[0]
            || (! $microseconds && trim($parts[1] ?? '', '0') !== '')) {
            $this->invalid('Invalid evidence date.');
        }

        return $microseconds ? $parts[0].'.'.str_pad($parts[1] ?? '', 6, '0') : $parts[0];
    }

    private function date(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function idGreater(int|string $left, int|string $right): bool
    {
        $left = ltrim((string) $left, '0') ?: '0';
        $right = ltrim((string) $right, '0') ?: '0';

        return strlen($left) !== strlen($right) ? strlen($left) > strlen($right) : strcmp($left, $right) > 0;
    }

    private function invalid(string $message): never
    {
        $this->conflict($message, AccountingConflictReason::ReconciliationEvidenceInvalid);
    }

    private function stale(string $message): never
    {
        $this->conflict($message, AccountingConflictReason::ReconciliationSnapshotStale);
    }

    private function conflict(string $message, AccountingConflictReason $reason): never
    {
        throw new AccountingConflict($message, reason: $reason);
    }
}
