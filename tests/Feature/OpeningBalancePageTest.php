<?php

namespace Tests\Feature;

use App\Accounting\Actions\CloseAccountingPeriod;
use App\Accounting\Actions\CreateAccountingPeriod;
use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\CreateOpeningBalanceDraft;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class OpeningBalancePageTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;
    private User $other;
    private int $assetId;
    private int $equityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->assetId = (new CreateChartAccount)->execute($this->owner, '1000', 'النقدية', 'asset')->id;
        $this->equityId = (new CreateChartAccount)->execute($this->owner, '3000', 'رأس المال', 'equity')->id;
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
        $actor ??= $this->owner;
        $data = $this->payload();
        if (! $actor->is($this->owner)) {
            $data['lines'][0]['chart_account_id'] = (new CreateChartAccount)->execute($actor, '1000', 'سري', 'asset')->id;
            $data['lines'][1]['chart_account_id'] = (new CreateChartAccount)->execute($actor, '3000', 'سري', 'equity')->id;
        }

        return (new CreateOpeningBalanceDraft)->execute($actor, $data['opening_date'], $data['currency'], $data['lines']);
    }

    public function test_guest_cannot_access_pages_or_submit_mutations(): void
    {
        foreach (['opening-balances', 'opening-balances/1'] as $path) {
            $this->get('/accounting/pages/'.$path)->assertRedirect(route('login'));
        }
        foreach ([
            ['POST', 'opening-balances'], ['PUT', 'opening-balances/1'],
            ['DELETE', 'opening-balances/1'], ['POST', 'opening-balances/1/post'],
        ] as [$method, $path]) {
            $this->call($method, '/accounting/pages/'.$path)->assertRedirect(route('login'));
        }
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->getName() ?? '', 'accounting-pages.opening-balances.'));
        $this->assertCount(6, $routes);
        foreach ($routes as $route) {
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('web', $route->gatherMiddleware());
        }
    }

    public function test_index_navigation_and_owner_only_listing_render(): void
    {
        $mine = $this->draft();
        $foreign = $this->draft($this->other);
        $this->actingAs($this->owner)->get(route('accounting-pages.opening-balances.index'))->assertOk()
            ->assertSee('الأرصدة الافتتاحية')->assertSee('تاريخ الافتتاح')
            ->assertSee('name="lines[0][debit]"', false)
            ->assertSee(route('accounting-pages.opening-balances.show', $mine->id), false)
            ->assertDontSee(route('accounting-pages.opening-balances.show', $foreign->id), false)
            ->assertDontSee('سري');
        $this->get(route('accounting-pages.journals.index'))->assertOk()
            ->assertSee('الأرصدة الافتتاحية')
            ->assertSee(route('accounting-pages.opening-balances.index'), false);
    }

    public function test_create_edit_delete_preserve_exact_decimal_strings(): void
    {
        $index = route('accounting-pages.opening-balances.index');
        $this->actingAs($this->owner)->from($index)
            ->post(route('accounting-pages.opening-balances.store'), $this->payload('0.01'))
            ->assertRedirect()->assertSessionHas('success');
        $batch = OpeningBalanceBatch::ownedBy($this->owner)->firstOrFail();
        $show = route('accounting-pages.opening-balances.show', $batch->id);
        $this->get($show)->assertOk()->assertSee('value="0.01"', false)
            ->assertSee('تعديل المسودة')->assertSee('ترحيل الأرصدة الافتتاحية')
            ->assertSee('حذف المسودة')->assertSee('data-add-line', false)->assertSee('data-remove-line', false)
            ->assertSee('inputmode="decimal"', false);
        $this->from($show)->put(route('accounting-pages.opening-balances.update', $batch->id), $this->payload('9999999999999.99', '2026-02-01'))
            ->assertRedirect($show)->assertSessionHas('success');
        $this->assertSame('2026-02-01', $batch->fresh()->opening_date->toDateString());
        $this->assertSame('9999999999999.99', $batch->fresh()->lines->first()->debit);
        $this->get($show)->assertSee('9999999999999.99');
        $this->from($show)->delete(route('accounting-pages.opening-balances.destroy', $batch->id))
            ->assertRedirect($index)->assertSessionHas('success');
        $this->assertDatabaseMissing('opening_balance_batches', ['id' => $batch->id]);
    }

    public function test_post_creates_linked_journal_and_posted_page_has_no_mutation_controls(): void
    {
        $batch = $this->draft();
        $show = route('accounting-pages.opening-balances.show', $batch->id);
        $this->actingAs($this->owner)->from($show)
            ->post(route('accounting-pages.opening-balances.post', $batch->id))
            ->assertRedirect($show)->assertSessionHas('success');
        $batch = $batch->fresh();
        $journal = $batch->journalEntry;
        $this->assertTrue($batch->isPosted());
        $this->assertTrue($journal->isPosted());
        $this->get($show)->assertOk()->assertSee('للقراءة فقط')
            ->assertSee('القيد المرتبط')->assertSee('OB-'.$batch->id)
            ->assertSee(route('accounting-pages.journals.show', $journal->id), false)
            ->assertDontSee('تعديل المسودة')->assertDontSee('حذف المسودة')
            ->assertDontSee('name="opening_date"', false)
            ->assertDontSee(route('accounting-pages.opening-balances.post', $batch->id), false);
        $this->from($show)->put(route('accounting-pages.opening-balances.update', $batch->id), $this->payload())
            ->assertRedirect($show)->assertSessionHasErrors('accounting');
        $this->from($show)->delete(route('accounting-pages.opening-balances.destroy', $batch->id))
            ->assertRedirect($show)->assertSessionHasErrors('accounting');
        $this->post(route('accounting-pages.opening-balances.post', $batch->id))->assertRedirect($show);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame($journal->id, $batch->fresh()->journal_entry_id);
    }

    public function test_foreign_and_missing_batch_are_not_exposed(): void
    {
        $foreign = $this->draft($this->other);
        $this->actingAs($this->owner);
        foreach ([$foreign->id, $foreign->id + 100000] as $id) {
            $this->get(route('accounting-pages.opening-balances.show', $id))->assertNotFound();
            $this->put(route('accounting-pages.opening-balances.update', $id), ['opening_date' => 'bad'])->assertNotFound();
            $this->delete(route('accounting-pages.opening-balances.destroy', $id))->assertNotFound();
            $this->post(route('accounting-pages.opening-balances.post', $id))->assertNotFound();
        }
        $this->assertTrue($foreign->fresh()->isDraft());
    }

    public function test_validation_errors_are_arabic_safe_and_preserve_form_input(): void
    {
        $index = route('accounting-pages.opening-balances.index');
        $bad = $this->payload('0.01', '2026-2-01');
        $this->actingAs($this->owner)->from($index)->post(route('accounting-pages.opening-balances.store'), $bad)
            ->assertRedirect($index)->assertSessionHasErrors('opening_date');
        $this->get($index)->assertOk()->assertSee('أدخل تاريخ الافتتاح بصيغة')->assertSee('2026-2-01');
        $bad = $this->payload();
        $bad['lines'][0]['debit'] = ' 100.00 ';
        $this->from($index)->post(route('accounting-pages.opening-balances.store'), $bad)
            ->assertSessionHasErrors('lines.0.debit');
        $this->get($index)->assertSee('أدخل مبلغاً عشرياً نصياً صحيحاً')->assertDontSee('SQLSTATE');
        $this->assertDatabaseCount('opening_balance_batches', 0);
    }

    public function test_closed_period_conflicts_are_sanitized_and_visible(): void
    {
        $batch = $this->draft();
        $period = (new CreateAccountingPeriod)->execute($this->owner, '2026-01-15', '2026-01-15');
        (new CloseAccountingPeriod)->execute($this->owner, $period->id);
        $index = route('accounting-pages.opening-balances.index');
        $show = route('accounting-pages.opening-balances.show', $batch->id);
        $this->actingAs($this->owner)->from($index)
            ->post(route('accounting-pages.opening-balances.store'), $this->payload())
            ->assertRedirect($index)->assertSessionHasErrors('accounting');
        $this->get($index)->assertSee('تاريخ الافتتاح يقع ضمن فترة محاسبية مغلقة.')
            ->assertDontSee('SQLSTATE')->assertDontSee('accounting_obb_');
        $this->from($show)->put(route('accounting-pages.opening-balances.update', $batch->id), $this->payload())
            ->assertRedirect($show)->assertSessionHasErrors('accounting');
        $this->get($show)->assertSee('تاريخ الافتتاح يقع ضمن فترة محاسبية مغلقة.');
        $this->from($show)->post(route('accounting-pages.opening-balances.post', $batch->id))
            ->assertRedirect($show)->assertSessionHasErrors('accounting');
        $this->get($show)->assertSee('تاريخ الافتتاح يقع ضمن فترة محاسبية مغلقة.');
        $this->assertTrue($batch->fresh()->isDraft());
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_duplicate_inactive_and_unbalanced_inputs_have_safe_arabic_errors(): void
    {
        $index = route('accounting-pages.opening-balances.index');
        $this->actingAs($this->owner);
        $duplicate = $this->payload();
        $duplicate['lines'][1]['chart_account_id'] = $this->assetId;
        $this->from($index)->post(route('accounting-pages.opening-balances.store'), $duplicate)
            ->assertRedirect($index)->assertSessionHasErrors('accounting');
        $this->get($index)->assertSee('الحساب مكرر داخل الدفعة.');
        $this->owner->chartAccounts()->findOrFail($this->assetId)->update(['is_active' => false]);
        $this->from($index)->post(route('accounting-pages.opening-balances.store'), $this->payload())
            ->assertSessionHasErrors('accounting');
        $this->get($index)->assertSee('لا يمكن استخدام حساب غير نشط أو غير متاح.');
        $this->owner->chartAccounts()->findOrFail($this->assetId)->update(['is_active' => true]);
        $unbalanced = $this->payload();
        $unbalanced['lines'][1]['credit'] = '99.99';
        $this->from($index)->post(route('accounting-pages.opening-balances.store'), $unbalanced)
            ->assertSessionHasErrors('accounting');
        $this->get($index)->assertSee('الأرصدة الافتتاحية غير متوازنة أو غير صالحة.')->assertDontSee('SQLSTATE');
        $this->assertDatabaseCount('opening_balance_batches', 0);
    }

    public function test_templates_do_not_perform_client_side_amount_arithmetic(): void
    {
        foreach (['opening-balance-fields.blade.php', 'opening-balance-line.blade.php', 'opening-balances.blade.php', 'opening-balance.blade.php'] as $view) {
            $contents = file_get_contents(resource_path('views/accounting/'.$view));
            $this->assertDoesNotMatchRegularExpression('/\b(?:float|double|floatval|number_format|parseFloat)\b|\bNumber\s*\(/i', $contents);
        }
    }
}
