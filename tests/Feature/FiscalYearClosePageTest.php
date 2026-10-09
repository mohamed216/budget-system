<?php

namespace Tests\Feature;

use App\Accounting\Actions\CloseFiscalYear;
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

class FiscalYearClosePageTest extends TestCase
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
        $this->withoutVite();
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->asset = $this->account($this->owner, '1000', 'نقدية', 'asset');
        $this->retained = $this->account($this->owner, '3000', 'أرباح محتجزة', 'equity');
        $this->revenue = $this->account($this->owner, '4000', 'إيراد', 'revenue');
    }

    private function account(User $owner, string $code, string $name, string $type): ChartAccount
    {
        $account = (new CreateChartAccount)->execute($owner, $code, $name, $type);
        $account->update(['cash_role' => 'non_cash']);

        return $account;
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => config('accounting.currency'),
            'retained_earnings_account_id' => $this->retained->id,
        ], $changes);
    }

    private function close(User $owner, int $retainedId, string $start = '2026-01-01', string $end = '2026-12-31'): FiscalYearClose
    {
        return (new CloseFiscalYear)->execute($owner, $start, $end, config('accounting.currency'), $retainedId);
    }

    private function journal(bool $post = true): \App\Models\JournalEntry
    {
        $draft = (new SaveJournalDraft)->execute($this->owner, '2026-06-15', config('accounting.currency'), [
            ['chart_account_id' => $this->asset->id, 'debit' => '0.01', 'credit' => '0.00'],
            ['chart_account_id' => $this->revenue->id, 'debit' => '0.00', 'credit' => '0.01'],
        ]);

        return $post ? (new PostJournalEntry)->execute($this->owner, $draft->id) : $draft;
    }

    public function test_only_four_authenticated_read_only_after_close_page_routes_exist(): void
    {
        foreach (['fiscal-year-closes', 'fiscal-year-closes/create', 'fiscal-year-closes/1'] as $path) {
            $this->get('/accounting/pages/'.$path)->assertRedirect(route('login'));
        }
        $this->post('/accounting/pages/fiscal-year-closes', $this->payload())->assertRedirect(route('login'));

        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->getName() ?? '', 'accounting-pages.fiscal-year-closes.'));
        $this->assertCount(4, $routes);
        foreach ([
            'index' => ['accounting/pages/fiscal-year-closes', ['GET', 'HEAD']],
            'create' => ['accounting/pages/fiscal-year-closes/create', ['GET', 'HEAD']],
            'store' => ['accounting/pages/fiscal-year-closes', ['POST']],
            'show' => ['accounting/pages/fiscal-year-closes/{fiscalYearClose}', ['GET', 'HEAD']],
        ] as $name => [$uri, $methods]) {
            $route = $routes->first(fn ($candidate) => $candidate->getName() === 'accounting-pages.fiscal-year-closes.'.$name);
            $this->assertNotNull($route);
            $this->assertSame($uri, $route->uri());
            $this->assertSame($methods, $route->methods());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('web', $route->gatherMiddleware());
        }
        $this->actingAs($this->owner);
        foreach (['put', 'patch', 'delete'] as $method) {
            $this->{$method}('/accounting/pages/fiscal-year-closes/1')->assertStatus(405);
        }
        $this->post('/accounting/pages/fiscal-year-closes/1/reopen')->assertNotFound();
    }

    public function test_index_is_owner_scoped_ordered_and_navigation_is_visible_on_existing_pages(): void
    {
        $later = $this->close($this->owner, $this->retained->id, '2027-01-01', '2027-12-31');
        $earlier = $this->close($this->owner, $this->retained->id);
        $foreignRetained = $this->account($this->other, '3999', 'سري', 'equity');
        $foreign = $this->close($this->other, $foreignRetained->id);

        $index = route('accounting-pages.fiscal-year-closes.index');
        $response = $this->actingAs($this->owner)->get($index)->assertOk()
            ->assertSee('إقفال السنة المالية')->assertSee('حساب الأرباح المحتجزة')
            ->assertSee(route('accounting-pages.fiscal-year-closes.show', $earlier->id), false)
            ->assertSee(route('accounting-pages.fiscal-year-closes.show', $later->id), false)
            ->assertDontSee(route('accounting-pages.fiscal-year-closes.show', $foreign->id), false)
            ->assertDontSee('سري');
        $response->assertSeeInOrder(['2026-01-01', '2027-01-01']);
        $this->get(route('accounting-pages.journals.index'))->assertOk()
            ->assertSee($index, false)->assertSee('إقفال السنة المالية');
    }

    public function test_create_form_selects_only_owned_active_equity_and_has_csrf_and_permanence_warning(): void
    {
        $inactive = $this->account($this->owner, '3001', 'غير نشط', 'equity');
        $inactive->is_active = false;
        $inactive->save();
        $this->account($this->other, '3002', 'سري', 'equity');

        $this->actingAs($this->owner)->get(route('accounting-pages.fiscal-year-closes.create'))->assertOk()
            ->assertSee('تاريخ البداية')->assertSee('تاريخ النهاية')->assertSee('العملة')
            ->assertSee('حساب الأرباح المحتجزة')->assertSee('أرباح محتجزة')
            ->assertSee('value="'.$this->retained->id.'"', false)
            ->assertDontSee('value="'.$this->asset->id.'"', false)
            ->assertDontSee('غير نشط')->assertDontSee('سري')
            ->assertSee('name="_token"', false)->assertSee('لا يمكن إعادة فتحها');
    }

    public function test_profitable_close_redirects_to_read_only_show_and_links_posted_journal(): void
    {
        $this->journal();
        $create = route('accounting-pages.fiscal-year-closes.create');
        $this->actingAs($this->owner)->from($create)
            ->post(route('accounting-pages.fiscal-year-closes.store'), $this->payload())
            ->assertRedirect()->assertSessionHas('success');
        $close = FiscalYearClose::ownedBy($this->owner)->firstOrFail();
        $this->assertNotNull($close->journal_entry_id);
        $this->assertTrue($close->journalEntry->isPosted());
        $show = route('accounting-pages.fiscal-year-closes.show', $close->id);
        $this->get($show)->assertOk()->assertSee('للقراءة فقط')
            ->assertSee($close->journalEntry->reference)
            ->assertSee(route('accounting-pages.journals.show', $close->journal_entry_id), false)
            ->assertDontSee('name="start_date"', false)
            ->assertDontSee('name="retained_earnings_account_id"', false);
        $this->get(route('accounting-pages.fiscal-year-closes.index'))->assertOk()
            ->assertSee(route('accounting-pages.journals.show', $close->journal_entry_id), false);
    }

    public function test_zero_activity_close_displays_no_journal_required(): void
    {
        $this->actingAs($this->owner)->post(route('accounting-pages.fiscal-year-closes.store'), $this->payload())
            ->assertRedirect()->assertSessionHas('success');
        $close = FiscalYearClose::ownedBy($this->owner)->firstOrFail();
        $this->assertNull($close->journal_entry_id);
        $this->get(route('accounting-pages.fiscal-year-closes.show', $close->id))->assertOk()
            ->assertSee('لم يلزم إنشاء قيد ختامي لأن أرصدة الإيرادات والمصروفات المطلوب إقفالها كانت صفراً.');
        $this->get(route('accounting-pages.fiscal-year-closes.index'))->assertOk()
            ->assertSee('لم يلزم إنشاء قيد ختامي لأن أرصدة الإيرادات والمصروفات المطلوب إقفالها كانت صفراً.');
    }

    public function test_strict_validation_errors_are_arabic_and_preserve_exact_old_input(): void
    {
        $create = route('accounting-pages.fiscal-year-closes.create');
        $this->actingAs($this->owner);
        foreach ([
            ['currency', ' '.config('accounting.currency').' '],
            ['start_date', ' 2026-01-01 '],
            ['end_date', '2026-12-31 '],
            ['retained_earnings_account_id', ' '.$this->retained->id.' '],
        ] as [$field, $value]) {
            $this->from($create)->post(route('accounting-pages.fiscal-year-closes.store'), $this->payload([$field => $value]))
                ->assertRedirect($create)->assertSessionHasErrors($field)
                ->assertSessionHas('_old_input.'.$field, $value);
        }
        $this->from($create)->post(route('accounting-pages.fiscal-year-closes.store'), $this->payload(['end_date' => '2025-12-31']))
            ->assertRedirect($create)->assertSessionHasErrors('end_date');
        $this->get($create)->assertOk()->assertSee('role="alert"', false)
            ->assertSee('يجب أن يكون تاريخ النهاية');
        $this->assertDatabaseCount('fiscal_year_closes', 0);
    }

    public function test_overlap_and_draft_blockers_show_safe_arabic_conflicts_with_old_input(): void
    {
        $this->close($this->owner, $this->retained->id);
        $create = route('accounting-pages.fiscal-year-closes.create');
        $this->actingAs($this->owner)->from($create)
            ->post(route('accounting-pages.fiscal-year-closes.store'), $this->payload())
            ->assertRedirect($create)->assertSessionHasErrors('accounting')
            ->assertSessionHas('_old_input.start_date', '2026-01-01');
        $this->get($create)->assertOk()->assertSee('تتداخل الفترة المختارة')->assertDontSee('SQLSTATE');

        $draft = (new SaveJournalDraft)->execute($this->owner, '2027-06-15', config('accounting.currency'), []);
        $this->from($create)->post(route('accounting-pages.fiscal-year-closes.store'), $this->payload([
            'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
        ]))->assertRedirect($create)->assertSessionHasErrors('accounting');
        $this->get($create)->assertOk()->assertSee('يجب معالجة مسودات القيود اليومية')->assertDontSee('SQLSTATE');
        $this->assertNotNull($draft);

        $opening = (new CreateOpeningBalanceDraft)->execute($this->owner, '2028-06-15', config('accounting.currency'), [
            ['chart_account_id' => $this->asset->id, 'debit' => '0.01', 'credit' => '0.00'],
            ['chart_account_id' => $this->retained->id, 'debit' => '0.00', 'credit' => '0.01'],
        ]);
        $this->from($create)->post(route('accounting-pages.fiscal-year-closes.store'), $this->payload([
            'start_date' => '2028-01-01', 'end_date' => '2028-12-31',
        ]))->assertRedirect($create)->assertSessionHasErrors('accounting');
        $this->get($create)->assertOk()->assertSee('يجب معالجة مسودات الأرصدة الافتتاحية')->assertDontSee('SQLSTATE');
        $this->assertNotNull($opening);
        $this->assertDatabaseCount('fiscal_year_closes', 1);
    }

    public function test_foreign_and_missing_show_are_not_found(): void
    {
        $foreignRetained = $this->account($this->other, '3999', 'سري', 'equity');
        $foreign = $this->close($this->other, $foreignRetained->id);
        $this->actingAs($this->owner);
        $this->get(route('accounting-pages.fiscal-year-closes.show', $foreign->id))->assertNotFound();
        $this->get(route('accounting-pages.fiscal-year-closes.show', $foreign->id + 1000))->assertNotFound();
    }

    public function test_unexpected_database_and_internal_errors_are_sanitized(): void
    {
        foreach ([
            fn () => new QueryException('mysql_testing', 'select secret from fiscal_year_closes', [],
                new PDOException('SQLSTATE[HY000]: private database detail')),
            fn () => new RuntimeException('private filesystem path /secret/path'),
        ] as $failure) {
            Event::listen('eloquent.creating: '.FiscalYearClose::class, function () use ($failure): void {
                throw $failure();
            });
            try {
                $this->actingAs($this->owner)->post(route('accounting-pages.fiscal-year-closes.store'), $this->payload())
                    ->assertStatus(500)->assertSee('تعذر تحميل الصفحة المحاسبية.')
                    ->assertDontSee('SQLSTATE')->assertDontSee('fiscal_year_closes')
                    ->assertDontSee('private filesystem')->assertDontSee('/secret/path');
            } finally {
                Event::forget('eloquent.creating: '.FiscalYearClose::class);
            }
        }
        $this->assertDatabaseCount('fiscal_year_closes', 0);
    }

    public function test_views_have_no_client_side_accounting_arithmetic_and_existing_pages_render(): void
    {
        foreach (['index', 'create', 'show'] as $view) {
            $source = file_get_contents(resource_path('views/accounting/fiscal-year-closes/'.$view.'.blade.php'));
            $this->assertIsString($source);
            $this->assertDoesNotMatchRegularExpression('/<script\b|parseFloat|\bNumber\s*\(/i', $source);
        }
        $this->actingAs($this->owner)->get(route('accounting-pages.journals.index'))->assertOk();
        $this->get(route('accounting-pages.periods.index'))->assertOk();
    }
}
