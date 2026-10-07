<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\SaveJournalDraft;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class AccountingHttpTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
    }

    private function account(string $code = '1000', ?User $actor = null): ChartAccount
    {
        return (new CreateChartAccount)->execute($actor ?? $this->owner, $code, 'Account '.$code, 'asset');
    }

    private function chartData(array $changes = []): array
    {
        return array_replace(['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'is_active' => true, 'parent_id' => null], $changes);
    }

    private function line(ChartAccount $account, string $debit = '1.2', string $credit = '0'): array
    {
        return ['chart_account_id' => $account->id, 'debit' => $debit, 'credit' => $credit, 'description' => 'Line'];
    }

    private function draftData(array $lines = [], array $changes = []): array
    {
        return array_replace(['entry_date' => '2026-10-06', 'currency' => config('accounting.currency'),
            'reference' => 'REF', 'description' => 'Draft', 'lines' => $lines], $changes);
    }

    private function draft(User $actor, array $lines = [], string $date = '2026-10-06'): JournalEntry
    {
        return (new SaveJournalDraft)->execute($actor, $date, config('accounting.currency'), $lines);
    }

    public function test_guests_are_blocked_from_every_accounting_route(): void
    {
        foreach ([['GET', '/accounting/chart-accounts'], ['POST', '/accounting/chart-accounts'],
            ['PUT', '/accounting/chart-accounts/1'], ['DELETE', '/accounting/chart-accounts/1'],
            ['GET', '/accounting/periods'], ['POST', '/accounting/periods'], ['GET', '/accounting/periods/1'],
            ['PUT', '/accounting/periods/1'], ['POST', '/accounting/periods/1/close'],
            ['POST', '/accounting/periods/1/reopen'], ['DELETE', '/accounting/periods/1'],
            ['GET', '/accounting/journals'], ['GET', '/accounting/journals/1'], ['POST', '/accounting/journals'],
            ['PUT', '/accounting/journals/1'], ['DELETE', '/accounting/journals/1'], ['POST', '/accounting/journals/1/post'],
            ['POST', '/accounting/journals/1/reverse'],
            ['GET', '/accounting/general-ledger'], ['GET', '/accounting/trial-balance']] as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }
        $this->get('/accounting/chart-accounts')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    }

    public function test_route_set_is_authenticated_and_has_no_independent_line_crud(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->getName() ?? '', 'accounting.'));
        $this->assertCount(22, $routes);
        foreach ($routes as $route) {
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertStringNotContainsString('journal-lines', $route->uri());
        }
        foreach ([
            'accounting.income-statement' => 'accounting/income-statement',
            'accounting.balance-sheet' => 'accounting/balance-sheet',
        ] as $name => $uri) {
            $route = $routes->first(fn ($candidate) => $candidate->getName() === $name);
            $this->assertNotNull($route);
            $this->assertSame($uri, $route->uri());
            $this->assertSame(['GET', 'HEAD'], $route->methods());
        }
    }

    public function test_chart_index_is_owned_ordered_and_includes_inactive_hierarchy(): void
    {
        $child = $this->account('2000');
        $parent = $this->account();
        $child->update(['parent_id' => $parent->id, 'is_active' => false]);
        $this->account(actor: $this->other);
        $response = $this->actingAs($this->owner)->getJson('/accounting/chart-accounts')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$parent->id, $child->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.1.is_active', false)->assertJsonPath('data.1.parent.id', $parent->id)
            ->assertJsonPath('data.0.children.0.id', $child->id);
        $this->assertArrayNotHasKey('balance', $response->json('data.0'));
    }

    public function test_chart_create_update_and_delete_return_expected_json_statuses(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/accounting/chart-accounts', $this->chartData(['code' => ' cash.1 ', 'name' => ' Cash ']))
            ->assertCreated()->assertJsonPath('data.code', 'CASH.1')->assertJsonPath('data.name', 'Cash')
            ->assertJsonPath('data.user_id', $this->owner->id);
        $id = $response->json('data.id');
        $this->putJson('/accounting/chart-accounts/'.$id, $this->chartData(['code' => 'EXP', 'type' => 'expense', 'name' => 'Expense', 'is_active' => false]))
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.type', 'expense')->assertJsonPath('data.is_active', false);
        $this->deleteJson('/accounting/chart-accounts/'.$id)->assertNoContent();
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $id]);
    }

    public function test_chart_validation_and_protected_owner_fields_are_rejected(): void
    {
        $this->actingAs($this->owner)->postJson('/accounting/chart-accounts', $this->chartData(['code' => 'bad code', 'name' => ' ', 'type' => 'ASSET', 'is_active' => 'bad']))
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'name', 'type', 'is_active']);
        foreach (['user_id', 'id'] as $field) {
            $this->postJson('/accounting/chart-accounts', $this->chartData([$field => null]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('chart_of_accounts', 0);
    }

    public function test_chart_cycle_reference_and_delete_conflicts_map_to_409(): void
    {
        $parent = $this->account();
        $child = $this->account('2000');
        $child->update(['parent_id' => $parent->id]);
        $this->actingAs($this->owner)->putJson('/accounting/chart-accounts/'.$parent->id, $this->chartData(['parent_id' => $child->id]))
            ->assertConflict()->assertJsonStructure(['message']);
        $this->deleteJson('/accounting/chart-accounts/'.$parent->id)->assertConflict();
        $this->draft($this->owner, [$this->line($child)]);
        $this->putJson('/accounting/chart-accounts/'.$child->id, $this->chartData(['code' => 'NEW']))->assertConflict();
        $this->deleteJson('/accounting/chart-accounts/'.$child->id)->assertConflict();
        $this->assertDatabaseCount('journal_lines', 1);
    }

    public function test_foreign_chart_resources_and_parent_do_not_leak(): void
    {
        $foreign = $this->account(actor: $this->other);
        $this->actingAs($this->owner)->putJson('/accounting/chart-accounts/'.$foreign->id, $this->chartData())->assertNotFound();
        $this->deleteJson('/accounting/chart-accounts/'.$foreign->id)->assertNotFound();
        $this->getJson('/accounting/general-ledger?chart_account_id='.$foreign->id)->assertNotFound();
        $this->postJson('/accounting/chart-accounts', $this->chartData(['parent_id' => $foreign->id]))->assertNotFound();
        $this->assertNotNull($foreign->fresh());
    }

    public function test_journal_create_empty_and_line_drafts_and_show_exact_ordered_account_data(): void
    {
        $account = $this->account();
        $this->actingAs($this->owner)->postJson('/accounting/journals', $this->draftData())
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.posted_at', null)->assertJsonPath('data.lines', []);
        $response = $this->postJson('/accounting/journals', $this->draftData([$this->line($account), $this->line($account, '0', '1.20')]))
            ->assertCreated()->assertJsonPath('data.user_id', $this->owner->id)->assertJsonCount(2, 'data.lines');
        $this->getJson('/accounting/journals/'.$response->json('data.id'))->assertOk()
            ->assertJsonPath('data.lines.0.line_number', 1)->assertJsonPath('data.lines.1.line_number', 2)
            ->assertJsonPath('data.lines.0.debit', '1.20')->assertJsonPath('data.lines.1.credit', '1.20')
            ->assertJsonPath('data.lines.0.chart_account.code', '1000')->assertJsonPath('data.lines.0.chart_account.name', 'Account 1000');
    }

    public function test_protected_journal_header_and_nested_fields_are_strictly_rejected(): void
    {
        $account = $this->account();
        $this->actingAs($this->owner);
        foreach (['user_id', 'status', 'posted_at', 'version', 'id', 'line_number', 'journal_entry_id'] as $field) {
            $this->postJson('/accounting/journals', $this->draftData(changes: [$field => null]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach (['user_id', 'status', 'posted_at', 'version', 'line_number', 'journal_entry_id', 'id', 'unexpected'] as $field) {
            $this->postJson('/accounting/journals', $this->draftData([array_merge($this->line($account), [$field => 99])]))
                ->assertUnprocessable()->assertJsonValidationErrors('lines.0');
        }
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_invalid_money_currency_sides_and_foreign_accounts_return_422(): void
    {
        $account = $this->account();
        $foreign = $this->account(actor: $this->other);
        $this->actingAs($this->owner);
        foreach (['-1', '1e2', '1,00', 'bad', '1.234', '10000000000000', ' 1.00 '] as $amount) {
            $this->postJson('/accounting/journals', $this->draftData([$this->line($account, $amount)]))
                ->assertUnprocessable()->assertJsonValidationErrors('lines.0.debit');
        }
        $line = $this->line($account);
        $line['debit'] = 1.2;
        $this->postJson('/accounting/journals', $this->draftData([$line]))->assertUnprocessable();
        foreach (['sar', 'USD'] as $currency) {
            $this->postJson('/accounting/journals', $this->draftData(changes: ['currency' => $currency]))->assertUnprocessable()->assertJsonValidationErrors('currency');
        }
        $this->postJson('/accounting/journals', $this->draftData([$this->line($account, '0', '0')]))->assertUnprocessable();
        $this->postJson('/accounting/journals', $this->draftData([$this->line($foreign)]))->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_journal_update_requires_version_and_maps_stale_version_to_409(): void
    {
        $account = $this->account();
        $journal = $this->draft($this->owner, [$this->line($account)]);
        $this->actingAs($this->owner)->putJson('/accounting/journals/'.$journal->id, $this->draftData())
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->putJson('/accounting/journals/'.$journal->id, $this->draftData(changes: ['version' => 1, 'description' => 'Updated']))
            ->assertOk()->assertJsonPath('data.id', $journal->id)->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.description', 'Updated')->assertJsonPath('data.lines', []);
        $this->putJson('/accounting/journals/'.$journal->id, $this->draftData(changes: ['version' => 1]))
            ->assertConflict()->assertJsonStructure(['message']);
        $this->assertSame(2, $journal->fresh()->version);
    }

    public function test_owned_indexes_are_stable_and_foreign_journal_mutations_are_404(): void
    {
        $old = $this->draft($this->owner, date: '2026-01-01');
        $new = $this->draft($this->owner);
        $sameDate = $this->draft($this->owner);
        $foreign = $this->draft($this->other);
        $response = $this->actingAs($this->owner)->getJson('/accounting/journals')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame([$sameDate->id, $new->id, $old->id], array_column($response->json('data'), 'id'));
        $this->getJson('/accounting/journals/'.$foreign->id)->assertNotFound();
        $this->putJson('/accounting/journals/'.$foreign->id, $this->draftData(changes: ['version' => 1]))->assertNotFound();
        $this->deleteJson('/accounting/journals/'.$foreign->id)->assertNotFound();
        $this->postJson('/accounting/journals/'.$foreign->id.'/post')->assertNotFound();
        $this->assertNotNull($foreign->fresh());
    }

    public function test_posting_is_idempotent_and_posted_update_delete_are_forbidden(): void
    {
        $account = $this->account();
        $journal = $this->draft($this->owner, [$this->line($account), $this->line($account, '0', '1.2')]);
        $response = $this->actingAs($this->owner)->postJson('/accounting/journals/'.$journal->id.'/post')
            ->assertOk()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.version', 2);
        $this->assertNotNull($response->json('data.posted_at'));
        $retry = $this->postJson('/accounting/journals/'.$journal->id.'/post')->assertOk();
        $this->assertSame($response->json('data'), $retry->json('data'));
        $this->putJson('/accounting/journals/'.$journal->id, $this->draftData(changes: ['version' => 2]))->assertForbidden();
        $this->deleteJson('/accounting/journals/'.$journal->id)->assertForbidden();
        $this->assertDatabaseCount('journal_lines', 2);
    }

    public function test_empty_post_conflict_and_draft_delete_semantics(): void
    {
        $empty = $this->draft($this->owner);
        $this->actingAs($this->owner)->postJson('/accounting/journals/'.$empty->id.'/post')->assertConflict();
        $withLines = $this->draft($this->owner, [$this->line($this->account())]);
        $this->deleteJson('/accounting/journals/'.$withLines->id)->assertNoContent();
        $this->assertDatabaseMissing('journal_entries', ['id' => $withLines->id]);
        $this->assertDatabaseMissing('journal_lines', ['journal_entry_id' => $withLines->id]);
    }

    public function test_report_endpoints_are_owned_exact_and_validate_dates(): void
    {
        $account = $this->account();
        $journal = $this->draft($this->owner, [$this->line($account), $this->line($account, '0', '1.2')]);
        $this->actingAs($this->owner)->postJson('/accounting/journals/'.$journal->id.'/post')->assertOk();
        $this->getJson('/accounting/general-ledger?chart_account_id='.$account->id)->assertOk()
            ->assertJsonPath('data.account.id', $account->id)->assertJsonPath('data.period.total_debit', '1.20')
            ->assertJsonCount(2, 'data.movements');
        $this->getJson('/accounting/trial-balance?as_of=2026-10-06')->assertOk()
            ->assertJsonPath('data.totals.total_debits', '1.20')->assertJsonCount(1, 'data.accounts');
        $this->getJson('/accounting/general-ledger?chart_account_id='.$account->id.'&date_from=2026-10-07&date_to=2026-10-06')
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson('/accounting/trial-balance?as_of=bad')->assertUnprocessable()->assertJsonValidationErrors('as_of');
        $this->getJson('/accounting/general-ledger')->assertUnprocessable()->assertJsonValidationErrors('chart_account_id');
    }

    public function test_unbalanced_reporting_conflict_is_409_and_non_json_accounting_validation_is_422(): void
    {
        $journal = $this->draft($this->owner, [$this->line($this->account())]);
        DB::table('journal_entries')->where('id', $journal->id)->update(['status' => 'posted', 'posted_at' => now()]);
        $this->actingAs($this->owner)->getJson('/accounting/trial-balance')->assertConflict()->assertJsonStructure(['message']);
        $this->post('/accounting/journals', [])->assertUnprocessable()->assertHeader('Content-Type', 'application/json');
    }

    public function test_controllers_apply_discovered_policies(): void
    {
        $account = $this->account();
        Gate::policy(ChartAccount::class, DenyAccountingChartPolicy::class);
        $this->actingAs($this->owner)->getJson('/accounting/chart-accounts')->assertForbidden();
        $this->postJson('/accounting/chart-accounts', $this->chartData(['code' => '2000']))->assertForbidden();
        $this->putJson('/accounting/chart-accounts/'.$account->id, $this->chartData())->assertForbidden();
        $this->deleteJson('/accounting/chart-accounts/'.$account->id)->assertForbidden();
        $this->getJson('/accounting/general-ledger?chart_account_id='.$account->id)->assertForbidden();
        $this->getJson('/accounting/trial-balance')->assertForbidden();
    }
}

class DenyAccountingChartPolicy
{
    public function before(): bool
    {
        return false;
    }
}
