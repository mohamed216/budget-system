<?php

namespace App\Rules;

use App\Accounting\DecimalAmount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final class DecimalAmountRule implements ValidationRule
{
    public function __construct(
        private readonly ?string $message = null,
        private readonly bool $positive = false,
        private readonly ?int $maxWholeDigits = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $amount = DecimalAmount::fromString($value);
            if ($this->maxWholeDigits !== null && strlen(explode('.', $value, 2)[0]) > $this->maxWholeDigits) {
                throw new InvalidArgumentException('Amount has too many integer digits.');
            }
            if ($this->positive && ! $amount->isPositive()) {
                throw new InvalidArgumentException('Amount must be greater than zero.');
            }
        } catch (InvalidArgumentException $exception) {
            $fail($this->message ?? $exception->getMessage());
        }
    }
}
