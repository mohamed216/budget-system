<?php

namespace Tests\Unit;

use App\Accounting\FiscalYearCloseFieldRules;
use App\Accounting\FiscalYearCloseInput;
use App\Http\Requests\Accounting\FiscalYearCloseRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FiscalYearCloseFieldRulesTest extends TestCase
{
    private function fields(array $changes = []): array
    {
        return array_replace([
            'start_date' => '2026-04-15', 'end_date' => '2027-04-14',
            'currency' => config('accounting.currency'), 'retained_earnings_account_id' => 123,
        ], $changes);
    }

    public function test_shared_source_contains_only_the_four_reusable_fields_in_existing_order(): void
    {
        $this->assertSame(
            ['start_date', 'end_date', 'currency', 'retained_earnings_account_id'],
            array_keys((new FiscalYearCloseFieldRules)->rules()),
        );
    }

    public function test_request_and_direct_input_accept_exact_valid_values(): void
    {
        foreach ([
            $this->fields(),
            $this->fields(['start_date' => '2026-04-15', 'end_date' => '2026-04-15', 'retained_earnings_account_id' => '123']),
        ] as $fields) {
            $this->assertTrue(Validator::make($fields, (new FiscalYearCloseRequest)->rules())->passes());
            $this->assertSame($fields, FiscalYearCloseInput::validate(
                $fields['start_date'], $fields['end_date'], $fields['currency'], $fields['retained_earnings_account_id'],
            ));
        }
    }

    public function test_reusable_invalid_values_keep_the_same_field_and_message_in_both_layers(): void
    {
        foreach ([
            ['start_date' => ' 2026-04-15'], ['start_date' => '2026-02-30'],
            ['end_date' => '2026-04-14'], ['end_date' => '2027-4-14'],
            ['currency' => ' '.config('accounting.currency').' '], ['currency' => strtolower(config('accounting.currency'))],
            ['currency' => 'US1'], ['currency' => config('accounting.currency') === 'SAR' ? 'USD' : 'SAR'],
            ['retained_earnings_account_id' => 0],
            ['retained_earnings_account_id' => '1.0'],
        ] as $changes) {
            $fields = $this->fields($changes);
            $field = array_key_first($changes);
            $requestErrors = Validator::make($fields, (new FiscalYearCloseRequest)->rules())->errors()->toArray();
            $this->assertArrayHasKey($field, $requestErrors);

            try {
                FiscalYearCloseInput::validate(
                    $fields['start_date'], $fields['end_date'], $fields['currency'], $fields['retained_earnings_account_id'],
                );
                $this->fail('Direct fiscal-close input accepted invalid '.$field);
            } catch (ValidationException $exception) {
                $this->assertSame($requestErrors[$field], $exception->errors()[$field]);
            }
        }
    }

    public function test_request_only_restrictions_and_direct_caller_shape_check_remain_separate(): void
    {
        foreach (['id', 'user_id', 'status', 'journal_entry_id', 'closed_at', 'created_at', 'updated_at'] as $field) {
            $this->assertTrue(Validator::make($this->fields([$field => null]), (new FiscalYearCloseRequest)->rules())->errors()->has($field));
        }

        foreach (['retained_earnings_account_id', 'start_date', 'end_date', 'currency'] as $field) {
            $fields = $this->fields([$field => '']);
            $this->assertTrue(Validator::make($fields, (new FiscalYearCloseRequest)->rules())->errors()->has($field));
        }

        $this->assertTrue(Validator::make($this->fields(['retained_earnings_account_id' => ' 123 ']),
            (new FiscalYearCloseRequest)->rules())->passes());
        $this->assertTrue(Validator::make($this->fields(['retained_earnings_account_id' => '0123']),
            (new FiscalYearCloseRequest)->rules())->errors()->has('retained_earnings_account_id'));
        foreach (['0123', ' 123 '] as $accountId) {
            try {
                FiscalYearCloseInput::validate('2026-04-15', '2027-04-14', config('accounting.currency'), $accountId);
                $this->fail('Direct input accepted a noncanonical account id.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('retained_earnings_account_id', $exception->errors());
            }
        }
    }

    public function test_null_and_empty_values_remain_invalid_for_request_and_direct_callers(): void
    {
        foreach (['start_date', 'end_date', 'currency', 'retained_earnings_account_id'] as $field) {
            foreach ([null, ''] as $value) {
                $this->assertTrue(Validator::make($this->fields([$field => $value]),
                    (new FiscalYearCloseRequest)->rules())->errors()->has($field));
            }
        }

        foreach ([
            ['', '2027-04-14', config('accounting.currency'), 1, 'start_date'],
            ['2026-04-15', '', config('accounting.currency'), 1, 'end_date'],
            ['2026-04-15', '2027-04-14', '', 1, 'currency'],
            ['2026-04-15', '2027-04-14', config('accounting.currency'), null, 'retained_earnings_account_id'],
            ['2026-04-15', '2027-04-14', config('accounting.currency'), '', 'retained_earnings_account_id'],
        ] as [$start, $end, $currency, $accountId, $field]) {
            try {
                FiscalYearCloseInput::validate($start, $end, $currency, $accountId);
                $this->fail('Direct input accepted empty '.$field);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }
}
