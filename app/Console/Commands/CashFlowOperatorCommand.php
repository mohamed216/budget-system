<?php

namespace App\Console\Commands;

use App\Accounting\Exceptions\AccountingConflict;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

abstract class CashFlowOperatorCommand extends Command
{
    protected function owner(): User
    {
        return User::query()->findOrFail($this->positiveId('user'));
    }

    protected function positiveId(string $argument): int
    {
        $value = $this->argument($argument);
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value)
            || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw ValidationException::withMessages([$argument => 'A positive integer ID is required.']);
        }

        return (int) $value;
    }

    protected function safely(callable $operation): int
    {
        try {
            $operation();

            return self::SUCCESS;
        } catch (AccountingConflict $exception) {
            $this->error('Accounting conflict: '.($exception->reason?->name ?? 'Conflict'));
        } catch (ValidationException $exception) {
            $this->error('Invalid input: '.implode(', ', array_keys($exception->errors())));
        } catch (ModelNotFoundException) {
            $this->error('Owner or owned record not found.');
        } catch (Throwable) {
            $this->error('Operation failed. Check the operator logs; no details were disclosed.');
        }

        return self::FAILURE;
    }
}
