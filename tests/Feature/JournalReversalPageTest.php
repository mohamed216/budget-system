<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class JournalReversalPageTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;
    private User $foreign;
    private ChartAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->foreign = User::factory()->create();
        $this->account = (new CreateChartAccount)->execute($this->owner, '1000', 'Cash', 'asset');
    }

    private function draft(?User $actor = null, ?ChartAccount $account = null): JournalEntry
    {
        $actor ??= $this->owner;
        $account ??= $this->account;

        return (new SaveJournalDraft)->execute($actor, '2026-10-06', config('accounting.currency'), [
            ['chart_account_id' => $account->id, 'debit' => '0.01', 'credit' => '0.00', 'description' => 'Debit line'],
            ['chart_account_id' => $account->id, 'debit' => '0.00', 'credit' => '0.01', 'description' => 'Credit line'],
        ], 'UI-ORIG', 'Original journal');
    }

    private function posted(?User $actor = null, ?ChartAccount $account = null): JournalEntry
    {
        $actor ??= $this->owner;
        $draft = $this->draft($actor, $account);

        return (new PostJournalEntry)->execute($actor, $draft->id);
    }

    public function test_only_eligible_posted_original_shows_button_and_clear_confirmation(): void
    {
        $draft = $this->draft();
        $original = $this->posted();
        $this->actingAs($this->owner);

        $this->get(route('accounting-pages.journals.show', $draft->id))->assertOk()
            ->assertDontSee('عكس القيد')->assertSee('تعديل المسودة');
        $this->get(route('accounting-pages.journals.show', $original->id))->assertOk()
            ->assertSee('عكس القيد')
            ->assertSee(route('accounting-pages.journals.reverse', $original->id), false)
            ->assertSee('onsubmit="return confirm(', false)
            ->assertSee('لن يُعدّل القيد الأصلي أو يُحذف')
            ->assertSee('قيد عكسي جديد ومرحل')
            ->assertSee('السجل المحاسبي بشكل دائم')
            ->assertDontSee('تعديل المسودة')->assertDontSee('حذف المسودة');
    }

    public function test_successful_post_redirects_to_new_reversal_with_arabic_success_message(): void
    {
        $original = $this->posted();
        $response = $this->actingAs($this->owner)->post(route('accounting-pages.journals.reverse', $original->id));

        $reversal = JournalEntry::where('reversal_of_id', $original->id)->firstOrFail();
        $response->assertRedirect(route('accounting-pages.journals.show', $reversal->id))
            ->assertSessionHas('success', 'تم إنشاء القيد العكسي وترحيله بنجاح.');
        $this->assertTrue($reversal->isPosted());
        $this->assertSame($this->owner->id, $reversal->user_id);
        $this->get(route('accounting-pages.journals.show', $reversal->id))->assertOk()
            ->assertSee('تم إنشاء القيد العكسي وترحيله بنجاح.');
    }

    public function test_both_details_link_to_each_other_and_posted_controls_stay_read_only(): void
    {
        $original = $this->posted();
        $reversal = (new ReverseJournalEntry)->execute($this->owner, $original->id);
        $this->actingAs($this->owner);

        $this->get(route('accounting-pages.journals.show', $original->id))->assertOk()
            ->assertSee('تم عكس هذا القيد')
            ->assertSee('عرض القيد العكسي')
            ->assertSee(route('accounting-pages.journals.show', $reversal->id), false)
            ->assertDontSee('عكس القيد')->assertDontSee('تعديل المسودة')->assertDontSee('حذف المسودة');
        $this->get(route('accounting-pages.journals.show', $reversal->id))->assertOk()
            ->assertSee('هذا قيد عكسي للقيد رقم '.$original->id)
            ->assertSee('عرض القيد الأصلي')
            ->assertSee(route('accounting-pages.journals.show', $original->id), false)
            ->assertDontSee('عكس القيد')->assertDontSee('تعديل المسودة')->assertDontSee('حذف المسودة');
        $this->get(route('accounting-pages.journals.index'))->assertOk()
            ->assertSee('معكوس')->assertSee('قيد عكسي');
    }

    public function test_ineligible_page_posts_return_with_accounting_errors_without_new_journals(): void
    {
        $draft = $this->draft();
        $original = $this->posted();
        $this->actingAs($this->owner);

        $draftShow = route('accounting-pages.journals.show', $draft->id);
        $this->from($draftShow)->post(route('accounting-pages.journals.reverse', $draft->id))
            ->assertRedirect($draftShow)->assertSessionHasErrors('accounting');
        $reversal = (new ReverseJournalEntry)->execute($this->owner, $original->id);
        foreach ([$original, $reversal] as $entry) {
            $show = route('accounting-pages.journals.show', $entry->id);
            $this->from($show)->post(route('accounting-pages.journals.reverse', $entry->id))
                ->assertRedirect($show)->assertSessionHasErrors('accounting');
        }
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_guest_and_foreign_user_cannot_access_reversal_page_or_post(): void
    {
        $foreignAccount = (new CreateChartAccount)->execute($this->foreign, '1000', 'Foreign', 'asset');
        $foreignJournal = $this->posted($this->foreign, $foreignAccount);
        $url = route('accounting-pages.journals.reverse', $foreignJournal->id);

        $this->post($url)->assertRedirect(route('login'));
        $this->actingAs($this->owner)->get(route('accounting-pages.journals.show', $foreignJournal->id))->assertNotFound();
        $this->post($url)->assertNotFound();
        $this->get(route('accounting-pages.journals.index'))->assertOk()->assertDontSee('UI-ORIG');
        $this->assertDatabaseCount('journal_entries', 1);
    }
}
