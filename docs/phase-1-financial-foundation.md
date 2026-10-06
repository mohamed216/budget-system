# Phase 1: financial foundation

## Migration impact

The three additive migrations do not delete, seed, or reassign financial rows:

1. Add indexed, nullable `user_id` foreign keys to accounts, categories, transactions,
   and budgets. Existing rows remain NULL and inaccessible through the application.
   User deletion is restricted when financial rows reference the user.
2. Replace transaction account/category and budget category cascading foreign keys
   with RESTRICT. This migration uses MySQL ALTER TABLE, with one statement per table.
3. Add a unique budget key on `(category_id, month, year)`. A duplicate preflight
   aborts without changing budgets. Duplicate resolution requires a reviewed data plan.

MySQL DDL can lock tables and implicitly commit. Schedule application of these
migrations in a maintenance window with a verified backup; separate migration steps
can leave a partially applied upgrade if one fails. Do not deploy the ownership-aware
application before the ownership columns exist. No production/application migration
is automatically run by these changes.

Rolling back ownership removes ownership metadata. Rolling back deletion restrictions
restores the old cascade behavior. These rollbacks should not be routine operations.

Legacy record ownership must be assigned by a separately approved plan, consistently
across accounts, categories, transactions, and budgets. Never assign everything to the
first user or infer ownership from a login. Existing balances are not reconciled here.

## Authentication and posting

Login uses Laravel's existing User model and session guard, with throttling and session
regeneration. Logout invalidates the session. There is no public registration or user
provisioning flow in this phase; use existing, individually provisioned users.

Queries explicitly scope to the authenticated user. Policies protect deletion;
reference validation scopes account/category IDs to their owner. The posting action
rechecks ownership while locking the account and compatible category, inserts the
transaction, then updates the balance using bound MySQL DECIMAL arithmetic in the
same database transaction. Deadlocks are retried up to three times.

Referenced accounts/categories cannot be deleted. Categories with budgets are also
protected. Saving a budget locks its expense category before updating or creating the
unique period. An existing unassigned or foreign budget is never claimed by saving it.

Currencies accept uppercase three-letter codes (format validation, not an ISO catalog).
Amounts use ordinary decimal notation with at most two decimal places. Initial balances
are nonnegative; expense posting retains the prior ability to overdraw an account.
Years accept 1900 through 2100. Multi-currency aggregation and ledger accounting remain
outside this phase.

## Running tests safely

Tests require PHP's DOM, XML, XMLWriter, mbstring, and pdo_mysql extensions and a
**dedicated empty MySQL test database, never the application database**. Provision
that database and a separate user whose grants are limited to it. Compose initializes
only the application database/user; it does not provision test credentials. Do not
use application or root credentials for tests.

Copy `.env.testing.example` to the ignored `.env.testing` and supply a locally generated
test-only `APP_KEY`. Keep passwords and keys out of version control. PHPUnit forces
`DB_CONNECTION=mysql_testing`, but does not override `TEST_DB_*` variables; supply
those through `.env.testing` or the process environment:

| Variable | Docker default/example | Purpose |
| --- | --- | --- |
| `TEST_DB_HOST` | `mysql` | Compose service hostname; use `127.0.0.1` from the host |
| `TEST_DB_PORT` | `3306` | Container port; use `3307` from the host |
| `TEST_DB_DATABASE` | `budget_system_test` | Dedicated database ending in `_test` or `_testing` |
| `TEST_DB_USERNAME` | `budget_test` | Dedicated user restricted to the test database |
| `TEST_DB_PASSWORD` | No password default | Supply the test user's password privately |

The `mysql_testing` connection never inherits application credentials or `DB_URL`.
The test harness refuses other connection names, non-testing environments, database
names without `_test`/`_testing` suffixes, and the configured application database.
Do not use cached application configuration when running tests.

Compose reads the application `DB_DATABASE`/`DB_USERNAME` (development defaults:
`budget_system`/`budget`). Set `DB_PASSWORD` and `MYSQL_ROOT_PASSWORD` in your private
local `.env` or process environment before using Compose; neither has a password
default. MySQL is published only on `127.0.0.1:3307`. Its healthcheck reads the root
password from the container environment rather than a literal command argument.
Existing MySQL volumes retain their initialized credentials; changing these variables
does not rotate passwords or recreate databases. Match the existing credentials and
do not reset the volume. Local `*.backup` files and the pre-Phase-1 SQL dump are ignored
but retained on disk.

The RefreshDatabase-based test trait replaces Laravel's reset step with ordinary
`migrate --force`, followed by rollback of each test's fixtures. It never runs
`migrate:fresh`, drops the schema, or seeds. Use an empty test database; assertions assume
there are no pre-existing financial fixtures. Schema and migration history remain
available for repeated test runs.

Run:

```sh
php artisan test
php artisan route:list
php artisan migrate:status
vendor/bin/pint --test app routes tests config/database.php database/migrations/2026_10_05_*.php
```

The normal application migration status command is read-only. Outside Docker, temporary
DB_HOST/DB_PORT environment overrides may be needed to reach Compose's published port.
