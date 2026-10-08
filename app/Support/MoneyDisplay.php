<?php

namespace App\Support;

use App\Accounting\DecimalAmount;
use InvalidArgumentException;

final class MoneyDisplay
{
    public static function format(mixed $amount): string
    {
        if (! is_string($amount) && ! is_int($amount)) {
            throw new InvalidArgumentException('Displayed money must be an exact decimal string or integer.');
        }

        $value = (string) $amount;
        $negative = str_starts_with($value, '-');
        $decimal = DecimalAmount::fromAggregateString($negative ? substr($value, 1) : $value)->toDecimal();
        [$whole, $fraction] = explode('.', $decimal, 2);
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return ($negative && $decimal !== '0.00' ? '-' : '').$grouped.'.'.$fraction;
    }
}
