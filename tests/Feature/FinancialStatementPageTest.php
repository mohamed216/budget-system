<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Queries\IncomeStatementQuery;
use App\Accounting\Queries\StatementOfFinancialPositionQuery;
use App\Http\Controllers\Accounting\Pages\FinancialStatementPageController;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class FinancialStatementPageTest extends TestCase
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

    private function account(string $code, string $type, ?User $owner = null): ChartAccount
    {
        $account = (new CreateChartAccount)->execute($owner ?? $this->owner, $code, 'Account '.$code, $type);
        $account->update(['cash_role' => 'non_cash']);

        return $account;
    }

    private function line(ChartAccount $account, string $debit, string $credit): array
    {
        return ['chart_account_id' => $account->id, 'debit' => $debit, 'credit' => $credit];
    }

    private function journal(string $date, array $lines, bool $post = true, ?User $owner = null): JournalEntry
    {
        $owner ??= $this->owner;
        $draft = (new SaveJournalDraft)->execute($owner, $date, config('accounting.currency'), $lines);

        return $post ? (new PostJournalEntry)->execute($owner, $draft->id) : $draft;
    }

    private function income(string $from, string $to): string
    {
        return route('accounting-pages.income-statement', ['date_from' => $from, 'date_to' => $to]);
    }

    private function position(string $asOf): string
    {
        return route('accounting-pages.balance-sheet', ['as_of' => $asOf]);
    }

    public function test_routes_are_authenticated_read_only_and_guests_are_redirected(): void
    {
        foreach (['income-statement' => 'income-statement', 'balance-sheet' => 'balance-sheet'] as $name => $path) {
            $route = Route::getRoutes()->getByName('accounting-pages.'.$name);
            $this->assertNotNull($route);
            $this->assertSame('accounting/pages/'.$path, $route->uri());
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $this->assertSame(FinancialStatementPageController::class, $route->getControllerClass());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->get('/accounting/pages/'.$path)->assertRedirect(route('login'));
        }
    }

    public function test_empty_pages_show_arabic_headings_navigation_filters_and_configured_currency(): void
    {
        $this->actingAs($this->owner);
        $income = $this->get(route('accounting-pages.income-statement'))->assertOk()
            ->assertSee('قائمة الدخل')->assertSee('من تاريخ')->assertSee('إلى تاريخ')
            ->assertSee('الإيرادات')->assertSee('المصروفات')->assertSee('إجمالي الإيرادات')
            ->assertSee('إجمالي المصروفات')->assertSee('صافي الربح / الخسارة')
            ->assertSee('العملة:')->assertSee(config('accounting.currency'))
            ->assertSee('0.00')->assertSee('name="date_from"', false)->assertSee('name="date_to"', false);
        $income->assertSee(route('accounting-pages.balance-sheet'), false)
            ->assertSee(route('accounting-pages.ledger'), false)->assertSee(route('accounting-pages.trial'), false);
        $this->get(route('accounting-pages.balance-sheet'))->assertOk()
            ->assertSee('قائمة المركز المالي')->assertSee('كما في تاريخ')
            ->assertSee('الأصول')->assertSee('الالتزامات')->assertSee('حقوق الملكية')
            ->assertSee('الربح / الخسارة التراكمية غير المقفلة')
            ->assertSee('إجمالي الأصول')->assertSee('إجمالي الالتزامات')->assertSee('إجمالي حقوق الملكية')
            ->assertSee('الالتزامات + حقوق الملكية + الربح/الخسارة غير المقفلة')
            ->assertSee('فرق المعادلة')->assertSee(config('accounting.currency'))
            ->assertSee('name="as_of"', false)->assertDontSee('الأرباح المبقاة')
            ->assertSee(route('accounting-pages.income-statement'), false);
    }

    public function test_statement_views_receive_their_existing_data_keys_and_selected_dates(): void
    {
        $this->actingAs($this->owner);
        $this->get($this->income('2026-01-01', '2026-01-31'))->assertOk()
            ->assertViewIs('accounting.income-statement')
            ->assertViewHasAll(['report', 'dates', 'conflict'])
            ->assertViewHas('dates', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'])
            ->assertViewHas('conflict', fn ($conflict) => $conflict === null)
            ->assertViewHas('report', fn ($report) => is_array($report));
        $this->get($this->position('2026-01-31'))->assertOk()
            ->assertViewIs('accounting.balance-sheet')
            ->assertViewHasAll(['report', 'asOf', 'conflict'])
            ->assertViewHas('asOf', '2026-01-31')
            ->assertViewHas('conflict', fn ($conflict) => $conflict === null)
            ->assertViewHas('report', fn ($report) => is_array($report));
    }

    public function test_statement_pages_keep_unexpected_database_errors_sanitized(): void
    {
        foreach ([
            [IncomeStatementQuery::class, $this->income('2026-01-01', '2026-01-31')],
            [StatementOfFinancialPositionQuery::class, $this->position('2026-01-31')],
        ] as [$queryClass, $url]) {
            $this->app->bind($queryClass, fn () => throw new QueryException('mysql_testing',
                'select secret from journal_entries', [], new PDOException('SQLSTATE[HY000]: private database detail')));
            try {
                $this->actingAs($this->owner)->get($url)->assertStatus(500)
                    ->assertViewIs('accounting.error')
                    ->assertSee('تعذر تحميل الصفحة المحاسبية.')
                    ->assertDontSee('SQLSTATE')->assertDontSee('secret')
                    ->assertDontSee('journal_entries')->assertDontSee('private database detail');
            } finally {
                $this->app->bind($queryClass, $queryClass);
            }
        }
    }

    public function test_income_page_uses_inclusive_dates_posted_only_owner_scope_exact_cents_and_loss(): void
    {
        $asset = $this->account('1000', 'asset');
        $revenue = $this->account('4000', 'revenue');
        $expense = $this->account('5000', 'expense');
        $this->journal('2025-12-31', [$this->line($asset, '99.00', '0'), $this->line($revenue, '0', '99.00')]);
        $this->journal('2026-01-01', [$this->line($asset, '10.03', '0'), $this->line($revenue, '0', '10.03')]);
        $this->journal('2026-01-31', [$this->line($expense, '4.02', '0'), $this->line($asset, '0', '4.02')]);
        $this->journal('2026-02-01', [$this->line($expense, '0.01', '0'), $this->line($asset, '0', '0.01')]);
        $this->journal('2026-01-15', [$this->line($asset, '999.00', '0'), $this->line($revenue, '0', '999.00')], false);
        $foreignAsset = $this->account('1000', 'asset', $this->other);
        $foreignRevenue = $this->account('4000', 'revenue', $this->other);
        $this->journal('2026-01-15', [$this->line($foreignAsset, '999.00', '0'), $this->line($foreignRevenue, '0', '999.00')], owner: $this->other);

        $this->actingAs($this->owner)->get($this->income('2026-01-01', '2026-01-31').'&user_id='.$this->other->id)
            ->assertOk()->assertSee('10.03')->assertSee('4.02')->assertSee('6.01')
            ->assertSee('ربح')->assertDontSee('99.00')->assertDontSee('999.00')
            ->assertSee('Account 4000')->assertSee('Account 5000');
        $this->get($this->income('2026-02-01', '2026-02-01'))->assertOk()
            ->assertSee('-0.01')->assertSee('خسارة');
    }

    public function test_balance_sheet_page_shows_exact_totals_equation_and_owner_isolation(): void
    {
        $asset = $this->account('1000', 'asset');
        $liability = $this->account('2000', 'liability');
        $equity = $this->account('3000', 'equity');
        $revenue = $this->account('4000', 'revenue');
        $expense = $this->account('5000', 'expense');
        $this->journal('2026-01-01', [$this->line($asset, '100.01', '0'), $this->line($liability, '0', '40.00'), $this->line($equity, '0', '60.01')]);
        $this->journal('2026-01-31', [$this->line($asset, '10.00', '0'), $this->line($revenue, '0', '10.00')]);
        $this->journal('2026-01-31', [$this->line($expense, '3.00', '0'), $this->line($asset, '0', '3.00')]);
        $this->journal('2026-02-01', [$this->line($asset, '99.00', '0'), $this->line($revenue, '0', '99.00')]);
        $this->journal('2026-01-15', [$this->line($asset, '999.00', '0'), $this->line($revenue, '0', '999.00')], false);
        $foreignAsset = $this->account('1000', 'asset', $this->other);
        $foreignEquity = $this->account('3000', 'equity', $this->other);
        $this->journal('2026-01-15', [$this->line($foreignAsset, '999.00', '0'), $this->line($foreignEquity, '0', '999.00')], owner: $this->other);

        $this->actingAs($this->owner)->get($this->position('2026-01-31').'&user_id='.$this->other->id)
            ->assertOk()->assertSee('107.01')->assertSee('40.00')->assertSee('60.01')
            ->assertSee('7.00')->assertSee('0.00')->assertDontSee('999.00')
            ->assertDontSee('الأرباح المبقاة');
    }

    public function test_reversal_appears_on_its_own_accounting_date(): void
    {
        $asset = $this->account('1000', 'asset');
        $revenue = $this->account('4000', 'revenue');
        $original = $this->journal('2026-01-05', [$this->line($asset, '5.00', '0'), $this->line($revenue, '0', '5.00')]);
        $this->travelTo(Carbon::parse('2026-02-01 12:00:00'));
        try {
            (new ReverseJournalEntry)->execute($this->owner, $original->id);
        } finally {
            $this->travelBack();
        }

        $this->actingAs($this->owner)->get($this->income('2026-01-05', '2026-01-31'))->assertOk()->assertSee('5.00');
        $this->get($this->income('2026-02-01', '2026-02-01'))->assertOk()->assertSee('-5.00')->assertSee('خسارة');
        $this->get($this->position('2026-02-01'))->assertOk()->assertSee('0.00');
    }

    public function test_invalid_dates_show_validation_errors_and_preserve_input(): void
    {
        $this->actingAs($this->owner);
        $incomePage = route('accounting-pages.income-statement');
        $positionPage = route('accounting-pages.balance-sheet');
        $this->from($incomePage)->get($this->income('2026-02-01', '2026-01-31'))
            ->assertRedirect($incomePage)->assertSessionHasErrors('date_to');
        $this->get($incomePage)->assertOk()->assertSee('value="2026-02-01"', false);
        $this->from($incomePage)->get($this->income('2026-02-30', '2026-03-01'))
            ->assertRedirect($incomePage)->assertSessionHasErrors('date_from');
        $this->from($positionPage)->get($this->position('2026-02-30'))
            ->assertRedirect($positionPage)->assertSessionHasErrors('as_of');
        $this->get($positionPage)->assertOk()->assertSee('value="2026-02-30"', false);
    }

    public function test_report_page_defaults_apply_only_when_their_date_inputs_are_absent(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 12:00:00'));
        try {
            $this->actingAs($this->owner);
            $incomePage = route('accounting-pages.income-statement');
            $positionPage = route('accounting-pages.balance-sheet');
            $this->get($incomePage)->assertOk()
                ->assertSee('value="2026-03-01"', false)
                ->assertSee('value="2026-03-15"', false);
            $this->get($positionPage)->assertOk()->assertSee('value="2026-03-15"', false);

            $this->from($incomePage)->get($incomePage.'?date_from=2026-02-01')
                ->assertRedirect($incomePage)->assertSessionHasErrors('date_to');
            $this->get($incomePage)->assertOk()->assertSee('value="2026-02-01"', false);
            $this->from($positionPage)->get($positionPage.'?as_of=')
                ->assertRedirect($positionPage)->assertSessionHasErrors('as_of');
            $this->get($positionPage)->assertOk()->assertSee('name="as_of"', false);
        } finally {
            $this->travelBack();
        }
    }

    public function test_mixed_currency_conflict_is_safe_arabic_html(): void
    {
        $asset = $this->account('1000', 'asset');
        $revenue = $this->account('4000', 'revenue');
        $configured = config('accounting.currency');
        try {
            config(['accounting.currency' => 'SAR']);
            $this->journal('2026-01-01', [$this->line($asset, '1.00', '0'), $this->line($revenue, '0', '1.00')]);
            config(['accounting.currency' => 'USD']);
            $this->journal('2026-01-02', [$this->line($asset, '2.00', '0'), $this->line($revenue, '0', '2.00')]);
            $this->actingAs($this->owner);
            foreach ([$this->income('2026-01-01', '2026-01-02'), $this->position('2026-01-02')] as $url) {
                $this->get($url)->assertStatus(409)->assertSee('تعذر عرض القائمة')
                    ->assertDontSee('SQLSTATE')->assertDontSee('Financial report cannot combine');
            }
        } finally {
            config(['accounting.currency' => $configured]);
        }
    }

    public function test_corrupt_posted_ledger_conflict_does_not_leak_internal_details(): void
    {
        $asset = $this->account('1000', 'asset');
        $draft = $this->journal('2026-01-01', [$this->line($asset, '0.01', '0')], false);
        DB::table('journal_entries')->where('id', $draft->id)->update(['status' => 'posted', 'posted_at' => now()]);

        $this->actingAs($this->owner);
        foreach ([$this->income('2026-01-01', '2026-01-01'), $this->position('2026-01-01')] as $url) {
            $this->get($url)->assertStatus(409)->assertSee('تعذر عرض القائمة')
                ->assertDontSee('SQLSTATE')->assertDontSee('out-of-balance posted ledger');
        }
    }
}
