<?php

namespace App\Accounting\Exceptions;

use DomainException;
use Throwable;

final class AccountingConflict extends DomainException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?AccountingConflictReason $reason = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
