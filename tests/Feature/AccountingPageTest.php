<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class AccountingPageTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;

    private User $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create();
        $this->foreign = User::factory()->create();
    }

    private function account(string $code = '1000', ?User $user = null): ChartAccount
    {
        return (new CreateChartAccount)->execute($user ?? $this->owner, $code, 'Account '.$code, 'asset');
    }

    private function data(array $lines = [], array $overrides = []): array
    {
        return array_replace(['entry_date' => '2026-10-06', 'currency' => config('accounting.currency'), 'reference' => 'UI reference', 'description' => 'UI description', 'lines' => $lines], $overrides);
    }

    private function lines(ChartAccount $account): array
    {
        return [['chart_account_id' => $account->id, 'debit' => '0.10', 'credit' => '0', 'description' => 'Debit line'],
            ['chart_account_id' => $account->id, 'debit' => '0', 'credit' => '0.1', 'description' => 'Credit line']];
    }

    private function draft(array $lines = [], ?User $user = null): JournalEntry
    {
        return (new SaveJournalDraft)->execute($user ?? $this->owner, '2026-10-06', config('accounting.currency'), $lines, 'UI reference', 'UI description');
    }

    public function test_guests_cannot_access_any_page_or_mutation(): void
    {
        foreach (['periods', 'chart-accounts', 'chart-accounts/1/edit', 'journals', 'journals/create', 'journals/1', 'journals/1/edit', 'general-ledger', 'trial-balance'] as $path) {
            $this->get('/accounting/pages/'.$path)->assertRedirect(route('login'));
        }
        foreach ([['POST', 'periods'], ['PUT', 'periods/1'], ['POST', 'periods/1/close'], ['POST', 'periods/1/reopen'], ['DELETE', 'periods/1'], ['POST', 'chart-accounts'], ['PUT', 'chart-accounts/1'], ['DELETE', 'chart-accounts/1'], ['POST', 'journals'], ['PUT', 'journals/1'], ['DELETE', 'journals/1'], ['POST', 'journals/1/post'], ['POST', 'journals/1/reverse']] as [$method, $path]) {
            $this->call($method, '/accounting/pages/'.$path)->assertRedirect(route('login'));
        }
    }

    public function test_navigation_and_empty_pages_render(): void
    {
        $this->actingAs($this->owner);
        foreach (['periods', 'chart-accounts', 'journals', 'journals/create', 'general-ledger', 'trial-balance'] as $path) {
            $response = $this->get('/accounting/pages/'.$path)->assertOk();
            foreach (['chart.index', 'journals.index', 'periods.index', 'ledger', 'trial'] as $route) {
                $response->assertSee(route('accounting-pages.'.$route), false);
            }
        }
        $this->get('/')->assertOk()->assertSee('دليل الحسابات');
    }

    public function test_chart_owned_list_selectors_and_escaped_names(): void
    {
        $owned = $this->account();
        $owned->update(['name' => '<script>owned</script>', 'is_active' => false]);
        $this->account('SECRET', $this->foreign);
        $this->actingAs($this->owner)->get(route('accounting-pages.chart.index'))->assertOk()
            ->assertSee('&lt;script&gt;owned&lt;/script&gt;', false)->assertDontSee('<script>owned</script>', false)->assertSee('غير نشط')->assertDontSee('SECRET');
        $this->get(route('accounting-pages.journals.create'))->assertOk()->assertDontSee('SECRET')->assertSee('disabled', false);
        $this->get(route('accounting-pages.ledger'))->assertOk()->assertDontSee('SECRET');
    }

    public function test_chart_create_update_delete_and_conflicts(): void
    {
        $d = ['code' => ' cash ', 'name' => 'Cash UI', 'type' => 'asset', 'is_active' => '1', 'parent_id' => null];
        $this->actingAs($this->owner)->post(route('accounting-pages.chart.store'), $d)->assertRedirect(route('accounting-pages.chart.index'));
        $account = ChartAccount::ownedBy($this->owner)->firstOrFail();
        $this->assertSame('CASH', $account->code);
        $this->get(route('accounting-pages.chart.edit', $account->id))->assertOk()->assertSee('Cash UI');
        $this->put(route('accounting-pages.chart.update', $account->id), array_replace($d, ['name' => 'Renamed']))->assertRedirect();
        $this->assertSame('Renamed', $account->fresh()->name);
        $child = $this->account('2000');
        $child->update(['parent_id' => $account->id]);
        $this->from(route('accounting-pages.chart.index'))->delete(route('accounting-pages.chart.destroy', $account->id))->assertRedirect()->assertSessionHasErrors('accounting');
        $this->get(route('accounting-pages.chart.index'))->assertOk()->assertSee('child');
        $this->from(route('accounting-pages.chart.edit', $account->id))->put(route('accounting-pages.chart.update', $account->id), array_replace($d, ['parent_id' => $child->id]))->assertSessionHasErrors('accounting');
        $this->get(route('accounting-pages.chart.edit', $account->id))->assertOk()->assertSee('cycle');
        $this->delete(route('accounting-pages.chart.destroy', $child->id))->assertRedirect();
        $this->delete(route('accounting-pages.chart.destroy', $account->id))->assertRedirect();
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $account->id]);
    }

    public function test_chart_validation_preserves_values_and_foreign_access_is_not_found(): void
    {
        $this->actingAs($this->owner)->from(route('accounting-pages.chart.index'))->post(route('accounting-pages.chart.store'), ['code' => 'bad code', 'name' => 'Keep me', 'type' => 'asset', 'is_active' => '1'])->assertSessionHasErrors('code');
        $this->get(route('accounting-pages.chart.index'))->assertOk()->assertSee('Keep me');
        $foreign = $this->account('FOREIGN', $this->foreign);
        $this->get(route('accounting-pages.chart.edit', $foreign->id))->assertNotFound();
        $this->delete(route('accounting-pages.chart.destroy', $foreign->id))->assertNotFound();
    }

    public function test_journal_index_owned_only_and_foreign_pages_are_inaccessible(): void
    {
        $own = $this->draft();
        $foreign = $this->draft(user: $this->foreign);
        $foreign->update(['reference' => 'SECRET JOURNAL']);
        $this->actingAs($this->owner)->get(route('accounting-pages.journals.index'))->assertOk()->assertSee('UI reference')->assertDontSee('SECRET JOURNAL');
        foreach (['show', 'edit'] as $method) {
            $this->get(route('accounting-pages.journals.'.$method, $foreign->id))->assertNotFound();
        }
        $this->post(route('accounting-pages.journals.post', $foreign->id))->assertNotFound();
        $this->get(route('accounting-pages.journals.show', $own->id))->assertOk();
    }

    public function test_native_empty_draft_and_lines_create_edit_and_delete(): void
    {
        $d = $this->data();
        unset($d['lines']);
        $this->actingAs($this->owner)->post(route('accounting-pages.journals.store'), $d)->assertRedirect();
        $empty = JournalEntry::ownedBy($this->owner)->firstOrFail();
        $this->assertCount(0, $empty->lines);
        $account = $this->account();
        $this->post(route('accounting-pages.journals.store'), $this->data($this->lines($account)))->assertRedirect();
        $journal = JournalEntry::ownedBy($this->owner)->latest('id')->firstOrFail();
        $this->get(route('accounting-pages.journals.edit', $journal->id))->assertOk()->assertSee('name="version"', false)->assertSee('value="0.10"', false);
        $this->put(route('accounting-pages.journals.update', $journal->id), $this->data($this->lines($account), ['version' => 1, 'description' => 'Edited']))->assertRedirect(route('accounting-pages.journals.show', $journal->id));
        $this->assertSame(2, $journal->fresh()->version);
        $this->assertSame('Edited', $journal->fresh()->description);
        $this->delete(route('accounting-pages.journals.destroy', $journal->id))->assertRedirect();
        $this->assertDatabaseMissing('journal_lines', ['journal_entry_id' => $journal->id]);
    }

    public function test_stale_version_and_posting_conflicts_display_without_losing_input(): void
    {
        $journal = $this->draft();
        $edit = route('accounting-pages.journals.edit', $journal->id);
        $this->actingAs($this->owner)->from($edit)->put(route('accounting-pages.journals.update', $journal->id), $this->data(overrides: ['version' => 99, 'description' => 'Unsaved input']))->assertRedirect($edit)->assertSessionHasErrors('accounting');
        $this->get($edit)->assertOk()->assertSee('Stale journal version')->assertSee('Unsaved input')->assertSee('value="99"', false);
        $show = route('accounting-pages.journals.show', $journal->id);
        $this->from($show)->post(route('accounting-pages.journals.post', $journal->id))->assertSessionHasErrors('accounting');
        $this->get($show)->assertOk()->assertSee('at least two');
    }

    public function test_posted_detail_is_read_only_and_retry_is_unchanged(): void
    {
        $journal = $this->draft($this->lines($this->account()));
        $this->actingAs($this->owner)->get(route('accounting-pages.journals.show', $journal->id))->assertOk()->assertSee('confirm(', false)->assertSee('ترحيل القيد');
        $this->post(route('accounting-pages.journals.post', $journal->id))->assertRedirect();
        $snapshot = $journal->fresh()->getAttributes();
        $this->get(route('accounting-pages.journals.show', $journal->id))->assertOk()->assertSee('للقراءة فقط')->assertSee('0.10')->assertSee('وقت الترحيل')->assertDontSee('تعديل المسودة')->assertDontSee('حذف المسودة')->assertDontSee('name="version"', false);
        $this->get(route('accounting-pages.journals.edit', $journal->id))->assertForbidden();
        $this->delete(route('accounting-pages.journals.destroy', $journal->id))->assertForbidden();
        $this->post(route('accounting-pages.journals.post', $journal->id))->assertRedirect();
        $this->assertSame($snapshot, $journal->fresh()->getAttributes());
    }

    public function test_exact_reports_include_inactive_zero_accounts_and_exclude_foreign_data(): void
    {
        $account = $this->account();
        $journal = $this->draft($this->lines($account));
        (new PostJournalEntry)->execute($this->owner, $journal->id);
        $account->update(['is_active' => false]);
        $this->account('ZERO');
        $this->account('SECRET', $this->foreign);
        $this->actingAs($this->owner)->get(route('accounting-pages.ledger', ['chart_account_id' => $account->id, 'date_from' => '2026-10-06', 'date_to' => '2026-10-06']))->assertOk()->assertSee('0.10')->assertSee('0.00')->assertSee('Debit line')->assertSee('UI reference')->assertDontSee('SECRET');
        $this->get(route('accounting-pages.trial'))->assertOk()->assertSee('0.10')->assertSee('ZERO')->assertSee('Account 1000')->assertDontSee('SECRET');
        $foreign = ChartAccount::ownedBy($this->foreign)->firstOrFail();
        $this->get(route('accounting-pages.ledger', ['chart_account_id' => $foreign->id]))->assertNotFound();
    }

    public function test_report_dates_and_integrity_errors_are_visible(): void
    {
        $account = $this->account();
        $this->actingAs($this->owner)->from(route('accounting-pages.ledger'))->get(route('accounting-pages.ledger', ['chart_account_id' => $account->id, 'date_from' => '2026-10-07', 'date_to' => '2026-10-06']))->assertSessionHasErrors('date_to');
        $this->get(route('accounting-pages.ledger'))->assertOk()->assertSee('date to');
        $this->from(route('accounting-pages.trial'))->get(route('accounting-pages.trial', ['as_of' => 'invalid']))->assertSessionHasErrors('as_of');
        $this->get(route('accounting-pages.trial'))->assertOk()->assertSee('as of');
        $journal = $this->draft([$this->lines($account)[0]]);
        DB::table('journal_entries')->where('id', $journal->id)->update(['status' => 'posted', 'posted_at' => now()]);
        $this->get(route('accounting-pages.trial'))->assertStatus(409)->assertSee('out of balance')->assertDontSee('SQLSTATE');
    }

    public function test_ledger_and_trial_pages_reject_mixed_currency_and_ledger_labels_actual_historical_currency(): void
    {
        $account = $this->account();
        $configured = config('accounting.currency');
        try {
            $first = $this->draft($this->lines($account));
            (new PostJournalEntry)->execute($this->owner, $first->id);
            config(['accounting.currency' => $configured === 'SAR' ? 'USD' : 'SAR']);
            $this->actingAs($this->owner)->get(route('accounting-pages.ledger', ['chart_account_id' => $account->id]))
                ->assertOk()->assertSee(' / '.$configured);
            $this->get(route('accounting-pages.trial'))->assertOk()->assertSee($configured.' — القيود المرحلة');

            $second = $this->draft($this->lines($account));
            (new PostJournalEntry)->execute($this->owner, $second->id);
            $this->get(route('accounting-pages.ledger', ['chart_account_id' => $account->id]))
                ->assertStatus(409)->assertSee('اختلاف عملات القيود المرحلة')->assertDontSee('SQLSTATE');
            $this->get(route('accounting-pages.trial'))
                ->assertStatus(409)->assertSee('تعارض في بيانات القيود المرحلة')->assertDontSee('SQLSTATE');
        } finally {
            config(['accounting.currency' => $configured]);
        }
    }

    public function test_forms_never_emit_protected_accounting_fields_and_money_stays_text(): void
    {
        $this->account();
        $this->actingAs($this->owner);
        foreach (['chart.index', 'journals.create'] as $route) {
            $response = $this->get(route('accounting-pages.'.$route))->assertOk();
            foreach (['user_id', 'status', 'posted_at', 'journal_entry_id', 'line_number', 'id'] as $protected) {
                $response->assertDontSee('name="'.$protected.'"', false)->assertDontSee(']['.$protected.']', false);
            }
            $response->assertSee('name="_token"', false);
        }
        $this->get(route('accounting-pages.journals.create'))->assertDontSee('name="version"', false)->assertSee('inputmode="decimal"', false)->assertSee('data-add-line', false)->assertSee('data-remove-line', false);
        $this->assertStringNotContainsString('parseFloat', file_get_contents(resource_path('js/accounting.js')));
        $this->assertStringNotContainsString('Number(', file_get_contents(resource_path('js/accounting.js')));
    }

    public function test_page_database_errors_do_not_expose_sql_or_exception_details(): void
    {
        DB::listen(function () {
            throw new \Illuminate\Database\QueryException('mysql_testing', 'private_sql_text', [], new \PDOException('private_database_detail'));
        });
        $this->actingAs($this->owner)->get(route('accounting-pages.chart.index'))->assertStatus(500)
            ->assertSee('تعذر تحميل')->assertDontSee('private_sql_text')->assertDontSee('private_database_detail')->assertDontSee('Stack trace');
    }

    public function test_negative_signed_report_values_remain_exact(): void
    {
        $debit = $this->account('1000');
        $credit = $this->account('2000');
        $lines = $this->lines($debit);
        $lines[1]['chart_account_id'] = $credit->id;
        $journal = $this->draft($lines);
        (new PostJournalEntry)->execute($this->owner, $journal->id);
        $this->actingAs($this->owner)->get(route('accounting-pages.ledger', ['chart_account_id' => $credit->id]))->assertOk()->assertSee('-0.10');
        $this->get(route('accounting-pages.trial'))->assertOk()->assertSee('-0.10')->assertSee('0.10');
    }

    public function test_malformed_line_collections_show_errors_without_breaking_the_form(): void
    {
        $this->actingAs($this->owner);
        foreach (['bad', ['bad'], [['debit' => ['bad'], 'credit' => '0', 'chart_account_id' => ['bad']]]] as $lines) {
            $this->from(route('accounting-pages.journals.create'))->post(route('accounting-pages.journals.store'), $this->data(overrides: ['lines' => $lines]))->assertSessionHasErrors();
            $this->get(route('accounting-pages.journals.create'))->assertOk()->assertSee('يرجى تصحيح');
        }
    }

    public function test_draft_validation_keeps_exact_strings_and_rejects_protected_or_foreign_inputs(): void
    {
        $account = $this->account();
        $create = route('accounting-pages.journals.create');
        $lines = $this->lines($account);
        $lines[0]['debit'] = ' 0.10 ';
        $this->actingAs($this->owner)->from($create)->post(route('accounting-pages.journals.store'), $this->data($lines))->assertSessionHasErrors('lines.0.debit');
        $this->get($create)->assertOk()->assertSee('value=" 0.10 "', false);
        $this->post(route('accounting-pages.journals.store'), $this->data(overrides: ['user_id' => $this->foreign->id]))->assertSessionHasErrors('user_id');
        $foreign = $this->account('FOREIGN', $this->foreign);
        $this->post(route('accounting-pages.journals.store'), $this->data($this->lines($foreign)))->assertSessionHasErrors('lines');
        $this->assertDatabaseCount('journal_entries', 0);
    }
}
