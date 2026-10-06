# Phase 2 accounting contract

Step 1 supplies configuration and exact decimal arithmetic only. It creates no tables,
journals, routes, or posting behavior, and changes no operational financial behavior.

## Currency

Accounting uses one configurable currency, read from `config('accounting.currency')`.
`ACCOUNTING_CURRENCY` defaults to `SAR`; its format must be exactly three uppercase
ASCII letters. Configuration rejects invalid values; format validation is not an ISO
4217 membership check. No business logic should embed a currency literal. Changing
currency after journals exist will require a reviewed migration/conversion decision;
configuration must never reinterpret existing posted amounts.

## Exact amounts

`App\Accounting\DecimalAmount::fromString()` accepts only ordinary non-negative
ASCII decimal strings, with at most two decimal places and a maximum input amount of
`9999999999999.99` (DECIMAL(15,2)). Leading zeros are allowed and normalized. Integers,
floats, exponent notation, signs, commas, whitespace, and malformed values are rejected.
`toDecimal()` returns exactly two decimal places.

The immutable value object stores canonical minor units as digit strings. Addition
uses digit-by-digit integer operations only; comparison uses length and lexical order.
Never convert accounting money to PHP floats. No Composer dependency or PHP extension
is required. Zero/positive checks and equality are exact.

Aggregate addition is intentionally unbounded by DECIMAL(15,2) and native integer
ranges, subject to available memory. An aggregate must not be persisted to a
DECIMAL(15,2) line without revalidating its formatted value through `fromString()`.
This utility represents non-negative amounts, not signed ledger balances; subtraction,
signed presentation, and ledger queries are deferred.

## Journal invariants for later steps

Draft journals may be empty or unbalanced. Every saved journal line must have exactly
one positive side: debit > 0 and credit = 0, or credit > 0 and debit = 0.
Posted journals require at least two lines, exactly equal debit/credit totals, and a
positive total. Posted headers and lines will be immutable. These are design contracts;
this step does not implement journal validation or posting.

## Boundaries

The accounting Chart of Accounts will be separate from operational cash/bank/wallet
accounts. Existing accounts and transactions remain unchanged and unlinked. Chart
hierarchy will later use `parent_id`, with ownership and cycle rules reviewed when its
schema is implemented. Database immutability triggers are deferred to a later accounting
phase. No reversals or additional business modules are introduced here.
