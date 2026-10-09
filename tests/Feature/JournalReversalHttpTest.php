<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class JournalReversalHttpTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;
    private ChartAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->account = (new CreateChartAccount)->execute($this->owner, '1000', 'Cash', 'asset');
        $this->account->update(['cash_role' => 'non_cash']);
    }

    private function draft(): JournalEntry
    {
        return (new SaveJournalDraft)->execute($this->owner, '2026-10-06', config('accounting.currency'), [
            ['chart_account_id' => $this->account->id, 'debit' => '0.01', 'credit' => '0.00', 'description' => 'Debit line'],
            ['chart_account_id' => $this->account->id, 'debit' => '0.00', 'credit' => '0.01', 'description' => 'Credit line'],
        ], 'ORIG', 'Original journal');
    }

    private function posted(): JournalEntry
    {
        $draft = $this->draft();

        return (new PostJournalEntry)->execute($this->owner, $draft->id);
    }

    public function test_owner_receives_created_posted_reversal_with_exact_ordered_lines(): void
    {
        $original = $this->posted();
        $before = DB::table('journal_entries')->where('id', $original->id)->first();

        $this->actingAs($this->owner);
        $response = $this->postJson(route('accounting.journals.reverse', $original->id), [
            'user_id' => User::factory()->create()->id,
            'debit' => '999.00',
        ])->assertCreated()->assertJsonStructure(['data' => [
            'id', 'user_id', 'entry_date', 'currency', 'reference', 'description',
            'status', 'posted_at', 'version', 'reversal_of_id', 'lines',
        ]]);

        $id = $response->json('data.id');
        $this->assertNotSame($original->id, $id);
        $response->assertJsonPath('data.user_id', $this->owner->id)
            ->assertJsonPath('data.currency', $original->currency)
            ->assertJsonPath('data.reference', 'REV-'.$original->id)
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.reversal_of_id', $original->id)
            ->assertJsonPath('data.lines.0.line_number', 1)
            ->assertJsonPath('data.lines.0.chart_account_id', $this->account->id)
            ->assertJsonPath('data.lines.0.debit', '0.00')
            ->assertJsonPath('data.lines.0.credit', '0.01')
            ->assertJsonPath('data.lines.0.description', 'Debit line')
            ->assertJsonPath('data.lines.1.line_number', 2)
            ->assertJsonPath('data.lines.1.debit', '0.01')
            ->assertJsonPath('data.lines.1.credit', '0.00')
            ->assertJsonPath('data.lines.1.description', 'Credit line');
        $this->assertCount(2, $response->json('data.lines'));
        $this->assertNotNull($response->json('data.posted_at'));
        $this->assertStringContainsString('#'.$original->id, $response->json('data.description'));
        $this->assertSame((array) $before, (array) DB::table('journal_entries')->where('id', $original->id)->first());
        $this->assertSame('posted', JournalEntry::findOrFail($id)->status);
    }

    public function test_foreign_missing_and_guest_requests_are_denied_without_creating_records(): void
    {
        $original = $this->posted();
        $url = route('accounting.journals.reverse', $original->id);
        $this->postJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url)->assertNotFound();
        $this->actingAs($this->owner)->postJson(route('accounting.journals.reverse', $original->id + 1000))->assertNotFound();
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 2);
    }

    public function test_draft_already_reversed_and_reversal_of_reversal_return_conflict(): void
    {
        $draft = $this->draft();
        $original = $this->posted();
        $this->actingAs($this->owner)->postJson(route('accounting.journals.reverse', $draft->id))
            ->assertConflict()->assertJsonStructure(['message']);

        $reversalId = $this->postJson(route('accounting.journals.reverse', $original->id))->assertCreated()->json('data.id');
        $this->postJson(route('accounting.journals.reverse', $original->id))->assertConflict()->assertJsonStructure(['message']);
        $this->postJson(route('accounting.journals.reverse', $reversalId))->assertConflict()->assertJsonStructure(['message']);
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertDatabaseCount('journal_lines', 6);
    }

    public function test_failure_after_first_copied_line_leaves_no_partial_reversal(): void
    {
        $original = $this->posted();
        $before = (array) DB::table('journal_entries')->where('id', $original->id)->first();
        $event = 'eloquent.created: '.JournalLine::class;
        Event::listen($event, function (JournalLine $line) use ($original) {
            if ((string) $line->journal_entry_id !== (string) $original->id) {
                throw new RuntimeException('Injected reversal failure');
            }
        });
        try {
            $this->actingAs($this->owner)->postJson(route('accounting.journals.reverse', $original->id))->assertInternalServerError();
        } finally {
            Event::forget($event);
        }

        $this->assertSame($before, (array) DB::table('journal_entries')->where('id', $original->id)->first());
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 2);
        $this->assertSame(0, JournalEntry::where('reversal_of_id', $original->id)->count());
    }

    public function test_reverse_route_uses_authenticated_json_group_and_existing_routes_remain_available(): void
    {
        $route = Route::getRoutes()->getByName('accounting.journals.reverse');
        $this->assertSame('accounting/journals/{journal}/reverse', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        foreach (['accounting.journals.index', 'accounting.journals.show', 'accounting.journals.post'] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name));
        }
    }
}
