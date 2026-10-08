<?php

namespace Tests\Feature;

use App\Accounting\Actions\CloseAccountingPeriod;
use App\Accounting\Actions\CreateAccountingPeriod;
use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\CreateOpeningBalanceDraft;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class OpeningBalanceHttpTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;
    private User $other;
    private int $assetId;
    private int $equityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->assetId = (new CreateChartAccount)->execute($this->owner, '1000', 'Cash', 'asset')->id;
        $this->equityId = (new CreateChartAccount)->execute($this->owner, '3000', 'Capital', 'equity')->id;
    }

    private function payload(string $amount = '100.00', string $date = '2026-01-15'): array
    {
        return [
            'opening_date' => $date,
            'currency' => config('accounting.currency'),
            'lines' => [
                ['chart_account_id' => $this->assetId, 'debit' => $amount, 'credit' => '0.00'],
                ['chart_account_id' => $this->equityId, 'debit' => '0.00', 'credit' => $amount],
            ],
        ];
    }

    private function draft(?User $actor = null): OpeningBalanceBatch
    {
        $payload = $this->payload();
        if ($actor !== null && ! $actor->is($this->owner)) {
            $payload['lines'][0]['chart_account_id'] = (new CreateChartAccount)->execute($actor, '1000', 'Cash', 'asset')->id;
            $payload['lines'][1]['chart_account_id'] = (new CreateChartAccount)->execute($actor, '3000', 'Capital', 'equity')->id;
        }

        return (new CreateOpeningBalanceDraft)->execute($actor ?? $this->owner, $payload['opening_date'], $payload['currency'], $payload['lines']);
    }

    private function close(string $date = '2026-01-15'): void
    {
        $period = (new CreateAccountingPeriod)->execute($this->owner, $date, $date);
        (new CloseAccountingPeriod)->execute($this->owner, $period->id);
    }

    public function test_routes_require_authentication_and_use_accounting_group(): void
    {
        foreach ([
            ['POST', '/accounting/opening-balances'],
            ['GET', '/accounting/opening-balances/1'],
            ['PUT', '/accounting/opening-balances/1'],
            ['DELETE', '/accounting/opening-balances/1'],
            ['POST', '/accounting/opening-balances/1/post'],
        ] as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->getName() ?? '', 'accounting.opening-balances.'));
        $this->assertCount(5, $routes);
        foreach ($routes as $route) {
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('web', $route->gatherMiddleware());
        }
    }

    public function test_create_show_update_delete_return_canonical_exact_json(): void
    {
        $this->actingAs($this->owner);
        $created = $this->postJson('/accounting/opening-balances', $this->payload('0.01'))
            ->assertCreated()->assertJsonPath('data.opening_date', '2026-01-15')
            ->assertJsonPath('data.currency', config('accounting.currency'))
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.journal_entry_id', null)
            ->assertJsonPath('data.posted_at', null)->assertJsonPath('data.lines.0.debit', '0.01')
            ->assertJsonPath('data.lines.0.credit', '0.00');
        $id = $created->json('data.id');
        $this->assertSame(['id', 'opening_date', 'currency', 'status', 'journal_entry_id', 'posted_at', 'lines'], array_keys($created->json('data')));
        $this->assertSame(['chart_account_id', 'debit', 'credit'], array_keys($created->json('data.lines.0')));
        $this->getJson('/accounting/opening-balances/'.$id)->assertOk()->assertExactJson($created->json());
        $this->putJson('/accounting/opening-balances/'.$id, $this->payload('9999999999999.99', '2026-02-01'))
            ->assertOk()->assertJsonPath('data.opening_date', '2026-02-01')
            ->assertJsonPath('data.lines.0.debit', '9999999999999.99')
            ->assertJsonPath('data.lines.1.credit', '9999999999999.99');
        $this->assertDatabaseCount('opening_balance_lines', 2);
        $this->deleteJson('/accounting/opening-balances/'.$id)->assertNoContent();
        $this->getJson('/accounting/opening-balances/'.$id)->assertNotFound();
    }

    public function test_post_is_idempotent_and_exposes_one_linked_posted_journal(): void
    {
        $batch = $this->draft();
        $url = '/accounting/opening-balances/'.$batch->id;
        $this->actingAs($this->owner);
        $posted = $this->postJson($url.'/post')->assertOk()->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.lines.0.debit', '100.00');
        $journalId = $posted->json('data.journal_entry_id');
        $this->assertNotNull($journalId);
        $this->assertNotNull($posted->json('data.posted_at'));
        $this->assertDatabaseHas('journal_entries', ['id' => $journalId, 'user_id' => $this->owner->id, 'status' => 'posted']);
        $this->postJson($url.'/post')->assertOk()->assertJsonPath('data.journal_entry_id', $journalId);
        $this->getJson($url)->assertOk()->assertJsonPath('data.journal_entry_id', $journalId);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 2);
        foreach ([$this->putJson($url, $this->payload()), $this->deleteJson($url)] as $response) {
            $response->assertConflict()->assertJsonStructure(['message']);
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertStringNotContainsString('accounting_obb_', $response->getContent());
        }
        $this->assertDatabaseCount('opening_balance_batches', 1);
    }

    public function test_foreign_and_missing_batches_are_hidden_even_with_invalid_update_body(): void
    {
        $foreign = $this->draft($this->other);
        $this->actingAs($this->owner);
        foreach ([$foreign->id, $foreign->id + 100000] as $id) {
            $url = '/accounting/opening-balances/'.$id;
            $this->getJson($url)->assertNotFound();
            $this->putJson($url, ['opening_date' => 'bad'])->assertNotFound();
            $this->deleteJson($url)->assertNotFound();
            $this->postJson($url.'/post')->assertNotFound();
        }
        $this->assertTrue($foreign->fresh()->isDraft());
    }

    public function test_shape_date_currency_and_amount_errors_are_validation_errors(): void
    {
        $this->actingAs($this->owner);
        foreach ([
            ['opening_date', $this->payload(date: '2026-2-01')],
            ['opening_date', $this->payload(date: '2026-02-30')],
            ['currency', array_replace($this->payload(), ['currency' => 'usd'])],
            ['currency', array_replace($this->payload(), ['currency' => config('accounting.currency') === 'USD' ? 'SAR' : 'USD'])],
            ['lines', array_replace($this->payload(), ['lines' => []])],
            ['lines', array_replace($this->payload(), ['lines' => [$this->payload()['lines'][0]]])],
            ['lines.0.debit', $this->payloadWithLine(0, 'debit', 0.01)],
            ['lines.0.debit', $this->payloadWithLine(0, 'debit', ' 100.00 ')],
            ['lines.0.debit', $this->payloadWithLine(0, 'debit', '1e3')],
            ['lines.0.debit', $this->payloadWithLine(0, 'debit', '99999999999999.99')],
            ['lines.1.chart_account_id', $this->payloadWithLine(1, 'chart_account_id', $this->assetId)],
            ['lines', $this->payloadWithLine(1, 'credit', '99.99')],
            ['user_id', array_replace($this->payload(), ['user_id' => $this->other->id])],
        ] as [$field, $payload]) {
            $this->postJson('/accounting/opening-balances', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson('/accounting/opening-balances', ['opening_date' => '2026-01-15', 'currency' => config('accounting.currency')])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertDatabaseCount('opening_balance_batches', 0);
    }

    private function payloadWithLine(int $index, string $field, mixed $value): array
    {
        $payload = $this->payload();
        $payload['lines'][$index][$field] = $value;

        return $payload;
    }

    public function test_inactive_and_foreign_accounts_are_rejected_without_writes(): void
    {
        $foreignId = (new CreateChartAccount)->execute($this->other, '1000', 'Other', 'asset')->id;
        $this->actingAs($this->owner);
        $this->postJson('/accounting/opening-balances', $this->payloadWithLine(0, 'chart_account_id', $foreignId))
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->owner->chartAccounts()->findOrFail($this->assetId)->update(['is_active' => false]);
        $this->postJson('/accounting/opening-balances', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertDatabaseCount('opening_balance_batches', 0);
    }

    public function test_closed_period_create_and_update_return_safe_conflicts(): void
    {
        $batch = $this->draft();
        $this->close();
        $this->actingAs($this->owner);
        foreach ([
            $this->postJson('/accounting/opening-balances', $this->payload()),
            $this->putJson('/accounting/opening-balances/'.$batch->id, $this->payload()),
        ] as $response) {
            $response->assertConflict()->assertJsonPath('message', 'Accounting period for the journal date is closed.');
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertStringNotContainsString('accounting_obb_', $response->getContent());
            $this->assertStringNotContainsString('stack trace', strtolower($response->getContent()));
        }
        $this->assertTrue($batch->fresh()->isDraft());
        $this->assertDatabaseCount('opening_balance_batches', 1);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_unexpected_sql_failure_does_not_expose_internal_details(): void
    {
        Event::listen('eloquent.creating: '.OpeningBalanceBatch::class, function (): void {
            throw new QueryException('mysql_testing', 'select secret from accounting_obb_immutable_bu', [], new PDOException('SQLSTATE[HY000]: private database detail'));
        });
        try {
            $response = $this->actingAs($this->owner)->postJson('/accounting/opening-balances', $this->payload())
                ->assertStatus(500)->assertExactJson(['message' => 'Opening balance request could not be completed.']);
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertStringNotContainsString('secret', $response->getContent());
        } finally {
            Event::forget('eloquent.creating: '.OpeningBalanceBatch::class);
        }
        $this->assertDatabaseCount('opening_balance_batches', 0);
    }
}
