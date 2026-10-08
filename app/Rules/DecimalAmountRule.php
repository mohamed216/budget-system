<?php

namespace App\Rules;

use App\Accounting\DecimalAmount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final class DecimalAmountRule implements ValidationRule
{
    public function __construct(private readonly ?string $message = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            DecimalAmount::fromString($value);
        } catch (InvalidArgumentException $exception) {
            $fail($this->message ?? $exception->getMessage());
        }
    }
}
