<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class OperationalReconciliationEvidenceProtectionTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function rejects(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Evidence guard accepted an invalid operation.');
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0]);
            $this->assertSame(1644, $exception->errorInfo[1]);
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function review(int $owner, array $overrides = []): int
    {
        return DB::table('operational_reconciliation_reviews')->insertGetId(array_replace([
            'user_id' => $owner, 'reference' => (string) Str::uuid(),
            'historical_transaction_strategy' => 'historical_backfill', 'accounting_currency' => 'SAR',
            'transaction_high_water_id' => 0, 'scope_reviewed_at' => '2026-10-12 00:00:00',
            'created_at' => '2026-10-12 00:00:00',
        ], $overrides));
    }

    private function seal(int $review, int $owner): void
    {
        // Test the DB protocol only; this is deliberately not a completion action.
        DB::table('operational_reconciliation_reviews')->where('id', $review)->update([
            'reviewed_by' => $owner, 'completed_at' => '2026-10-13 00:00:00.123456',
        ]);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create()->id;
        $review = $this->review($owner);
        $charts = [];
        foreach (['asset', 'revenue', 'expense', 'secondAsset'] as $number => $key) {
            $type = $key === 'secondAsset' ? 'asset' : $key;
            $charts[$key] = DB::table('chart_of_accounts')->insertGetId([
                'user_id' => $owner, 'code' => (string) (1000 + $number), 'name' => $key,
                'type' => $type, 'cash_role' => 'non_cash',
            ]);
            DB::table('operational_reconciliation_charts')->insert([
                'review_id' => $review, 'user_id' => $owner, 'chart_account_id' => $charts[$key],
                'type_snapshot' => $type, 'cash_role_snapshot' => 'non_cash', 'is_active_snapshot' => 1,
            ]);
        }
        $account = DB::table('accounts')->insertGetId([
            'user_id' => $owner, 'name' => 'Selected', 'type' => 'bank', 'balance' => '-12.34', 'currency' => 'SAR',
        ]);
        $accountRow = ['review_id' => $review, 'user_id' => $owner, 'account_id' => $account,
            'chart_account_id' => $charts['asset'], 'stored_balance_snapshot' => '-12.34', 'currency_snapshot' => 'SAR'];
        DB::table('operational_reconciliation_accounts')->insert($accountRow);
        $category = DB::table('categories')->insertGetId(['user_id' => $owner, 'name' => 'Income', 'type' => 'income']);
        $categoryRow = ['review_id' => $review, 'user_id' => $owner, 'category_id' => $category,
            'category_type_snapshot' => 'income', 'chart_account_id' => $charts['revenue'], 'cash_flow_activity' => 'operating'];
        DB::table('operational_reconciliation_categories')->insert($categoryRow);
        $journal = DB::table('journal_entries')->insertGetId([
            'user_id' => $owner, 'entry_date' => '2026-10-12', 'currency' => 'SAR',
        ]);
        $line = DB::table('journal_lines')->insertGetId([
            'user_id' => $owner, 'journal_entry_id' => $journal, 'chart_account_id' => $charts['asset'],
            'line_number' => 1, 'debit' => '100.00', 'credit' => '0.00',
        ]);
        $revenueLine = DB::table('journal_lines')->insertGetId([
            'user_id' => $owner, 'journal_entry_id' => $journal, 'chart_account_id' => $charts['revenue'],
            'line_number' => 2, 'debit' => '0.00', 'credit' => '100.00',
        ]);
        DB::table('journal_entries')->where('id', $journal)->update([
            'status' => 'posted', 'posted_at' => '2026-10-12 00:00:00.123456',
        ]);
        $journalRow = ['review_id' => $review, 'user_id' => $owner, 'journal_entry_id' => $journal];
        DB::table('operational_reconciliation_journals')->insert($journalRow);
        $attributionRow = array_merge($journalRow, ['journal_line_id' => $line, 'target_kind' => 'account',
            'account_id' => $account, 'purpose' => 'existing_history', 'amount' => '40.00']);
        $attribution = DB::table('operational_reconciliation_attributions')->insertGetId($attributionRow);
        $rows = ['charts' => ['review_id' => $review, 'user_id' => $owner, 'chart_account_id' => $charts['asset'],
            'type_snapshot' => 'asset', 'cash_role_snapshot' => 'non_cash', 'is_active_snapshot' => 1],
            'accounts' => $accountRow, 'categories' => $categoryRow, 'journals' => $journalRow,
            'attributions' => array_merge($attributionRow, ['id' => $attribution])];

        return compact('owner', 'review', 'charts', 'account', 'category', 'journal', 'line', 'revenueLine', 'attribution', 'rows');
    }

    public function test_header_insert_requires_unsealed_identity_and_completed_same_owner_predecessor(): void
    {
        $owner = User::factory()->create()->id;
        $foreign = User::factory()->create()->id;
        foreach ([['reviewed_by' => $owner], ['completed_at' => '2026-10-13'],
            ['reviewed_by' => $owner, 'completed_at' => '2026-10-13']] as $fields) {
            $this->rejects(fn () => $this->review($owner, $fields), 'inserted unsealed');
        }
        $draft = $this->review($owner);
        $other = $this->review($foreign);
        $this->seal($other, $foreign);
        foreach ([$draft, $other, PHP_INT_MAX] as $predecessor) {
            $this->rejects(fn () => $this->review($owner, ['supersedes_review_id' => $predecessor]), 'owned completed');
        }
        $this->rejects(fn () => $this->review($owner, ['id' => 987654321, 'supersedes_review_id' => 987654321]), 'itself');
        $this->seal($draft, $owner);
        $next = $this->review($owner, ['supersedes_review_id' => $draft]);
        $this->assertDatabaseHas('operational_reconciliation_reviews', ['id' => $next, 'supersedes_review_id' => $draft,
            'reviewed_by' => null, 'completed_at' => null]);
    }

    public function test_draft_header_edits_and_sealing_only_two_fields(): void
    {
        $owner = User::factory()->create()->id;
        $review = $this->review($owner);
        $query = DB::table('operational_reconciliation_reviews')->where('id', $review);
        $query->update(['historical_transaction_strategy' => 'balance_only', 'accounting_currency' => 'USD',
            'transaction_high_water_id' => 5, 'scope_reviewed_at' => '2026-10-13 00:00:00',
            'balance_only_disclosure_code' => 'balance_only_legacy_history_v1', 'balance_only_disclosure_text' => 'Reviewed legacy exclusion.']);
        $this->assertDatabaseHas('operational_reconciliation_reviews', ['id' => $review, 'transaction_high_water_id' => 5]);
        $this->rejects(fn () => $query->update(['reviewed_by' => $owner]), 'together');
        $this->rejects(fn () => $query->update(['completed_at' => '2026-10-13']), 'together');
        $this->rejects(fn () => $query->update(['reviewed_by' => $owner, 'completed_at' => '2026-10-13',
            'transaction_high_water_id' => 6]), 'only reviewer');
        $this->seal($review, $owner);
        $this->rejects(fn () => $query->update(['transaction_high_water_id' => 5]), 'cannot be updated');
        $this->rejects(fn () => $query->update(['completed_at' => null, 'reviewed_by' => null]), 'cannot be updated');
        $this->rejects(fn () => $query->delete(), 'cannot be deleted');
    }

    public static function headerIdentities(): array
    {
        return ['id' => ['id', 987654321], 'owner' => ['user_id', 987654321],
            'reference' => ['reference', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'],
            'case-only reference' => ['reference', 'AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA'],
            'creation' => ['created_at', '2026-10-11 00:00:00'], 'predecessor' => ['supersedes_review_id', 987654321]];
    }

    #[DataProvider('headerIdentities')]
    public function test_header_identity_is_immutable(string $field, mixed $value): void
    {
        $owner = User::factory()->create()->id;
        $review = $this->review($owner, ['reference' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']);
        $this->rejects(fn () => DB::table('operational_reconciliation_reviews')->where('id', $review)->update([$field => $value]), 'identity');
    }

    public static function childMutations(): array
    {
        $cases = [];
        foreach (['charts', 'accounts', 'categories', 'journals', 'attributions'] as $table) {
            foreach (['insert', 'update', 'delete'] as $operation) {
                $cases[$table.' '.$operation] = [$table, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('childMutations')]
    public function test_sealed_children_reject_every_mutation(string $suffix, string $operation): void
    {
        $fixture = $this->fixture();
        $this->seal($fixture['review'], $fixture['owner']);
        $query = DB::table('operational_reconciliation_'.$suffix);
        $row = $fixture['rows'][$suffix];
        // Even duplicate INSERT and no-op UPDATE must reach the sealed-parent guard.
        $this->rejects(function () use ($query, $row, $operation, $fixture): void {
            if ($operation === 'insert') {
                $query->insert($row);
            } elseif ($operation === 'update') {
                $query->where('review_id', $fixture['review'])->update(['user_id' => $fixture['owner']]);
            } else {
                $query->where('review_id', $fixture['review'])->delete();
            }
        }, 'Sealed reconciliation evidence');
    }

    public function test_unsealed_children_can_edit_and_delete_in_dependency_order(): void
    {
        $f = $this->fixture();
        DB::table('operational_reconciliation_charts')->where('review_id', $f['review'])
            ->where('chart_account_id', $f['charts']['asset'])->update(['cash_role_snapshot' => 'cash', 'is_active_snapshot' => 0]);
        DB::table('operational_reconciliation_accounts')->where('review_id', $f['review'])->update([
            'stored_balance_snapshot' => '-1.23', 'currency_snapshot' => 'USD', 'rationale' => 'Draft correction.']);
        DB::table('operational_reconciliation_categories')->where('review_id', $f['review'])->update([
            'category_type_snapshot' => 'expense', 'chart_account_id' => $f['charts']['expense'], 'cash_flow_activity' => 'investing']);
        DB::table('operational_reconciliation_attributions')->where('id', $f['attribution'])->update([
            'target_kind' => 'excluded', 'account_id' => null, 'purpose' => null, 'amount' => '100.00', 'rationale' => 'Explicit exclusion.']);
        $this->assertDatabaseHas('operational_reconciliation_attributions', ['id' => $f['attribution'], 'target_key' => 'excluded']);
        $this->rejects(fn () => DB::table('operational_reconciliation_journals')->where('review_id', $f['review'])
            ->update(['user_id' => $f['owner']]), 'cannot be updated');
        foreach (['attributions', 'journals', 'categories', 'accounts', 'charts'] as $suffix) {
            $this->assertGreaterThan(0, DB::table('operational_reconciliation_'.$suffix)->where('review_id', $f['review'])->delete());
        }
        $this->assertSame(1, DB::table('operational_reconciliation_reviews')->where('id', $f['review'])->delete());
    }

    public function test_child_owner_review_and_source_identities_cannot_be_reassigned(): void
    {
        $f = $this->fixture();
        $identities = ['charts' => ['chart_account_id'], 'accounts' => ['account_id'],
            'categories' => ['category_id'], 'journals' => ['journal_entry_id'],
            'attributions' => ['id', 'journal_entry_id', 'journal_line_id']];
        foreach ($identities as $suffix => $fields) {
            foreach (array_merge(['review_id', 'user_id'], $fields) as $field) {
                $this->rejects(fn () => DB::table('operational_reconciliation_'.$suffix)->where('review_id', $f['review'])
                    ->update([$field => 987654321]), 'identity');
            }
        }
        $this->rejects(fn () => DB::table('operational_reconciliation_charts')->insert(array_replace($f['rows']['charts'],
            ['review_id' => 987654321])), 'owned review parent');
        $other = User::factory()->create()->id;
        $this->rejects(fn () => DB::table('operational_reconciliation_charts')->insert(array_replace($f['rows']['charts'],
            ['user_id' => $other])), 'owned operational or chart source');
    }

    public function test_account_category_and_reverse_chart_edits_preserve_target_eligibility(): void
    {
        $f = $this->fixture();
        $account = DB::table('operational_reconciliation_accounts')->where('review_id', $f['review']);
        $category = DB::table('operational_reconciliation_categories')->where('review_id', $f['review']);
        $this->rejects(fn () => $account->update(['chart_account_id' => $f['charts']['revenue']]), 'asset chart');
        $this->rejects(fn () => $category->update(['chart_account_id' => $f['charts']['asset']]), 'compatible');
        $this->rejects(fn () => $category->update(['category_type_snapshot' => 'expense']), 'compatible');
        $this->rejects(fn () => DB::table('operational_reconciliation_charts')->where('review_id', $f['review'])
            ->where('chart_account_id', $f['charts']['asset'])->update(['type_snapshot' => 'liability']), 'account evidence');
        $this->rejects(fn () => DB::table('operational_reconciliation_charts')->where('review_id', $f['review'])
            ->where('chart_account_id', $f['charts']['revenue'])->update(['type_snapshot' => 'expense']), 'category evidence');
        $this->rejects(fn () => $account->update(['chart_account_id' => $f['charts']['secondAsset']]), 'attribution evidence');
        DB::table('operational_reconciliation_attributions')->where('id', $f['attribution'])->delete();
        $this->assertSame(1, $account->update(['chart_account_id' => $f['charts']['secondAsset']]));
        $account->delete();
        $category->delete();
        $this->rejects(fn () => DB::table('operational_reconciliation_accounts')->insert(array_replace($f['rows']['accounts'],
            ['chart_account_id' => $f['charts']['revenue']])), 'asset chart');
        $this->rejects(fn () => DB::table('operational_reconciliation_categories')->insert(array_replace($f['rows']['categories'],
            ['chart_account_id' => $f['charts']['asset']])), 'compatible');
        $this->assertSame(1, DB::table('operational_reconciliation_charts')->where('review_id', $f['review'])
            ->where('chart_account_id', $f['charts']['secondAsset'])->update(['type_snapshot' => 'liability']));
    }

    public function test_membership_requires_posted_same_owner_journal(): void
    {
        $f = $this->fixture();
        $draft = DB::table('journal_entries')->insertGetId(['user_id' => $f['owner'], 'entry_date' => '2026-10-12', 'currency' => 'SAR']);
        $this->rejects(fn () => DB::table('operational_reconciliation_journals')->insert(array_replace($f['rows']['journals'],
            ['journal_entry_id' => $draft])), 'owned posted journal');
        $foreign = $this->fixture();
        $this->rejects(fn () => DB::table('operational_reconciliation_journals')->insert(array_replace($f['rows']['journals'],
            ['journal_entry_id' => $foreign['journal']])), 'owned posted journal');
        $this->assertDatabaseHas('operational_reconciliation_journals', $f['rows']['journals']);
    }

    public function test_attribution_source_membership_line_type_magnitude_and_target_checks(): void
    {
        $f = $this->fixture();
        $row = $f['rows']['attributions'];
        unset($row['id']);
        $row['purpose'] = 'opening_balance';
        $this->rejects(fn () => DB::table('operational_reconciliation_attributions')->insert(array_replace($row,
            ['journal_entry_id' => 987654321])), 'posted journal');
        $this->rejects(fn () => DB::table('operational_reconciliation_attributions')->insert(array_replace($row,
            ['journal_line_id' => 987654321])), 'owned journal');
        $foreign = $this->fixture();
        $this->rejects(fn () => DB::table('operational_reconciliation_attributions')->insert(array_replace($row,
            ['journal_line_id' => $foreign['line']])), 'owned journal');
        $this->rejects(fn () => DB::table('operational_reconciliation_attributions')->insert(array_replace($row,
            ['journal_line_id' => $f['revenueLine']])), 'represented asset line');
        $this->rejects(fn () => DB::table('operational_reconciliation_attributions')->insert(array_replace($row,
            ['amount' => '100.01'])), 'positive source line');
        $secondAccount = DB::table('accounts')->insertGetId(['user_id' => $f['owner'], 'name' => 'Other', 'type' => 'bank', 'currency' => 'SAR']);
        DB::table('operational_reconciliation_accounts')->insert(array_replace($f['rows']['accounts'],
            ['account_id' => $secondAccount, 'chart_account_id' => $f['charts']['secondAsset']]));
        $this->rejects(fn () => DB::table('operational_reconciliation_attributions')->insert(array_replace($row,
            ['account_id' => $secondAccount])), 'source line reviewed chart');
        $this->assertTrue(DB::table('operational_reconciliation_attributions')->insert(array_replace($row, ['amount' => '100.00'])));
        // Aggregates intentionally remain for later reconciliation validation.
        $this->assertDatabaseHas('operational_reconciliation_attributions', ['review_id' => $f['review'],
            'account_id' => $f['account'], 'purpose' => 'opening_balance', 'amount' => '100.00']);
        $this->rejects(fn () => DB::table('operational_reconciliation_attributions')->where('id', $f['attribution'])
            ->update(['amount' => '100.01']), 'positive source line');
        $this->rejects(fn () => DB::table('operational_reconciliation_attributions')->where('id', $f['attribution'])
            ->update(['account_id' => $secondAccount]), 'source line reviewed chart');
    }

    public function test_line_must_match_its_journal_and_credit_asset_magnitude_is_eligible(): void
    {
        $f = $this->fixture();
        $journal = DB::table('journal_entries')->insertGetId([
            'user_id' => $f['owner'], 'entry_date' => '2026-10-12', 'currency' => 'SAR',
        ]);
        DB::table('journal_lines')->insert(['user_id' => $f['owner'], 'journal_entry_id' => $journal,
            'chart_account_id' => $f['charts']['expense'], 'line_number' => 1, 'debit' => '100.00', 'credit' => '0.00']);
        $line = DB::table('journal_lines')->insertGetId(['user_id' => $f['owner'], 'journal_entry_id' => $journal,
            'chart_account_id' => $f['charts']['asset'], 'line_number' => 2, 'debit' => '0.00', 'credit' => '100.00']);
        DB::table('journal_entries')->where('id', $journal)->update(['status' => 'posted', 'posted_at' => '2026-10-12']);
        DB::table('operational_reconciliation_journals')->insert(array_replace($f['rows']['journals'], ['journal_entry_id' => $journal]));
        $row = $f['rows']['attributions'];
        unset($row['id']);
        $row['journal_entry_id'] = $journal;
        $this->rejects(fn () => DB::table('operational_reconciliation_attributions')->insert($row), 'owned journal');
        $row['journal_line_id'] = $line;
        $row['amount'] = '100.00';
        $this->assertTrue(DB::table('operational_reconciliation_attributions')->insert($row));
        $this->assertDatabaseHas('operational_reconciliation_attributions', ['review_id' => $f['review'],
            'journal_entry_id' => $journal, 'journal_line_id' => $line, 'amount' => '100.00']);
    }
}
