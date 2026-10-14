# Phase 3: operational accounting integration contract

## 1. Repository and design context

Operational `accounts` are cash, bank, or wallet records with a stored `DECIMAL(15,2)`
balance and currency. Operational `categories` are income or expense records.
`transactions` currently support creation only. `PostTransaction` creates a transaction
and changes its account balance in one database transaction, using bound MySQL decimal
arithmetic. Budgets are planning records and **never create journal entries**.

The separate accounting domain already has an owned chart of accounts, draft and posted
journals, accounting periods, opening balances, fiscal close, financial statements, and
direct-method cash flow. Posted journal headers, lines, and completed cash-flow review
are immutable. Accounting writes acquire `AccountingPeriodLocks::owner($actor)` first.
The configured accounting currency is `config('accounting.currency')`. All accounting
amounts are exact decimal strings; PHP floats are prohibited.

This phase links the two domains only for operational transactions. The owner-level
integration cutover is explicit. Before activation, the legacy transaction path remains
available. After activation, every new operational transaction must commit with exactly
one posted accounting journal and its immutable one-to-one link, or nothing commits.
There is no automatic owner assignment, mapping inference, cash-flow classification
inference, or balance migration.

## 2. Proposed schema and migrations

Use additive, guarded MySQL migrations. Inspect existing keys, constraints, data, and
trigger names before changing tables. MySQL DDL is not part of the posting transaction;
deploy schema separately from activation. Migrations must not assign owners, create
mappings, classify cash flow, or post historical balances automatically.

1. Add unique `(id, user_id)` keys to the legacy `accounts`, `categories`, and
   `transactions` tables where required for composite foreign keys. Their nullable
   legacy `user_id` columns remain nullable; an integration row always has a non-null
   owner. Preserve the existing restrictive transaction account/category foreign keys.
2. Add `operational_account_mappings` keyed by `account_id`, with `user_id NOT NULL`,
   `chart_account_id NOT NULL`, and audit timestamps. Keep `account_id` individually
   unique so each operational account has at most one mapping, and add a referenceable
   UNIQUE or PRIMARY key on `(account_id, user_id)`. Composite restrictive foreign
   keys `(account_id, user_id) -> accounts(id, user_id)` and
   `(chart_account_id, user_id) -> chart_of_accounts(id, user_id)` enforce ownership at
   the database level. A mapping is selected by **operational account ID**, never by
   account name, type label, or chart code.
3. Add `operational_category_mappings` keyed by `category_id`, with `user_id NOT NULL`,
   `chart_account_id NOT NULL`, nullable `cash_flow_activity`, review identity/time,
   and audit timestamps. Keep `category_id` individually unique so each operational
   category has at most one mapping, and add a referenceable UNIQUE or PRIMARY key on
   `(category_id, user_id)`. Use equivalent composite restrictive foreign keys to
   `categories` and `chart_of_accounts`. Constrain non-null activity to exactly
   `operating`, `investing`, or `financing`. A mapping is selected by **category ID**;
   duplicate category names are valid. Several categories may map to one chart account.
4. Add `operational_transaction_journals` with `transaction_id` unique/primary
   (exactly one linked journal per transaction), `journal_entry_id` unique (exactly
   one operational transaction per integrated journal), `user_id NOT NULL`, the
   operational account and category IDs used, and creation time. Composite restrictive
   foreign keys bind transaction, journal, and both mapping rows to the same owner.
   These owner-safe foreign keys require referenceable `(id, user_id)` keys on
   `transactions` and `journal_entries` as applicable, and can reference the mapping
   rows only because their `(account_id, user_id)` and `(category_id, user_id)`
   composite keys exist. No owner can cross-link another owner's transaction, mapping,
   or journal. Validate that the stored account
   and category IDs match the transaction itself. A database guard must reject link
   update/delete and linking an unrelated, unposted, opening, fiscal-close, or reversal
   journal. The journal lines remain the immutable snapshot of the actual chart account
   IDs and amounts.
5. Add an owner-keyed `operational_accounting_integrations` gate with state, configured
   currency at activation, cutover/high-water marker, reconciliation evidence, and
   activation time. Its owner foreign key is restrictive. Absence of an active gate
   means legacy behavior remains available; no global flag silently opts owners in.
6. Add database guards, backed by owner-first application checks, so that after a
   mapping has been used by a linked posted transaction its `chart_account_id` and
   `cash_flow_activity` cannot change and the mapping cannot be removed. Protect
   linked operational transaction identity, amount, type, date, account, category,
   owner, and deletion. Prevent changes to legacy category type or chart account type
   that would invalidate a live mapping. Existing chart cash-role freeze rules still
   apply. Active state is rechecked at every posting; disabling a mapped chart account
   blocks future transactions rather than rewriting history.

Mapping creation and change require owned, active chart accounts: `asset` for an
operational account, `revenue` for an income category, and `expense` for an expense
category. Both chart accounts in an integrated journal need reviewed `cash_role` before
use. Cross-table type and activity checks require transactional application validation
and appropriate database guards; ownership foreign keys alone do not enforce them.
Restrictive links must continue to protect historical transactions, accounts, and
categories from deletion.

## 3. Exact invariants

D1 installs additive, closed ACTIVE database protections: cutover identity, explicit
scopes, participating mappings/classifications, and source/chart accounting semantics
are protected even before linked use. Participating ACTIVE account balance writes and
publication touching a reserved mapped operational asset are disabled without any
authorization exception. Chart inactivity does not release reservation; the database
allows an `is_active` toggle while future posting must validate active use.
Activation remains unavailable, and integrated posting is not implemented by D1.
D3a must later install the unforgeable integrated write boundary. Restricting runtime
privileges so runtime SQL cannot remove guards or shadow their sources remains a
production prerequisite. D1 installation refuses existing ACTIVE owners rather than
adopting or repairing them; rollback also refuses guard removal with ACTIVE owners.
Install the complete trigger set under a controlled write pause: trigger DDL commits
implicitly, and preflight alone cannot exclude a concurrent raw cutover. Dependency
probes use current `FOR SHARE` locking reads, so raw SQL cannot use a stale
repeatable-read snapshot to bypass ACTIVE guards. MySQL 8.4 trigger prelocking may
wait despite NOWAIT syntax; D1 makes no trigger-level nonwaiting guarantee. Dependency
contention may surface as native MySQL 1205 timeouts or 1213 deadlocks, without
accounting-error normalization. A timeout rolls back the failing statement; a later
COMMIT cannot preserve that mutation, though earlier transaction statements may
remain. A deadlock rolls back the transaction. D1 protects integrity, not availability.
Earlier migrations must be rolled back only after D1.

- For each owner in active state, every newly created operational transaction has one
  and only one same-owner posted journal at commit. The journal has exactly two positive
  one-sided lines, equal debit and credit totals, the transaction date, and configured
  accounting currency. The unique link is immutable.
- Income debits the mapped operational asset and credits the mapped revenue account.
  Expense debits the mapped expense account and credits the mapped operational asset.
  Each side equals the transaction's exact positive `DECIMAL(15,2)` amount.
- `accounts.balance` changes once by the existing bound MySQL `DECIMAL(15,2)` addition
  or subtraction. Existing overdraft behavior remains. No PHP float is used for input,
  balance, journal, allocation, reconciliation, or reporting arithmetic.
- The operational account currency must exactly equal the configured accounting
  currency. No conversion or reinterpretation of posted history occurs.
- An integrated journal is posted, balanced, complete for cash flow when applicable,
  and immutable at commit. The linked operational transaction and mapping identity are
  immutable. Mapping target and activity classification freeze after linked posted use.
- Posting-time enforcement of the active mapping restriction on ordinary generic
  manual journals is authoritative, including for drafts created before activation.
  UI and save-time checks are defense-in-depth only.
- Budgets never create journals. Cash-to-cash transfers have no current operational
  transaction representation and are outside this phase.
- Historical backfill posts accounting history without changing the already stored
  operational balance. An opening balance is equity or another reviewed opening
  counterpart, never inferred revenue.

## 4. Readiness and activation states

Use owner-level states `legacy`, `reconciling`, `ready`, and `active`, with guarded
transitions. `legacy` retains current transaction behavior. `reconciling` is a
controlled rollout state: pause operational creates for that owner or serialize and
account for every create under the same owner gate. `ready` means a recorded dry-run
inventory and reconciliation passed; it does not itself switch the posting path.
`active` makes integrated posting mandatory and rejects any request that cannot meet
its accounting prerequisites. Never fall back to a legacy-only write after activation.

Activation requires verified ownership of all participating legacy records; exact
currency agreement; reviewed mappings by ID; owned active compatible chart accounts;
reviewed `cash_role` on every chart account used; explicit activity review for each
cash-backed category mapping; valid accounting dates and period/fiscal-close status;
an audited opening balance and historical transaction strategy; no unexplained overlap
with existing posted chart activity; and per-account reconciliation of stored balance
to the intended accounting ledger. Record the transaction high-water marker and
currency with the evidence. New accounts with nonzero starting balances require a
reviewed opening/onboarding path before integrated use. A later configuration currency
change cannot silently reinterpret an active owner's posted history.

## 5. Normative lock order

All integrated posting, mapping changes, rollout state changes, and relevant manual
journal guards use one outer database transaction and this order:

1. Owner row: `AccountingPeriodLocks::owner($actor)`.
2. Period and fiscal-close guard rows for the accounting date.
3. Owned operational account and matching category rows.
4. Integration gate and mapping rows.
5. Owned chart accounts in stable ascending chart account ID order.
6. Operational transaction and journal header.
7. Journal lines.
8. Journal-line allocations.
9. Draft-to-posted transition.
10. Cash-flow completion and immutable operational link.

Supported application actions must prelock dependencies in this normative order
to avoid trigger dependency waits. D2/D3 application boundaries own deterministic
lock sequencing and fast conflicts; raw SQL may block or receive native concurrency
errors while database triggers enforce current committed facts.

The owner row serializes this owner's application accounting writes. Validate owner and
date before taking later locks; recheck facts under their locks. Do not nest
`PostJournalEntry`, `SaveJournalDraft`, or another action that reacquires these locks in
a conflicting order inside the integrated action. Extract reusable validation or make
a dedicated posting routine with the declared order. Retry the entire operation on
retryable deadlocks, never only its accounting half.

## 6. Integrated posting algorithm

Keep the current request fields and owner-scoped validation. In one outer transaction:

1. Lock owner; confirm active integration and exact configured currency; apply the
   period and fiscal-close guard to `transactions.date`.
2. Lock the owned operational account and type-matching category. Lock their mappings,
   then their distinct chart accounts in ascending ID. Revalidate ownership, chart
   type, active state, reviewed roles and activity, account currency, and that the
   generic manual-journal restrictions cannot be bypassed.
3. Insert the operational transaction and run the same bound SQL balance update used
   today, requiring exactly one account row to update. Keep exact decimal strings.
4. Insert a draft journal and the two mapped lines. For a cash/cash-equivalent asset
   side, insert one full-amount debit-to-credit allocation with the reviewed activity.
   For a `non_cash` asset side, insert no allocation.
5. Apply the shared journal balance, currency, ownership, role, and allocation checks.
   Transition the journal to posted. If it has a cash side, insert its completion row.
   Insert the unique same-owner operational link and commit.

The existing completion trigger accepts a completion only when the journal is already
posted. Therefore the allocation precedes the posted transition and the completion
follows it **within the same uncommitted transaction**. At commit, a cash journal has
both allocation and completion. Failure at any point rolls back transaction row,
balance update, journal, lines, allocations, completion, and link together.

## 7. Cash-flow classification rules

Review `cash_role` explicitly on both mapped chart accounts. `cash` and
`cash_equivalent` are cash-flow sides; `non_cash` is not. A revenue or expense
counterpart must be reviewed `non_cash`. Do not infer cash role from operational
account type, chart account type, name, or code.

Review category-mapping `cash_flow_activity` explicitly as `operating`, `investing`, or
`financing`. Do not infer it from income/expense, category name, chart code, or account
type. A null/unreviewed activity blocks use of that category with a cash or cash
equivalent operational mapping. The selected value is copied into the immutable
`journal_line_allocations.category`; subsequent mapping changes cannot reclassify a
posted journal. For a two-line cash-to-noncash journal, the one allocation covers the
full amount and the journal has a completion at commit. A reviewed noncash operational
posting has no allocation or completion. Opening-balance journals follow the existing
opening and cash-flow reporting rules, not ordinary transaction classification.

## 8. Legacy rollout and backfill

Start with a read-only owner-by-owner inventory: operational account balances,
currencies, transaction IDs and exact net sums, ownership, mapped chart balances,
existing opening batches and posted journals, period/fiscal-close status, cash roles,
and category activity decisions. Detect unassigned owners, missing records, duplicate
candidate accounting history, unexplained balance differences, and closed historical
dates. Do not prescribe immediate production writes or alter immutable posted rows.

Known production facts require this candidate reconciliation, subject to the audit:

| Item | Exact amount | Proposed accounting meaning |
| --- | ---: | --- |
| Operational account #1 current balance | 20,500.00 SAR | Reconciliation target only |
| Recorded operational transaction net | +500.00 SAR | Historical transaction movement |
| Inferred pre-transaction opening | 20,000.00 SAR | Asset debit and equity credit, never revenue |
| Transaction #1, income on 2026-10-06 | 500.00 SAR | Asset debit and reviewed revenue credit |

The current chart has `1000` cash asset and `3000` equity, with no revenue or expense
accounts. Before historical posting or activation, review whether these existing chart
accounts already contain relevant posted balances, review their cash roles, create and
review the needed revenue account and any expense accounts for actual categories, map
records by ID, and review category cash-flow activities. Account #1 can map to `1000`
only if ownership, active state, currency, and reconciled ledger role all agree. The
20,000.00 opening candidate belongs in the existing opening-balance workflow on a
reviewed permissible date before transaction #1. Transaction #1 then needs its own
posted 500.00 revenue journal and immutable link, with an explicit cash allocation if
`1000` is reviewed as cash. Backfill must not update account #1's 20,500.00 balance.

Use a dry-run report and reviewed rollout plan before any historical posting. During
the controlled cutover, prevent untracked legacy creates, record a high-water mark,
post eligible historical transactions idempotently by transaction ID, then reconcile
stored balances and ledger totals before activation. A rerun must recognize a valid
existing link and must reject a conflicting link or duplicate accounting movement.
Do not double count an existing posted chart journal. A closed period or fiscal year
blocks historical posting; resolve that case through a separately reviewed accounting
decision, without redating historical revenue or rewriting immutable journals. If an
owner instead selects a reviewed balance-only cutover, mark the pre-cutover transactions
as legacy history and disclose that the earlier income statement is incomplete; do not
also backfill those transactions as income.

## 9. Conflict and error semantics

Owner-scoped request validation remains the first boundary. Under locks, return an
actionable validation or accounting conflict for a missing mapping, wrong chart type,
inactive chart account, unreviewed cash role or required activity, exact currency
mismatch, or closed accounting period/fiscal year. Reject missing or foreign owner
references without revealing another owner's records. Composite foreign keys and
unique keys provide a second database boundary. Missing integration prerequisites in
active mode never trigger a legacy-only fallback.

MySQL `DECIMAL(15,2)` overflow remains a failed posting and rolls back the entire
integrated operation, including the operational insert. A duplicate backfill attempt
with the same verified link is idempotent; a different journal, changed historical
facts, or mismatched owner is a conflict requiring review. Do not silently repair it.
Deadlocks and lock timeouts may retry the whole outer transaction only; no partial
commit or separate journal retry is permitted. Map expected conflicts to the existing
HTTP/UI error conventions where possible while preserving successful create behavior.

## 10. Manual journal and reversal restrictions after cutover

After activation, an asset chart account actively mapped to an operational account is
reserved from **ordinary generic manual journal activity** that would change its
ledger balance without the stored operational balance. Posting-time enforcement is
authoritative: a generic journal draft created before owner activation must still be
rejected at posting if it then touches an actively mapped operational asset and is not
an allowed controlled workflow. UI and draft/save-time rejection are defense-in-depth
only and must not be the sole guard. Generic `ReverseJournalEntry` must
reject reversal of a linked integrated journal and any future generic reversal that
would move an actively mapped operational asset balance without a synchronized
operational change. Enforce these checks owner-first under locks at posting time, not
only in the UI. Mapping changes cannot be used to evade a frozen historical link.

Allow reviewed legacy/opening reconciliation during rollout through its controlled
workflow, and preserve existing pre-cutover posted history. Do not retroactively reject
or mutate historical journals. Future dedicated synchronized adjustment or reversal
workflows may be added if they atomically update the operational balance, accounting
journal, cash-flow history, and link or audit trail under this contract. Until then,
generic reversal is not an operational transaction correction mechanism.

## 11. Test matrix

| Area | Required cases |
| --- | --- |
| Legacy compatibility | Before activation, existing create flow, budgets, owner isolation, exact income/expense balance changes, overdrafts, overflow rollback, and deletion restrictions retain `FinancialFoundationTest` behavior. Replace only lock-count assertions that encode the old implementation. |
| Mappings | Duplicate category names map independently by ID; owned active type checks; cross-owner composite-FK rejection; missing/inactive/type-mismatched mapping; freeze target and classification after linked posted use. |
| Integrated posting | Exact two-line income and expense postings; one-to-one posted link; date/currency/amount agreement; no float; entire rollback on balance overflow, journal failure, allocation failure, and link uniqueness failure. |
| Periods and currency | Open period succeeds; closed period and permanent fiscal close block; absent-period policy is explicit; exact currency mismatch blocks without writes. |
| Cash flow | Explicit reviewed role/activity required; cash and cash-equivalent allocation and completion; noncash posting has neither; wrong category or incomplete allocation rolls back; classification is immutable in history. |
| Concurrency and guards | Two-connection owner-first lock order, concurrent mapping/cutover/posting, whole-operation deadlock retry, database immutability, and cross-owner references. |
| Manual activity | After activation generic manual posting and generic reversal that touch active mapped balances are blocked; pre-cutover posted history remains readable; controlled opening reconciliation is allowed. |
| Rollout | 20,000.00 equity opening plus 500.00 revenue equals 20,500.00 ledger balance while stored balance is unchanged; idempotent backfill; existing chart history, missing revenue/expense accounts, unreviewed roles, closed dates, and reconciliation mismatch block readiness. |

## 12. Recommended implementation slices and commits

1. Add guarded ownership-safe schema, immutable link, mapping freeze, and migration
   tests without activating integration.
2. Add mapping review, cash-flow activity review, readiness inventory, and owner-level
   state transitions with dry-run reconciliation evidence.
3. Add one owner-first integrated posting path and shared posting validation; preserve
   operational API/UI success behavior and exact SQL balance arithmetic.
4. Add controlled historical opening reconciliation, idempotent transaction backfill,
   high-water verification, and activation checks.
5. Guard generic manual posting and reversals against active mapped balances; add
   concurrency, cash-flow, reporting, and legacy compatibility coverage.

No slice should automatically mutate production financial history or activate an owner.
