<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\CreateOpeningBalanceDraft;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Models\ChartAccount;
use App\Models\FiscalYearClose;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PDOException;
use RuntimeException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class FiscalYearCloseHttpTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;
    private User $other;
    private ChartAccount $asset;
    private ChartAccount $retained;
    private ChartAccount $revenue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->asset = $this->account($this->owner, '1000', 'asset');
        $this->retained = $this->account($this->owner, '3000', 'equity');
        $this->revenue = $this->account($this->owner, '4000', 'revenue');
    }

    private function account(User $owner, string $code, string $type): ChartAccount
    {
        $account = (new CreateChartAccount)->execute($owner, $code, $code, $type);
        $account->update(['cash_role' => 'non_cash']);

        return $account;
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'currency' => config('accounting.currency'),
            'retained_earnings_account_id' => $this->retained->id,
        ], $changes);
    }

    private function lines(string $amount = '0.01'): array
    {
        return [
            ['chart_account_id' => $this->asset->id, 'debit' => $amount, 'credit' => '0.00'],
            ['chart_account_id' => $this->revenue->id, 'debit' => '0.00', 'credit' => $amount],
        ];
    }

    private function journal(bool $post = true): \App\Models\JournalEntry
    {
        $draft = (new SaveJournalDraft)->execute($this->owner, '2026-06-15', config('accounting.currency'), $this->lines());

        return $post ? (new PostJournalEntry)->execute($this->owner, $draft->id) : $draft;
    }

    public function test_routes_are_authenticated_read_only_after_creation_and_have_exact_methods(): void
    {
        foreach ([['GET', '/accounting/fiscal-year-closes'], ['POST', '/accounting/fiscal-year-closes'],
            ['GET', '/accounting/fiscal-year-closes/1']] as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->getName() ?? '', 'accounting.fiscal-year-closes.'));
        $this->assertCount(3, $routes);
        foreach ([
            'accounting.fiscal-year-closes.index' => ['accounting/fiscal-year-closes', ['GET', 'HEAD']],
            'accounting.fiscal-year-closes.store' => ['accounting/fiscal-year-closes', ['POST']],
            'accounting.fiscal-year-closes.show' => ['accounting/fiscal-year-closes/{fiscalYearClose}', ['GET', 'HEAD']],
        ] as $name => [$uri, $methods]) {
            $route = $routes->first(fn ($candidate) => $candidate->getName() === $name);
            $this->assertNotNull($route);
            $this->assertSame($uri, $route->uri());
            $this->assertSame($methods, $route->methods());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('web', $route->gatherMiddleware());
        }
        $this->actingAs($this->owner);
        foreach (['putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->{$method}('/accounting/fiscal-year-closes/1')->assertStatus(405);
        }
        $this->postJson('/accounting/fiscal-year-closes/1/reopen')->assertNotFound();
    }

    public function test_profitable_close_returns_canonical_exact_json_and_linked_posted_journal(): void
    {
        $this->journal();
        $created = $this->actingAs($this->owner)->postJson('/accounting/fiscal-year-closes', $this->payload())
            ->assertCreated()->assertJsonPath('data.start_date', '2026-01-01')
            ->assertJsonPath('data.end_date', '2026-12-31')
            ->assertJsonPath('data.currency', config('accounting.currency'))
            ->assertJsonPath('data.retained_earnings_account_id', $this->retained->id);
        $data = $created->json('data');
        $this->assertSame(['id', 'start_date', 'end_date', 'currency', 'retained_earnings_account_id',
            'journal_entry_id', 'closed_at'], array_keys($data));
        $this->assertIsInt($data['journal_entry_id']);
        $this->assertIsString($data['closed_at']);
        $this->assertDatabaseHas('journal_entries', ['id' => $data['journal_entry_id'], 'user_id' => $this->owner->id,
            'entry_date' => '2026-12-31', 'currency' => config('accounting.currency'), 'status' => 'posted']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $data['journal_entry_id'],
            'chart_account_id' => $this->revenue->id, 'debit' => '0.01', 'credit' => '0.00']);
        $this->getJson('/accounting/fiscal-year-closes/'.$data['id'])->assertOk()->assertExactJson($created->json());
    }

    public function test_zero_activity_close_has_null_journal_and_list_is_owned_ordered(): void
    {
        $this->actingAs($this->owner);
        $later = $this->postJson('/accounting/fiscal-year-closes', $this->payload([
            'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
        ]))->assertCreated();
        $earlier = $this->postJson('/accounting/fiscal-year-closes', $this->payload())->assertCreated()
            ->assertJsonPath('data.journal_entry_id', null);
        $this->assertDatabaseCount('journal_entries', 0);
        $otherRetained = $this->account($this->other, '3000', 'equity');
        (new \App\Accounting\Actions\CloseFiscalYear)->execute($this->other, '2026-01-01', '2026-12-31',
            config('accounting.currency'), $otherRetained->id);
        $list = $this->getJson('/accounting/fiscal-year-closes')->assertOk()->json('data');
        $this->assertSame([$earlier->json('data.id'), $later->json('data.id')], array_column($list, 'id'));
        $this->assertSame([null, null], array_column($list, 'journal_entry_id'));
    }

    public function test_foreign_and_missing_closes_are_hidden(): void
    {
        $otherRetained = $this->account($this->other, '3000', 'equity');
        $foreign = (new \App\Accounting\Actions\CloseFiscalYear)->execute($this->other, '2026-01-01', '2026-12-31',
            config('accounting.currency'), $otherRetained->id);
        $this->actingAs($this->owner);
        $this->getJson('/accounting/fiscal-year-closes/'.$foreign->id)->assertNotFound();
        $this->getJson('/accounting/fiscal-year-closes/'.($foreign->id + 100000))->assertNotFound();
        $this->assertSame([], $this->getJson('/accounting/fiscal-year-closes')->assertOk()->json('data'));
    }

    public function test_invalid_shape_dates_currency_and_account_ids_are_422(): void
    {
        $this->actingAs($this->owner);
        foreach ([
            ['start_date', ['start_date' => '2026-1-01']],
            ['start_date', ['start_date' => '2026-02-30']],
            ['end_date', ['end_date' => '2025-12-31']],
            ['currency', ['currency' => 'usd']],
            ['currency', ['currency' => config('accounting.currency') === 'SAR' ? 'USD' : 'SAR']],
            ['retained_earnings_account_id', ['retained_earnings_account_id' => 0]],
            ['retained_earnings_account_id', ['retained_earnings_account_id' => 1.5]],
            ['user_id', ['user_id' => $this->other->id]],
            ['journal_entry_id', ['journal_entry_id' => 1]],
            ['closed_at', ['closed_at' => now()->toISOString()]],
            ['status', ['status' => 'posted']],
        ] as [$field, $change]) {
            $this->postJson('/accounting/fiscal-year-closes', $this->payload($change))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach (['start_date', 'end_date', 'currency', 'retained_earnings_account_id'] as $field) {
            $payload = $this->payload();
            unset($payload[$field]);
            $this->postJson('/accounting/fiscal-year-closes', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('fiscal_year_closes', 0);
    }

    public function test_fiscal_close_preserves_exact_input_while_other_accounting_routes_still_trim(): void
    {
        $this->actingAs($this->owner);
        foreach ([
            ['currency', ' '.config('accounting.currency').' '],
            ['start_date', ' 2026-01-01 '],
            ['end_date', '2026-12-31 '],
            ['retained_earnings_account_id', ' '.$this->retained->id.' '],
        ] as [$field, $value]) {
            $this->postJson('/accounting/fiscal-year-closes', $this->payload([$field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('fiscal_year_closes', 0);

        $this->getJson('/accounting/income-statement?date_from=%202026-01-01%20&date_to=2026-12-31%20')
            ->assertOk()->assertJsonPath('data.date_from', '2026-01-01')
            ->assertJsonPath('data.date_to', '2026-12-31');

        $this->postJson('/accounting/fiscal-year-closes', $this->payload())
            ->assertCreated()->assertJsonPath('data.currency', config('accounting.currency'));
    }

    public function test_invalid_retained_account_and_business_blockers_are_safe_conflicts(): void
    {
        $foreign = $this->account($this->other, '3000', 'equity');
        $inactive = $this->account($this->owner, '3001', 'equity');
        $inactive->is_active = false;
        $inactive->save();
        $this->actingAs($this->owner);
        foreach ([$foreign->id, $inactive->id, $this->asset->id] as $id) {
            $response = $this->postJson('/accounting/fiscal-year-closes', $this->payload(['retained_earnings_account_id' => $id]))
                ->assertConflict()->assertJsonStructure(['message']);
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertStringNotContainsString('fyc_', $response->getContent());
        }
        $this->journal(false);
        $this->postJson('/accounting/fiscal-year-closes', $this->payload())->assertConflict();
        (new PostJournalEntry)->execute($this->owner, \App\Models\JournalEntry::ownedBy($this->owner)->firstOrFail()->id);
        $opening = (new CreateOpeningBalanceDraft)->execute($this->owner, '2026-07-01', config('accounting.currency'), [
            ['chart_account_id' => $this->asset->id, 'debit' => '0.01', 'credit' => '0.00'],
            ['chart_account_id' => $this->retained->id, 'debit' => '0.00', 'credit' => '0.01'],
        ]);
        $this->postJson('/accounting/fiscal-year-closes', $this->payload())->assertConflict();
        (new \App\Accounting\Actions\PostOpeningBalanceBatch)->execute($this->owner, $opening->id);
        $this->postJson('/accounting/fiscal-year-closes', $this->payload())->assertCreated();
        $this->postJson('/accounting/fiscal-year-closes', $this->payload())->assertConflict();
        $this->assertDatabaseCount('fiscal_year_closes', 1);
    }

    public function test_permanent_close_blocks_later_journal_and_opening_balance_writes(): void
    {
        $this->actingAs($this->owner)->postJson('/accounting/fiscal-year-closes', $this->payload())->assertCreated();
        $this->postJson('/accounting/journals', [
            'entry_date' => '2026-06-15', 'currency' => config('accounting.currency'), 'lines' => $this->lines(),
        ])->assertConflict();
        $this->postJson('/accounting/opening-balances', [
            'opening_date' => '2026-06-15', 'currency' => config('accounting.currency'), 'lines' => [
                ['chart_account_id' => $this->asset->id, 'debit' => '0.01', 'credit' => '0.00'],
                ['chart_account_id' => $this->retained->id, 'debit' => '0.00', 'credit' => '0.01'],
            ],
        ])->assertConflict();
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('opening_balance_batches', 0);
    }

    public function test_unexpected_sql_failure_is_sanitized_and_rolls_back(): void
    {
        Event::listen('eloquent.creating: '.FiscalYearClose::class, function (): void {
            throw new QueryException('mysql_testing', 'select secret from fiscal_year_closes', [],
                new PDOException('SQLSTATE[HY000]: private database detail'));
        });
        try {
            $response = $this->actingAs($this->owner)->postJson('/accounting/fiscal-year-closes', $this->payload())
                ->assertStatus(500)->assertExactJson(['message' => 'Fiscal-year close request could not be completed.']);
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertStringNotContainsString('secret', $response->getContent());
            $this->assertStringNotContainsString('fiscal_year_closes', $response->getContent());
        } finally {
            Event::forget('eloquent.creating: '.FiscalYearClose::class);
        }
        $this->assertDatabaseCount('fiscal_year_closes', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_unexpected_internal_failure_is_sanitized(): void
    {
        Event::listen('eloquent.creating: '.FiscalYearClose::class, function (): void {
            throw new RuntimeException('private internal closing detail');
        });
        try {
            $response = $this->actingAs($this->owner)->postJson('/accounting/fiscal-year-closes', $this->payload())
                ->assertStatus(500)->assertExactJson(['message' => 'Fiscal-year close request could not be completed.']);
            $this->assertStringNotContainsString('private internal', $response->getContent());
        } finally {
            Event::forget('eloquent.creating: '.FiscalYearClose::class);
        }
        $this->assertDatabaseCount('fiscal_year_closes', 0);
    }
}
