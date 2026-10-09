<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateAccountingPeriod;
use App\Http\Controllers\Accounting\Pages\AccountingPeriodPageController;
use App\Models\AccountingPeriod;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class AccountingPeriodPageTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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

    public function test_period_page_routes_preserve_methods_names_middleware_and_controller_targets(): void
    {
        foreach ([
            ['periods.index', 'GET', 'periods', 'periodIndex'],
            ['periods.store', 'POST', 'periods', 'periodStore'],
            ['periods.update', 'PUT', 'periods/{period}', 'periodUpdate'],
            ['periods.close', 'POST', 'periods/{period}/close', 'periodClose'],
            ['periods.reopen', 'POST', 'periods/{period}/reopen', 'periodReopen'],
            ['periods.destroy', 'DELETE', 'periods/{period}', 'periodDelete'],
        ] as [$name, $method, $path, $action]) {
            $route = Route::getRoutes()->getByName('accounting-pages.'.$name);
            $this->assertNotNull($route);
            $this->assertSame('accounting/pages/'.$path, $route->uri());
            $this->assertSame($method === 'GET' ? ['GET', 'HEAD'] : [$method], $route->methods());
            $this->assertSame(AccountingPeriodPageController::class, $route->getControllerClass());
            $this->assertSame($action, $route->getActionMethod());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('auth', $route->gatherMiddleware());
        }
    }

    public function test_update_validation_preserves_only_the_target_period_old_input(): void
    {
        $period = $this->period($this->owner, '2026-01-01', '2026-01-31');
        $index = route('accounting-pages.periods.index');
        $this->actingAs($this->owner)->from($index)
            ->put(route('accounting-pages.periods.update', $period->id), [
                '_period_id' => (string) $period->id,
                'start_date' => '2026-01-02',
                'end_date' => 'bad-date',
            ])->assertRedirect($index)->assertSessionHasErrors('end_date');
        $this->get($index)->assertOk()->assertSee('value="2026-01-02"', false)
            ->assertSee('value="bad-date"', false);
        $this->assertSame('2026-01-01', $period->fresh()->start_date->format('Y-m-d'));
    }

    public function test_ineligible_period_operations_keep_the_existing_arabic_conflict(): void
    {
        $period = $this->period($this->owner, '2026-01-01', '2026-01-31');
        $index = route('accounting-pages.periods.index');
        $this->actingAs($this->owner)->post(route('accounting-pages.periods.close', $period->id))->assertRedirect($index);
        foreach ([
            ['PUT', 'periods.update', $this->dates('2026-01-02', '2026-01-31')],
            ['POST', 'periods.close', []],
            ['DELETE', 'periods.destroy', []],
        ] as [$method, $name, $data]) {
            $this->from($index)->call($method, route('accounting-pages.'.$name, $period->id), $data)
                ->assertRedirect($index)->assertSessionHasErrors('accounting');
            $this->assertSame('لا يمكن تنفيذ العملية على هذه الفترة المحاسبية في حالتها الحالية.',
                session('errors')->first('accounting'));
        }
    }

    public function test_period_page_database_errors_remain_sanitized(): void
    {
        DB::listen(function (): void {
            throw new QueryException('mysql_testing', 'private_sql_text', [], new PDOException('private_database_detail'));
        });
        $this->actingAs($this->owner)->get(route('accounting-pages.periods.index'))
            ->assertStatus(500)->assertViewIs('accounting.error')
            ->assertDontSee('private_sql_text')->assertDontSee('private_database_detail');
    }

    public function test_guest_cannot_access_period_page_or_actions(): void
    {
        foreach ([['GET', 'periods'], ['POST', 'periods'], ['PUT', 'periods/1'], ['POST', 'periods/1/close'], ['POST', 'periods/1/reopen'], ['DELETE', 'periods/1']] as [$method, $path]) {
            $this->call($method, '/accounting/pages/'.$path)->assertRedirect(route('login'));
        }
    }

    public function test_page_lists_only_own_periods_in_date_order_and_navigation_is_visible(): void
    {
        $later = $this->period($this->owner, '2026-03-01', '2026-03-31');
        $earlier = $this->period($this->owner, '2026-01-01', '2026-01-31');
        $this->period($this->other, '2026-02-01', '2026-02-28');

        $response = $this->actingAs($this->owner)->get(route('accounting-pages.periods.index'))->assertOk()
            ->assertSee('الفترات المحاسبية')->assertSee(route('accounting-pages.periods.index'), false)
            ->assertDontSee('2026-02-01');
        $response->assertSeeInOrder(['2026-01-01', '2026-03-01']);
        $response->assertSee(route('accounting-pages.periods.update', $earlier->id), false)
            ->assertSee(route('accounting-pages.periods.update', $later->id), false);
        $this->get(route('accounting-pages.chart.index'))->assertOk()->assertSee(route('accounting-pages.periods.index'), false);
    }

    public function test_create_validation_and_overlap_conflict_preserve_input_and_show_message(): void
    {
        $index = route('accounting-pages.periods.index');
        $this->actingAs($this->owner)->from($index)->post(route('accounting-pages.periods.store'), $this->dates())
            ->assertRedirect($index)->assertSessionHas('success');
        $this->assertDatabaseHas('accounting_periods', ['user_id' => $this->owner->id, 'status' => 'open']);

        $this->from($index)->post(route('accounting-pages.periods.store'), $this->dates('2026-01-31', '2026-02-28'))
            ->assertRedirect($index)->assertSessionHasErrors('accounting');
        $this->get($index)->assertOk()->assertSee('2026-02-28')->assertSee('role="alert"', false)
            ->assertSee('تتداخل الفترة المحاسبية مع فترة موجودة.')->assertDontSee('Accounting period overlaps');
        $this->from($index)->post(route('accounting-pages.periods.store'), $this->dates('2026-03-31', '2026-03-01'))
            ->assertRedirect($index)->assertSessionHasErrors('end_date');
        $this->get($index)->assertOk()->assertSee('2026-03-31');
        $this->assertDatabaseCount('accounting_periods', 1);
    }

    public function test_edit_close_reopen_and_delete_controls_follow_policy_state(): void
    {
        $period = $this->period($this->owner, '2026-01-01', '2026-01-31');
        $index = route('accounting-pages.periods.index');
        $this->actingAs($this->owner)->get($index)->assertOk()
            ->assertSee('حفظ التواريخ')
            ->assertSee(route('accounting-pages.periods.close', $period->id), false)
            ->assertSee('حذف')
            ->assertDontSee(route('accounting-pages.periods.reopen', $period->id), false);
        $this->put(route('accounting-pages.periods.update', $period->id), $this->dates('2026-01-02', '2026-02-01'))
            ->assertRedirect($index)->assertSessionHas('success');
        $this->assertSame('2026-01-02', $period->fresh()->start_date->format('Y-m-d'));
        $this->post(route('accounting-pages.periods.close', $period->id))->assertRedirect($index)->assertSessionHas('success');
        $closedAt = $period->fresh()->first_closed_at;
        $this->assertNotNull($closedAt);
        $this->get($index)->assertOk()->assertSee('مغلقة')
            ->assertSee(route('accounting-pages.periods.reopen', $period->id), false)
            ->assertDontSee('action="'.route('accounting-pages.periods.update', $period->id).'"', false)
            ->assertDontSee(route('accounting-pages.periods.close', $period->id), false)
            ->assertDontSee('حذف');
        $this->post(route('accounting-pages.periods.reopen', $period->id))->assertRedirect($index)->assertSessionHas('success');
        $this->assertTrue($period->fresh()->first_closed_at->equalTo($closedAt));
        $this->get($index)->assertOk()->assertSee('مفتوحة')
            ->assertSee(route('accounting-pages.periods.close', $period->id), false)
            ->assertDontSee('حفظ التواريخ')
            ->assertDontSee('حذف')
            ->assertDontSee(route('accounting-pages.periods.reopen', $period->id), false);
        $this->from($index)->delete(route('accounting-pages.periods.destroy', $period->id))
            ->assertRedirect($index)->assertSessionHasErrors('accounting');
        $this->get($index)->assertOk()->assertSee('role="alert"', false);
    }

    public function test_delete_only_never_closed_period_succeeds(): void
    {
        $period = $this->period($this->owner, '2026-01-01', '2026-01-31');
        $this->actingAs($this->owner)->delete(route('accounting-pages.periods.destroy', $period->id))
            ->assertRedirect(route('accounting-pages.periods.index'))->assertSessionHas('success');
        $this->assertDatabaseMissing('accounting_periods', ['id' => $period->id]);
    }

    public function test_foreign_and_missing_periods_are_not_exposed_by_any_mutation(): void
    {
        $foreign = $this->period($this->other, '2026-01-01', '2026-01-31');
        $this->actingAs($this->owner);
        foreach ([$foreign->id, $foreign->id + 1000] as $id) {
            $this->put(route('accounting-pages.periods.update', $id), $this->dates())->assertNotFound();
            $this->put(route('accounting-pages.periods.update', $id), [])->assertNotFound();
            $this->post(route('accounting-pages.periods.close', $id))->assertNotFound();
            $this->post(route('accounting-pages.periods.reopen', $id))->assertNotFound();
            $this->delete(route('accounting-pages.periods.destroy', $id))->assertNotFound();
        }
        $this->assertSame('open', $foreign->fresh()->status);
    }

    public function test_existing_accounting_pages_still_render(): void
    {
        $this->actingAs($this->owner);
        foreach (['chart.index', 'journals.index', 'ledger', 'trial'] as $name) {
            $this->get(route('accounting-pages.'.$name))->assertOk();
        }
    }
}
