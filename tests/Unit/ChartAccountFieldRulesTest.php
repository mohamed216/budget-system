<?php

namespace Tests\Unit;

use App\Accounting\Actions\ChartAccountInput;
use App\Accounting\ChartAccountFieldRules;
use App\Http\Requests\Accounting\ChartAccountRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChartAccountFieldRulesTest extends TestCase
{
    private function fields(array $changes = []): array
    {
        return array_replace([
            'code' => 'CASH.01_-', 'name' => 'Cash', 'type' => 'asset',
            'is_active' => true, 'parent_id' => null,
        ], $changes);
    }

    public function test_shared_rules_cover_only_reusable_chart_account_fields(): void
    {
        $this->assertSame(
            ['code', 'name', 'type', 'is_active', 'parent_id'],
            array_keys((new ChartAccountFieldRules)->rules()),
        );
        $this->assertSame(array_keys((new ChartAccountFieldRules)->rules()), array_slice(array_keys((new ChartAccountRequest)->rules()), 0, 5));
    }

    public function test_request_and_direct_input_accept_the_same_valid_fields(): void
    {
        foreach ([
            $this->fields(),
            $this->fields(['code' => 'A_1-2', 'name' => str_repeat('N', 255), 'type' => 'expense', 'is_active' => false, 'parent_id' => 1]),
        ] as $fields) {
            $this->assertTrue(Validator::make($fields, (new ChartAccountRequest)->rules())->passes());
            $this->assertSame($fields, ChartAccountInput::validate(
                $fields['code'], $fields['name'], $fields['type'], $fields['is_active'], $fields['parent_id'],
            ));
        }
    }

    public function test_request_and_direct_input_reject_the_same_invalid_reusable_fields(): void
    {
        foreach ([
            ['code' => 'BAD CODE'], ['code' => 'é'], ['code' => str_repeat('A', 33)],
            ['name' => ''], ['name' => str_repeat('N', 256)], ['type' => 'ASSET'],
        ] as $changes) {
            $fields = $this->fields($changes);
            $field = array_key_first($changes);
            $this->assertTrue(Validator::make($fields, (new ChartAccountRequest)->rules())->errors()->has($field));

            try {
                ChartAccountInput::validate($fields['code'], $fields['name'], $fields['type'], $fields['is_active'], $fields['parent_id']);
                $this->fail('Direct chart-account input accepted invalid '.$field);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    public function test_http_only_fields_remain_forbidden_and_direct_input_remains_normalized(): void
    {
        foreach (['id', 'user_id'] as $field) {
            $validator = Validator::make($this->fields([$field => null]), (new ChartAccountRequest)->rules());
            $this->assertTrue($validator->errors()->has($field));
        }

        $this->assertSame('CASH.01_-', ChartAccountInput::validate(' cash.01_- ', ' Cash ', 'asset', true, null)['code']);
        $this->assertSame('Cash', ChartAccountInput::validate(' cash.01_- ', ' Cash ', 'asset', true, null)['name']);
    }
}
