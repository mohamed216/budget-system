<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateAccountingPeriod;
use App\Models\AccountingPeriod;
use App\Models\User;
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
        $this->get($index)->assertOk()->assertSee('2026-02-28')->assertSee('role="alert"', false);
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
