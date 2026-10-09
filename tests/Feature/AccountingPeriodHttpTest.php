<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateAccountingPeriod;
use App\Models\AccountingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class AccountingPeriodHttpTest extends TestCase
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

    private function period(User $owner, string $start, string $end): AccountingPeriod
    {
        return (new CreateAccountingPeriod)->execute($owner, $start, $end);
    }

    private function dates(string $start = '2026-01-01', string $end = '2026-01-31'): array
    {
        return ['start_date' => $start, 'end_date' => $end];
    }

    public function test_all_period_routes_require_authentication(): void
    {
        foreach ([
            ['GET', '/accounting/periods'], ['POST', '/accounting/periods'],
            ['GET', '/accounting/periods/1'], ['PUT', '/accounting/periods/1'],
            ['POST', '/accounting/periods/1/close'], ['POST', '/accounting/periods/1/reopen'],
            ['DELETE', '/accounting/periods/1'],
        ] as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }
    }

    public function test_routes_use_authenticated_accounting_group(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->getName() ?? '', 'accounting.periods.'));
        $this->assertCount(7, $routes);
        foreach ($routes as $route) {
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('web', $route->gatherMiddleware());
        }
    }

    public function test_owner_list_is_sorted_and_payload_is_stable_without_user_id(): void
    {
        $later = $this->period($this->owner, '2026-03-01', '2026-03-31');
        $earlier = $this->period($this->owner, '2026-01-01', '2026-01-31');
        $this->period($this->other, '2026-02-01', '2026-02-28');

        $response = $this->actingAs($this->owner)->getJson('/accounting/periods')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$earlier->id, $later->id], array_column($response->json('data'), 'id'));
        $this->assertSame(['id', 'start_date', 'end_date', 'status', 'first_closed_at', 'created_at', 'updated_at'], array_keys($response->json('data.0')));
        $response->assertJsonPath('data.0.start_date', '2026-01-01')
            ->assertJsonPath('data.0.end_date', '2026-01-31')
            ->assertJsonPath('data.0.status', 'open')
            ->assertJsonPath('data.0.first_closed_at', null);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $response->json('data.0.created_at'));
        $this->assertArrayNotHasKey('user_id', $response->json('data.0'));
    }

    public function test_create_show_update_and_delete_never_closed_period(): void
    {
        $this->actingAs($this->owner);
        $created = $this->postJson('/accounting/periods', $this->dates())->assertCreated()
            ->assertJsonPath('data.start_date', '2026-01-01')->assertJsonPath('data.end_date', '2026-01-31')
            ->assertJsonPath('data.status', 'open')->assertJsonPath('data.first_closed_at', null);
        $id = $created->json('data.id');
        $this->assertArrayNotHasKey('user_id', $created->json('data'));
        $this->getJson('/accounting/periods/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->putJson('/accounting/periods/'.$id, $this->dates('2026-01-02', '2026-02-01'))
            ->assertOk()->assertJsonPath('data.start_date', '2026-01-02')->assertJsonPath('data.end_date', '2026-02-01');
        $this->deleteJson('/accounting/periods/'.$id)->assertNoContent();
        $this->assertDatabaseMissing('accounting_periods', ['id' => $id]);
    }

    public function test_overlap_and_invalid_or_protected_fields_use_existing_error_shapes(): void
    {
        $this->actingAs($this->owner);
        $this->postJson('/accounting/periods', $this->dates())->assertCreated();
        $this->postJson('/accounting/periods', $this->dates('2026-01-31', '2026-02-28'))
            ->assertStatus(409)->assertExactJson(['message' => 'Accounting period overlaps an existing period.']);
        $this->postJson('/accounting/periods', $this->dates('2026-02-02', '2026-02-01'))
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->postJson('/accounting/periods', $this->dates('2026-2-01', '2026-02-28'))
            ->assertUnprocessable()->assertJsonValidationErrors('start_date');
        foreach (['id', 'user_id', 'status', 'first_closed_at', 'created_at', 'updated_at'] as $field) {
            $this->postJson('/accounting/periods', $this->dates() + [$field => null])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('accounting_periods', 1);
    }

    public function test_update_overlap_validation_and_protected_fields(): void
    {
        $first = $this->period($this->owner, '2026-01-01', '2026-01-31');
        $second = $this->period($this->owner, '2026-03-01', '2026-03-31');
        $this->actingAs($this->owner);
        $this->putJson('/accounting/periods/'.$first->id, $this->dates('2026-01-01', '2026-03-01'))
            ->assertStatus(409)->assertExactJson(['message' => 'Accounting period overlaps an existing period.']);
        $this->putJson('/accounting/periods/'.$first->id, $this->dates('2026-01-31', '2026-01-01'))
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->putJson('/accounting/periods/'.$first->id, $this->dates() + ['status' => 'closed'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->putJson('/accounting/periods/'.$first->id, $this->dates('2026-02-01', '2026-02-28'))
            ->assertOk()->assertJsonPath('data.end_date', '2026-02-28');
        $this->assertSame('2026-03-01', $second->fresh()->start_date->format('Y-m-d'));
    }

    public function test_close_reopen_and_ever_closed_restrictions_are_conflicts(): void
    {
        $period = $this->period($this->owner, '2026-01-01', '2026-01-31');
        $url = '/accounting/periods/'.$period->id;
        $this->actingAs($this->owner);
        $closed = $this->postJson($url.'/close')->assertOk()->assertJsonPath('data.status', 'closed');
        $firstClosed = $closed->json('data.first_closed_at');
        $this->assertNotNull($firstClosed);
        $this->postJson($url.'/close')->assertStatus(409)->assertJsonStructure(['message']);
        $this->putJson($url, $this->dates())->assertStatus(409)->assertJsonStructure(['message']);
        $this->deleteJson($url)->assertStatus(409)->assertJsonStructure(['message']);

        $this->postJson($url.'/reopen')->assertOk()->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.first_closed_at', $firstClosed);
        $this->postJson($url.'/reopen')->assertStatus(409)->assertJsonStructure(['message']);
        $this->putJson($url, $this->dates())->assertStatus(409)->assertJsonStructure(['message']);
        $this->deleteJson($url)->assertStatus(409)->assertJsonStructure(['message']);
        $this->assertDatabaseHas('accounting_periods', ['id' => $period->id]);
    }

    public function test_foreign_and_missing_period_ids_return_404_even_for_invalid_update_body(): void
    {
        $foreign = $this->period($this->other, '2026-01-01', '2026-01-31');
        $this->actingAs($this->owner);
        foreach ([$foreign->id, $foreign->id + 100000] as $id) {
            $url = '/accounting/periods/'.$id;
            $this->getJson($url)->assertNotFound();
            $this->putJson($url, ['start_date' => 'invalid'])->assertNotFound();
            $this->postJson($url.'/close')->assertNotFound();
            $this->postJson($url.'/reopen')->assertNotFound();
            $this->deleteJson($url)->assertNotFound();
        }
        $this->assertTrue($foreign->fresh()->isOpen());
    }
}
