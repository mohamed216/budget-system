<?php

$currency = env('ACCOUNTING_CURRENCY', 'SAR');

// Validate the format, not membership in an ISO 4217 currency catalog.
if (! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
    throw new InvalidArgumentException('ACCOUNTING_CURRENCY must be an uppercase three-letter currency code.');
}

return [
    'currency' => $currency,
];
