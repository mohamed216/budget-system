# D3a.1 trust and transaction research

Status: **D3a.1 isolated test-model closure: TEST_MODEL_PASS.** The operator reports
owner-authority auth 11/11, lock 4/4, replay 24/24 and full proof 39/39, with zero
unresolved cases and successful cleanup. **PRODUCTION CLOSURE NOT PROVEN.**
No production deployment or provisioning occurred as part of the D3A1 proof;
production remains untouched for this phase, including D1/D2, privileges and activation.
The next step is **D3a.2 production provisioning design/rehearsal**, not deployment.
The reviewed baseline is `438cb8c` on `phase/3-operational-accounting`.

## Execution evidence

Codex could not access the Docker API: permission denied on
`/var/run/docker.sock`. No Docker permissions were changed. Codex did not execute
live database queries or create users, grants, schemas, or routines. PHP syntax and local
RFC-vector/encoding checks can run without MySQL. They do **not** establish SQL
MAC correctness, CALL atomicity, or transaction detection.

The operator separately reports isolated MySQL 8.4.11 PASS results for SQL HMAC
vectors, object/DDL denials, tamper/cross-connection rejection, variable checks,
SAVEPOINT failure cases through 1205, explicit caller transaction cases 1-7,
autocommit=0 exclusion, and disabled `events_transactions_current` failing closed
with 1644. The automated deadlock proof also passed with R as victim, and the
neutral constraint-state guard primitive passed with restoration/reuse on runtime
connection 1654. These are operator observations on isolated MySQL 8.4.11, not
Codex execution results. The operator subsequently reported the projected
application matrix **TEST_MODEL_PASS**, recorded below.

Recorded automated deadlock evidence in `phase3_d3a1_proof`:

| Role | InnoDB transaction | Connection | User | Operation result |
|---|---:|---:|---|---|
| O | 499413 | 1646 | `d3a1_proof_owner` | Row-1 lock request completed after R was victim |
| R | 499416 | 1647 | `d3a1_proof_runtime` | CALL returned native errno 1213 / SQLSTATE 40001 |

R's native message was preserved: "Deadlock found when trying to get lock; try
restarting transaction". R then attempted COMMIT successfully. Independent reads
reported caller `id=800` count=0 and protected `ids IN (800,801)` count=0. The
harness completed with:

```text
PASS: R victim; native 1213/40001 preserved; COMMIT cannot retain caller or protected writes
```

This closes the previously missing R-victim cleanup experiment for this fixture.
It does not guarantee future victim selection or establish production finalizer
behavior.

Recorded constraint-state guard evidence on the same isolated server/schema:

| Session flags (FK/unique) | Operator-observed result |
|---|---|
| 1/1 | All four valid write classes succeeded; ordinary FK/unique/CHECK errors remained native |
| 0/1 | Flags writable; eight writes rejected by guard 45003/60031; COMMIT left baseline unchanged |
| 1/0 | Flags writable; eight writes rejected by guard 45003/60031; COMMIT left baseline unchanged |
| 0/0 | Flags writable; eight writes rejected by guard 45003/60031; COMMIT left baseline unchanged |

After each disabled state, the same runtime connection **1654** was restored to
1/1 and all four valid write classes succeeded again. Thus 24 guard rejections
covered otherwise-valid and FK-inconsistent child INSERT/UPDATE and parent
UPDATE/DELETE. The final operator result confirmed both flags restored to 1/1 and
probe rows removed. This proves the neutral deny primitive and this connection's
restoration/reuse; it does not prove application-table coverage.

Recorded projected application-matrix evidence in `phase3_d3a1_matrix`, on
isolated MySQL 8.4.11:

| Counter | Operator-observed result |
|---|---:|
| total_write_paths | 90 |
| privilege_denied_paths | 47 |
| guard_protected_paths | 43 |
| independently_protected_paths | 0 |
| trusted_routine_later_paths | 3 (subset of the 47 privilege-denied paths) |
| lock_only_update_paths | 2 (subset of the 43 guard-protected paths) |
| unresolved_paths | 0 |
| cleanup_failed | false |

The final verdict was **TEST_MODEL_PASS**, with scope **projected relations and
proposed restricted profile; production closure NOT PROVEN**. The operator
observed native privilege denials, matrix guard 45004/60041, native enabled-state
FK/CHECK errors, unchanged baselines after denied writes followed by COMMIT,
same-connection restoration to checks=1/1, functional lock-only reads, and complete
cleanup. This is operator evidence, not a live run by Codex.

## Four-obligation assessment

| Original obligation | Current evidence and disposition |
|---|---|
| Unforgeable owner authority | **PASS within the isolated owner-authority model.** The completed 39-case proof covers protected authentication, issuer owner selection, typed fact reconstruction, connection binding, key lifecycle, trusted expiry and durable replay consumption. Production authority and full-schema integration remain unproven. |
| `foreign_key_checks=0` threat closure | **PASS within the projected proof model.** The neutral primitive and all 90 projected table/operation paths pass under the proposed restricted profile, with zero unresolved paths and successful cleanup. FK-off, unique-off and both-off attacks are denied by measured guards or privileges. Full-schema compatibility, deployed privilege coverage and production installation remain unproven. |
| Failed CALL cleanup | **PASS for the observed isolated fixture cases.** SIGNAL before/B/C writes, 1062, 1452, 3819 and 1205 clean up routine writes; R-victim 1213/40001 now removes the entire R transaction even after COMMIT. No production finalizer or unobserved timeout configuration is certified. |
| Reliable same-transaction enforcement | **PASS within the isolated fixtures on MySQL 8.4.11.** The primitive transaction gate, autocommit=0 exclusion and disabled-current-event rejection pass. The owner-authority fixture additionally exercises Laravel issuance and caller-transaction finalization, rollback retry and committed consumption. Tickets are connection-bound, not bound to a transaction identifier; production integration remains unproven. |

All four original obligations now have passing evidence within their isolated
fixtures, profiles and server version. The former owner-authority/replay model
blocker is resolved by the completed proof below. Production readiness still
requires full-schema integration, effective-grant review, clean-data validation
and approved provisioning. The transaction gate alone does not prevent replay;
the owner-authority model adds transactional command consumption for that purpose.

The primitive harness deliberately avoids Laravel bootstrap; the owner-authority
harness uses a minimal Laravel fixture. Neither loads application `.env`, runs
application migrations or touches production tables. Each uses a dedicated proof schema,
an explicit isolated-container acknowledgement, a pinned server UUID, port other
than 3307, and server version 8.4.11. Those checks supplement operator verification
of the endpoint; they are not an automatic production/test identity detector.

## Owner authority decision

**CHOSEN and proven within the isolated model: an application-issued HMAC-SHA-256 ticket**, verified
by a deployment-owned routine with its key in inaccessible private storage.
The following rationale records the original design requirements; the neutral-intent
model below implements and exercises them within its stated scope. Production
approval still requires independent implementation review and production-specific
issuer/key lifecycle, fact binding, clock and replay integration. No production signer is added.

Community MySQL documents SHA2 and AES functions, but no general native HMAC or
signature-verification function. Enterprise asymmetric verification requires a
separately installed feature and is not assumed available. AES encryption or
PBKDF2 support is not a substitute for message authentication.

RFC 2104 HMAC with SHA-256 is a standard construction, not a new MAC design.
Implementing it in SQL nevertheless creates a cryptographic implementation that
needs review: binary XOR evaluation, null handling, key length, binary inner
digest, encoding, comparison timing, error responses and key secrecy. Reject
`SHA2(secret || payload)` and ad hoc encryption-based tickets. Do not promote the
proof SQL to production merely because test vectors pass.

The fixture compares SQL results to published RFC 4231 cases 1, 2, 3, 6 and PHP's
`hash_hmac`. `proof_hmac` is executable only by the owner; runtime may execute only
the proof verifier. Its verifier checks opaque bytes, not typed accounting facts.
The fixed-length comparison loop avoids early exit but is **not a claim that the
MySQL interpreter is constant-time**.

Future typed validation must reconstruct the signed bytes in a fixed field order:
protocol/version and key version, request UUID, owner ID, transaction/account/
category/journal IDs, income/expense, canonical positive two-decimal amount, ISO
date, uppercase currency, immutable cutover identity/nonce, issued/expiry times,
and target physical connection ID. Use unambiguous length-prefixed UTF-8/ASCII
fields; validate every value before signing and again in SQL. No float conversion,
JSON reserialization assumptions, or independent unsigned duplicate arguments.
Expiry must use a trustworthy database clock; session `timestamp` is caller
controlled, so a future design must evaluate the configured behavior of SYSDATE
and must not trust caller-adjustable NOW alone.

Key distribution is controlled provisioning, outside runtime SQL. The signing key
is not placed in runtime-readable tables or exposed through a signing routine.
The issuer must authorize the owner independently of caller-supplied IDs and bind
current prepared rows. A MAC proves issuer approval, not user authentication by
itself. Runtime-readable sessions and writable authentication records also require
an issuer threat review if attackers may use HTTP after acquiring SQL credentials.

Modification/cross-owner/cross-command reuse changes signed bytes and invalidates
the MAC. A connection-bound ticket rejects another physical connection; a retry
on a new connection needs a newly issued ticket for the same request identity.
Connection ID is a signed restriction, **not** owner authentication. Durable
private UNIQUE command/result identity must serialize replay and return/reject
according to exact payload identity without applying a second movement. On outer
rollback that command consumption rolls back too, permitting a legitimate retry.
Private context must still be created and destroyed inside the trusted CALL.

**REJECTED: opaque capability registered through runtime SQL.** Runtime could
register its own. Registration by a distinct trusted issuer could work, but no
such path exists and a second privileged DB connection would violate this design.
It is not smaller than the signed-ticket approach.

**REJECTED: MySQL-native owner identification under a shared DB login.** USER,
CURRENT_USER, connection attributes, IP, connection ID, session flags, and
Performance Schema do not establish application-owner authority. The signing
proof does not relax this threat model.

## Constraint-disabled audit

The following is a repository audit, not live attack results. All attacks assume
the previously proposed restricted DML allowlist. CHECK/domain constraints remain
separate from FKs; `foreign_key_checks=0` does not inherently disable CHECKs.
`unique_checks=0` is not assumed to disable every unique index; the harness only
tests that it is settable, not a guaranteed duplicate-insertion exploit.

| Table/path | Constraint-disabled attack | Existing independent protection / required disposition |
|---|---|---|
| `users` | Change a parent ID and orphan owned rows; permitted authentication edits may undermine a ticket issuer | No ACTIVE parent-ID trigger. Add independent identity/reference protection or narrow column privileges; review issuer authentication separately. |
| `accounts` | Insert nonexistent owner; delete/reassign unselected or pre-ACTIVE referenced source | D1 blocks selected ACTIVE OLD owner/id/currency/balance/delete. It does not replace all owner/existence/reference FKs. New guard required. |
| `categories` | Insert nonexistent owner; delete a referenced pre-ACTIVE/unselected category | Restricted runtime lacks UPDATE; D1 selected ACTIVE delete/type/owner freeze remains. INSERT/DELETE still need a guard. |
| `chart_of_accounts` | Insert nonexistent owner; delete or reassign a referenced pre-ACTIVE/unrelated chart | D1 ACTIVE participant semantics/delete protected; ordinary parent references remain FK-dependent. Guard required. |
| `journal_entries` | Draft with nonexistent owner or foreign `reversal_of_id`; publish journal under owner B with lines using owner A's reserved chart | Insert-posted and posted mutation guards remain. Generic publication does not independently prove all line/chart ownership, balance, or reversal ownership. New ownership guard or trusted publication restriction required. |
| `journal_lines` | Insert line into draft journal using foreign owner/chart; delete/reinsert altered draft identity | Posted-parent trigger checks parent status by ID, not complete composite ownership. CHECK ensures one-sided amount, not ownership. New guard required. |
| `transactions` | Insert foreign account/category or nonexistent owner; change unlinked transaction's identities | Linked facts frozen independently; restricted UPDATE also needed for FOR UPDATE reads. Unlinked inserts/updates remain FK-dependent. New guard; future ACTIVE source insertion contract must be explicit. |
| `operational_accounting_integrations` | Insert missing owner or reassign pre-ACTIVE owner; raw transition to ACTIVE is not an application authorization check | CHECKs, review-binding checks and D1 OLD ACTIVE freeze remain. Owner FK still needs guard; activation authorization remains D4 work, not supplied by D1. |
| Account/category scope | Reassign existing pre-ACTIVE scope source/owner to a foreign row | Runtime INSERT/DELETE unnecessary and should remain absent. D1 ACTIVE OLD/NEW owner checks freeze scopes, but pre-ACTIVE UPDATE still needs independent ownership/existence checks. |
| Account mappings | Insert selected foreign source/target under non-ACTIVE owner; change unused target to foreign chart | Identity and linked-use freezes remain; D1 explicit ACTIVE participant frozen. Missing owner-safe FK validation requires guard on allowed INSERT/UPDATE. Remove DELETE. |
| Category mappings | Foreign category/chart/reviewer in new or unused pre-ACTIVE mapping | Same linked-use/D1 freezes; reviewer and target relationships still require guard. Remove DELETE. |
| `opening_balance_batches` | Foreign owner/equity target/linked journal on draft; orphan parent | Posted rows frozen independently. Draft relations still need guard. |
| `opening_balance_lines` | Foreign chart or mismatched batch owner; delete a referenced draft source | Posted-parent guards remain, composite ownership still FK-dependent. Guard required. |
| `journal_line_allocations` | Foreign/missing line endpoints, mismatched owner/journal; nonexistent parent accepted on INSERT lookup returning NULL | Existing INSERT guard denies completed journals, but does not deny every missing parent or validate line ownership. CHECKs do not replace composite references. Guard required. |
| `cash_flow_journal_completions` | INSERT with missing/foreign journal/reviewer; NULL parent lookup is not rejected | UPDATE/DELETE always rejected independently. INSERT needs guard; UPDATE currently granted only to permit existing FOR UPDATE reads. |
| `fiscal_year_closes` | INSERT foreign retained-earnings chart/journal/owner | Date CHECK is not ownership/type/provenance validation. Guard required; UPDATE exists only for lock privileges. |
| `operational_transaction_journals` | Attempt manufactured link | Runtime should have SELECT only. Existing INSERT trigger independently validates owned transaction/account/mappings/posted journal and line shape; UPDATE/DELETE reject. Later creation belongs only to finalizer. |
| `operational_reconciliation_reviews` | Attempt completion with missing reviewer; insert missing owner during maintenance | Runtime SELECT/UPDATE only. Header identity and sealed state protected, but reviewer existence still FK-dependent. Guard allowed UPDATE; INSERT remains controlled maintenance. |
| Reconciliation charts/accounts/categories/journals/attributions | Forge evidence parent/source ownership | Runtime SELECT only. Existing evidence triggers independently check owned parents and sources and reject sealed changes. No runtime writes needed; retain exact guards for maintenance and audit reviewer/parent-root relations. |

Minimal recommended closure is an **additive constraint-state guard** on every
allowed INSERT/UPDATE/DELETE in this trust graph, including parent `users`, that
rejects if `@@session.foreign_key_checks <> 1` or `@@session.unique_checks <> 1`.
This is a deny condition, not a caller-controlled authorization exception. With
checks enabled, enforced owner-safe FKs remain mandatory. Require validated clean
data at installation: re-enabling FK checks does not retrospectively validate
rows written while disabled. Do not infer or automatically repair.

This application-wide guard is a future proposal, **not added to production and
not a D1 modification**. Both the neutral TEST-ONLY primitive and the projected
application matrix have now passed live. Actual application-schema integration
and production closure remain unproven.
Full independent parent/child validation could replace it but is larger and must
protect parent deletions/reassignments as well as child inserts. Checks alone do
not establish valid accounting publication or authenticate an owner.

Loss of runtime writes should be explicit: link INSERT/UPDATE/DELETE, scope
INSERT/DELETE, evidence child writes and review INSERT/DELETE are unnecessary for
current requests. Preserve UPDATE grants needed by FOR UPDATE, or separately
review lock changes; do not break legacy requests to obtain SELECT-only grants.

The harness proves the basic FK-off orphan using neutral child/parent tables,
variable mutability, and sql_log_bin denial. It does not execute this entire attack
matrix against a full migrated application schema. The later projected matrix
covers the audited paths in a reduced model; full-schema compatibility remains a
production prerequisite.

## CALL failure candidate

`proof_failure` owns one savepoint. Its EXIT handler captures the original errno,
rolls back to and releases that savepoint, then RESIGNALs. For native 1213 it skips
savepoint cleanup because the engine already rolled back the transaction. During
cleanup it tolerates only 1305 (savepoint absent after full abort); it never
converts 1205/1213 to accounting conflicts. The isolated fixture has passed the
listed operator-observed failure cases, including R-victim deadlock cleanup; a
production error handler is not implemented or certified. Reserve the savepoint name: procedures
share the caller's savepoint namespace and must not replace a caller savepoint.

Expected survival after a catch followed by COMMIT:

| Failure | Original native errno | Caller A | Routine B/C |
|---|---:|---|---|
| Before writes / after B / after C SIGNAL | 1644 / 45000 | survives | absent |
| Duplicate key | 1062 | survives | absent |
| Foreign key | 1452 | survives | absent |
| CHECK | 3819 | survives | absent |
| Timeout, rollback-on-timeout OFF | 1205 | survives | absent |
| Timeout, rollback-on-timeout ON | 1205 | absent | absent |
| Deadlock victim | 1213 | absent | absent |
| Successful CALL then outer rollback | none | absent if written | absent |

No implicit commit, transaction replacement, autocommit change, or production DDL
is allowed inside the eventual finalizer. Application D3b must roll back its whole
transaction on CALL failure so caller-owned setup rows also disappear; the raw
catch-and-COMMIT proof intentionally allows A for ordinary statement failures.

The harness covers the first seven failure variants plus successful outer
rollback; timeout expectations adapt to the server's read-only configuration.
Deadlock is a separate opt-in automated proof below. Connection-loss and
other unobserved failures are outside these cleanup results. Both timeout
configuration branches in the table are expected behavior; the reported 1205
PASS covers the operator's tested configuration, not evidence of testing both.

## Explicit caller transaction candidate

`proof_transaction` performs a harmless InnoDB anchor read to materialize lazy
instrumentation, then reads the current session's Performance Schema event under
definer privileges. It requires ACTIVE state, NULL END_EVENT_ID, AUTOCOMMIT=NO and
session autocommit=1. The last condition deliberately excludes autocommit=0
implicit transactions; it does not serve as the positive transaction proof.
It creates no permission and uses no PFS data for owner authentication.

Cases in the harness: autocommit without BEGIN rejects; explicit BEGIN accepts;
read-only-so-far accepts; after write accepts; Laravel-style SAVEPOINT accepts;
after rollback rejects; reused and independent connections reject without BEGIN.
PDO beginTransaction uses the same server transaction mechanism as Laravel, but
this is not a framework integration test. Instrumentation absent must fail closed.

**The isolated transaction gate now passes the reported MySQL 8.4.11 cases.**
Explicit caller transaction cases 1-7 pass, autocommit=0 is excluded, and disabled
`events_transactions_current` rejects with native 1644. This closes the reported
gate experiment, including rejection after rollback/reuse and missing current
instrumentation. PFS enabled alone still does not establish enabled transaction
instruments/current-event consumers; the routine must retain its fail-closed checks.
These observations do not prove application setup/finalizer transaction identity
or a ticket's binding to one transaction, and do not certify other server versions.
Do not use unused TRX_ID, connection attributes or a snapshot merely containing a
previous event. No runtime PFS grants are needed. A SAVEPOINT-only existence probe
is not chosen without evidence that autocommit CALL behavior cannot satisfy it.

## Isolated operator setup

Use only `budget-phase23-mysql-test`, never production port 3307. Operator first
checks the test container version/UUID and its published port:

```sh
docker inspect --format '{{json .NetworkSettings.Ports}}' budget-phase23-mysql-test
docker exec -it budget-phase23-mysql-test mysql -uroot -p -e 'SELECT VERSION(), @@server_uuid;'
```

In an interactive test-admin mysql session on that container, create an empty
`phase3_d3a1_proof` schema and two **test-only** identities. Replace password
placeholders privately, not in repository files, command arguments, or logs:

```sql
CREATE DATABASE phase3_d3a1_proof;
CREATE USER 'd3a1_proof_owner'@'%' IDENTIFIED BY '<operator-supplied test secret>';
CREATE USER 'd3a1_proof_runtime'@'%' IDENTIFIED BY '<different operator-supplied test secret>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, CREATE ROUTINE, EXECUTE
  ON phase3_d3a1_proof.* TO 'd3a1_proof_owner'@'%';
GRANT SELECT ON performance_schema.threads TO 'd3a1_proof_owner'@'%';
GRANT SELECT ON performance_schema.events_transactions_current TO 'd3a1_proof_owner'@'%';
GRANT SELECT ON performance_schema.data_lock_waits TO 'd3a1_proof_owner'@'%';
GRANT SELECT ON performance_schema.data_locks TO 'd3a1_proof_owner'@'%';
```

Before running, the test admin verifies `transaction` in setup_instruments and
`events_transactions_current` in setup_consumers are enabled. Configure them only
on the isolated test instance if needed. The owner does not receive grant authority
or production-schema access. No runtime PFS access or broad routine grant.

Supply environment in a private shell; hidden read prompts keep passwords out of
history and output. HOST/PORT must come from the inspected test-container mapping:

```sh
export D3A1_ISOLATED_TEST=budget-phase23-mysql-test
export D3A1_DSN='mysql:host=127.0.0.1;port=<verified-test-port>;dbname=phase3_d3a1_proof;charset=utf8mb4'
export D3A1_SERVER_UUID='<verified-test-server-uuid>'
export D3A1_OWNER_USER=d3a1_proof_owner
export D3A1_RUNTIME_USER=d3a1_proof_runtime
read -rs -p 'Test owner password: ' D3A1_OWNER_PASSWORD
export D3A1_OWNER_PASSWORD
read -rs -p 'Test runtime password: ' D3A1_RUNTIME_PASSWORD
export D3A1_RUNTIME_PASSWORD
php tests/Proofs/d3a1.php --install
```

After installation, the test admin grants only the following runtime access:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON phase3_d3a1_proof.caller_writes TO 'd3a1_proof_runtime'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON phase3_d3a1_proof.child_rows TO 'd3a1_proof_runtime'@'%';
GRANT SELECT, UPDATE ON phase3_d3a1_proof.lock_rows TO 'd3a1_proof_runtime'@'%';
GRANT EXECUTE ON PROCEDURE phase3_d3a1_proof.proof_verify TO 'd3a1_proof_runtime'@'%';
GRANT EXECUTE ON PROCEDURE phase3_d3a1_proof.proof_failure TO 'd3a1_proof_runtime'@'%';
GRANT EXECUTE ON PROCEDURE phase3_d3a1_proof.proof_transaction TO 'd3a1_proof_runtime'@'%';
```

Multiple public procedures are deliberate **proof-only** probes, not the future
one-entry-point production API. Test grants apply only to the neutral proof schema.

```sh
php tests/Proofs/d3a1.php
unset D3A1_OWNER_PASSWORD D3A1_RUNTIME_PASSWORD
```

Expected output reports individual checks; any failed assertion stops with BLOCKED.
Do not interpret its final NOT PROVEN line as complete D3a approval. Runs reset
only proof data, never schema objects; --install refuses nonempty schema. Routines
remain after a failed run for inspection. Cleanup belongs to the test admin after
results are recorded, explicitly naming this proof schema and these two users.

## Automated deadlock proof (preferred; operator, test schema only)

**Operator-observed PASS:** the recorded run selected R as victim and verified
whole-R-transaction cleanup after COMMIT. The evidence is recorded above; retain
the outcome branches below for repeat runs because victim choice is not guaranteed.

Use the existing exported `D3A1_*` identities, secrets, acknowledgement and pinned
isolated server UUID. The isolated owner needs SELECT on `data_locks`,
`data_lock_waits` and `threads` as listed above. If the earlier setup omitted
`data_locks`, an isolated-test admin must supply that test-only grant first;
the harness does not change grants and fails BLOCKED when metadata is unavailable.
No server deadlock or lock-timeout settings are changed by this mode.

From the working tree, this one command builds/reuses the existing Dockerfile's
PHP CLI image and runs a disposable proof container sharing only the named test
container's network namespace. It does not start, exec into, or deploy the
production app. The repository is mounted read-only. Secrets are forwarded by
environment-variable name, never expanded into command arguments. The internal
loopback DSN deliberately replaces a host-published-port DSN; port 3307 is unused.

```sh
docker run --rm \
  --network container:budget-phase23-mysql-test \
  --mount type=bind,src=/home/mhmed216/budget-system-phase3,dst=/proof,readonly \
  --workdir /proof \
  --env D3A1_ISOLATED_TEST --env D3A1_SERVER_UUID \
  --env D3A1_OWNER_USER --env D3A1_OWNER_PASSWORD \
  --env D3A1_RUNTIME_USER --env D3A1_RUNTIME_PASSWORD \
  --env 'D3A1_DSN=mysql:host=127.0.0.1;port=3306;dbname=phase3_d3a1_proof;charset=utf8mb4' \
  "$(docker build -q -f /home/mhmed216/budget-system-phase3/Dockerfile /home/mhmed216/budget-system-phase3)" \
  php tests/Proofs/d3a1.php --deadlock-proof
```

The controller uses a third owner PDO connection for metadata and independent
committed reads. O and R each run in a child PHP process, launched with array argv
and inherited environment. No `pcntl` extension is assumed: `proc_open` and
`stream_select` are checked at runtime before probe writes. Each connection checks
MySQL version and the pinned server UUID. A proof-specific named lock serializes
automated runs; occupied probe IDs or existing proof locks cause refusal.

O updates row 2 and inserts 64 caller probe rows (810000-810063) within its
transaction to favor O surviving under InnoDB's transaction-size heuristic.
R inserts caller 800 and updates row 1. O then requests row 1 in its child.
The controller queries `data_lock_waits`, `data_locks` and `threads` until it
observes the exact worker connection IDs: O WAITING on PRIMARY key 1, R holding
that key with a granted X record lock, and O holding PRIMARY key 2. Only this
observed edge authorizes R's `CALL proof_failure('timeout', 800)`.
There are no fixed synchronization sleeps. Missing/NULL lock metadata or failure
to establish the edge within 15 seconds produces BLOCKED. Pipe responses also
have bounded deadlines; readiness and EOF drive process coordination/cleanup.

Both operation results are recorded with connection IDs. A victim must report
native errno 1213 **and** SQLSTATE 40001; its native driver message is preserved.
Exactly one victim and a successful surviving operation are required. Nothing is
normalized to an accounting conflict, and neither worker commits/rolls back until
the controller has recorded both outcomes and established victim identity.

- **R victim: PASS** only after R attempts SQL COMMIT and independent reads report
  caller 800 count=0 and protected 800/801 count=0. Surviving O is rolled back.
- **O victim: INCONCLUSIVE/SKIP**, exit 0 with an explicit non-PASS result. R's CALL
  must succeed and R is explicitly rolled back. This does not prove failed-CALL
  deadlock cleanup. Victim selection is never asserted to be guaranteed.
- **Unexpected error, missing native victim, unavailable metadata, coordination
  or cleanup failure: BLOCKED**, exit 1. No PASS is emitted before cleanup completes.

Workers unconditionally execute ROLLBACK in finally on normal completion, command
failure or controller EOF. The controller's finally closes/reaps workers and
terminates a child blocked in PDO when necessary, which closes its connection for
engine rollback. It observes worker lock release before removing reserved probe
rows and releases the coordination lock. Pre-existing probe rows are never erased.
No new schema objects, fixture routines or settings are needed. The ordinary
proof run remains independent of deadlock timing and must not be used in place of
this dedicated option. Codex's local checks do not prove the live deadlock result.

## Manual deadlock diagnostic fallback

Two interactive sessions, runtime R and owner O; neither should auto-retry:

1. O: `START TRANSACTION; UPDATE lock_rows SET value=value+1 WHERE id=2;`
2. R: `START TRANSACTION; INSERT INTO caller_writes VALUES (800); UPDATE lock_rows SET value=value+1 WHERE id=1;`
3. O: issue `UPDATE lock_rows SET value=value+1 WHERE id=1;` (blocks).
4. Test admin confirms O's pending request in `performance_schema.data_lock_waits`
   before proceeding; do not coordinate using a fixed sleep.
5. R: `CALL proof_failure('timeout',800);` attempts row 2, completing a cycle.
6. Record which session receives native 1213. Victim selection is not guaranteed.
   If O is victim, this has NOT proved R's missing-savepoint handler; repeat with
   O performing more fixture-only updates before step 3 to increase its undo cost.
7. If R is victim, R attempts COMMIT: owner then verifies caller 800 and protected
   800/801 are absent. If O is victim and CALL succeeds, explicitly roll R back;
   do not claim a failed-CALL proof. Roll back the surviving O transaction too.

Use a bounded operator/session timeout and terminate the experiment if coordination
fails. Never change server production lock timeout/deadlock settings for this proof.

## Constraint-state guard primitive (TEST-ONLY; operator-observed PASS)

The operator executed this mode successfully on isolated MySQL 8.4.11. All three
disabled flag states produced the exact guard denial, COMMIT preserved the
baseline, and connection 1654 repeatedly returned to safe flags and valid writes.
The evidence table above records the result. Retain the installation/run steps
below for fresh isolated fixtures and repeat runs; no reinstall is required to
update this assessment.

This opt-in fixture uses new neutral tables `d3a1_guard_parents` and
`d3a1_guard_children`. The existing unguarded parent/child fixtures remain intact
so the normal FK-off counterexample, CALL cleanup and deadlock modes remain
independent. Neither the ordinary `--install` nor the proof run installs guards.
Only the separate `--install-constraint-guard` option executes the six
`-- D3A1 GUARD STATEMENT` blocks at the end of `tests/Proofs/d3a1.sql`.

Four BEFORE row triggers cover child INSERT/UPDATE and parent UPDATE/DELETE. Each
has only this deny predicate:

```sql
IF @@session.foreign_key_checks <> 1 OR @@session.unique_checks <> 1 THEN
    SIGNAL SQLSTATE '45003' SET MYSQL_ERRNO = 60031,
        MESSAGE_TEXT = 'D3A1 constraint-state guard: checks must be enabled';
END IF;
```

The predicate grants no authority or exception. Enabled checks merely allow the
write to proceed to ordinary privilege/FK/unique/CHECK enforcement. PHP only
orchestrates tests and verifies diagnostics; the database triggers reject writes.
The harness requires the exact SQLSTATE, errno and message together, so an FK,
index or unrelated trigger error cannot masquerade as a guard rejection.
MySQL supports these explicit diagnostic items in
[SIGNAL](https://dev.mysql.com/doc/refman/8.4/en/signal.html).

Ownership/security assumption: all four triggers explicitly use
`'d3a1_proof_owner'@'%'` as DEFINER. The harness checks the trigger names, tables,
BEFORE timing, write events and owner against the owner's authenticated account.
Runtime receives only DML on the two neutral tables, with no TRIGGER/DDL privilege
or grant authority. Trigger activation uses definer privileges, including TRIGGER
on the subject tables; the flags being inspected are the executing session's deny
conditions, not caller authentication. See
[MySQL CREATE TRIGGER](https://dev.mysql.com/doc/refman/8.4/en/create-trigger.html).

Provision only in `phase3_d3a1_proof` on `budget-phase23-mysql-test`. The isolated
admin must supply the owner's proof-schema TRIGGER privilege:

```sql
GRANT TRIGGER ON phase3_d3a1_proof.* TO 'd3a1_proof_owner'@'%';
```

Install the separate fixture with `--install-constraint-guard` using the Docker
invocation below with that option substituted. It refuses existing guard tables
or triggers. MySQL binary-log policy may require additional administrative
privilege for CREATE TRIGGER: if the owner is denied, stop that installation and
have the isolated root/admin apply the six guard statement blocks individually
with normal mysql delimiter handling and the explicit owner DEFINER. Do not
change server settings or expand the runtime's privileges. DDL is not atomic;
partial installation remains BLOCKED until the test admin reviews/removes only
these dedicated fixture objects before retrying. No automatic DROP/replacement.

After the tables/triggers exist, the isolated admin grants:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON phase3_d3a1_proof.d3a1_guard_parents
  TO 'd3a1_proof_runtime'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON phase3_d3a1_proof.d3a1_guard_children
  TO 'd3a1_proof_runtime'@'%';
```

These are test-only provisioning steps; the proof runner never changes grants.
With the existing exported `D3A1_*` contract and isolated pinned UUID, run:

```sh
docker run --rm \
  --network container:budget-phase23-mysql-test \
  --mount type=bind,src=/home/mhmed216/budget-system-phase3,dst=/proof,readonly \
  --workdir /proof \
  --env D3A1_ISOLATED_TEST --env D3A1_SERVER_UUID \
  --env D3A1_OWNER_USER --env D3A1_OWNER_PASSWORD \
  --env D3A1_RUNTIME_USER --env D3A1_RUNTIME_PASSWORD \
  --env 'D3A1_DSN=mysql:host=127.0.0.1;port=3306;dbname=phase3_d3a1_proof;charset=utf8mb4' \
  "$(docker build -q -f /home/mhmed216/budget-system-phase3/Dockerfile /home/mhmed216/budget-system-phase3)" \
  php tests/Proofs/d3a1.php --constraint-guard-proof
```

Checks now live-proven by the operator:

- Checks 1/1: valid child INSERT/UPDATE and unreferenced parent UPDATE/DELETE
  succeed. Orphan child INSERT/UPDATE return 1452, referenced parent key
  UPDATE/DELETE return 1451, duplicate child returns 1062 and invalid CHECK value
  returns 3819. These errors must remain distinct from the guard.
- Checks 0/1, 1/0 and 0/0: runtime SET succeeds and SELECT confirms the requested
  flags. In each state, all four otherwise-valid writes and all four
  FK-inconsistent writes return the exact guard diagnostic. The runtime attempts
  COMMIT; independent owner reads must show the complete baseline unchanged.
- After each disabled state, finally restores both flags to 1, verifies them,
  checks the same CONNECTION_ID, and repeats all four valid writes successfully.
  Successful enabled/reuse probes are rolled back.

A fixture-specific named lock prevents concurrent automated guard runs. Existing
probe IDs are refused before any seeding. Only the reserved parent 910001-910009
and child 920001-920002 ranges are cleaned up, child first. Finally paths roll back
transactions, attempt both flag restorations even if one fails, and verify safe
state. Restoration/rollback failure discards the runtime connection and produces
BLOCKED. The final PASS is emitted only after cleanup and zero-probe-row checks.
Missing guards, grants, wrong diagnostics, changed rows or failed cleanup produce
BLOCKED. The successful operator run confirmed safe restored flags and removal
of probe rows. No SQL proof was executed by Codex; operator evidence, not local
checks, establishes this primitive's PASS. Failure-injection coverage of every
restoration/cleanup failure branch is not claimed.

This primitive proves denial for the four tested write classes and reuse of
one session after restoration. It does not prove owner authority or close the
full application-table threat model. Parent INSERT and child DELETE have no guard
in this narrowly scoped fixture; cascades, other statements and application
trigger interactions are not covered. Full allowed-DML table/operation coverage,
independent ownership/FK validation and clean-data prerequisites remain a later
slice. Re-enabling checks is not retrospective validation of pre-existing data.

## Application-table constraint-disabled matrix (operator-proven TEST_MODEL_PASS)

The new modes are `--install-application-matrix` and
`--application-matrix-proof`. Existing proof modes retain their original schema,
identities and fixtures. This model uses only `phase3_d3a1_matrix` in
`budget-phase23-mysql-test`, accessed through its loopback port 3306 and pinned
UUID. No code connects to production port 3307 or `budget_system_test`.

### Authoritative code-usage inventory and proposed restricted profile

The inventory was re-audited against HEAD `438cb8c`: HTTP routes/controllers,
legacy/accounting actions, dependency loaders, framework auth/session/cache/queue
SQL, migrations and configuration. The workspace's nonsecret `.env` selectors
are SESSION_DRIVER=database, CACHE_STORE=database and QUEUE_CONNECTION=database;
failed-job configuration defaults to database-uuids. The normal worker launched
by composer dev uses the database queue. Database sessions and login throttling
have concrete current callers. No current app/route job batching or atomic cache
lock consumer was found, so `job_batches` and `cache_locks` are excluded; the
password-reset table has no current reset/registration route and is also excluded.
A different effective backend, custom table name, deployed cached configuration,
new route/worker or maintenance identity needs a separately reviewed profile.
This is an authoritative baseline **usage** inventory, not a claim about existing
production grants; no production grants were inspected or changed.

Unwired public operational actions are recorded as maintenance capabilities,
not normal HTTP/worker mutations: Begin/Capture/Map/Complete/Ready reconciliation
methods have no first-party route or dispatched job caller. Request dependency
loaders use shared locks on scopes, mappings and review/evidence. Those reads do
not justify UPDATE. Do not give this normal runtime identity to a maintenance
script and then claim the maintenance flow is covered by this profile.

Legend for INSERT/UPDATE/DELETE:

- G = GUARDED_WRITE: actual normal mutation; retain privilege and add a proposed
  TEST-ONLY constraint-state guard. There are 41 such paths.
- L = LOCK_ONLY_UPDATE: retain UPDATE for locking, deny actual UPDATE even with
  checks enabled. Two paths: cash-flow completions and fiscal closes.
- R = REMOVE_RUNTIME_PRIVILEGE: no normal mutation; omit the grant and prove
  native denial. There are 44 such paths.
- T = TRUSTED_ROUTINE_ONLY_LATER: direct operational-link mutations remain denied;
  no finalizer exists yet. Three paths, included in the 47 privilege-denied total.

SELECT here records actual normal reads, not owner/monitor inspection. UPDATE
on users is needed for framework password rehash/remember-token persistence,
not registration or parent identity editing. Narrowing user UPDATE columns is a
future privilege refinement, separate from this disabled-state model. Categories,
journal/opening lines and allocations already need DELETE; MySQL's FOR UPDATE
can use that privilege instead of granting an otherwise unused UPDATE. This is
verified through locking-read controls in the matrix, not assumed from PHP.
See [MySQL locking-read privileges](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html).

| Table | SELECT | INSERT | UPDATE | DELETE | Code evidence / scope |
|---|---|---|---|---|---|
| `users` | yes | R | G | R | AccountingPeriodLocks::owner; Auth EloquentUserProvider rehashPasswordIfRequired/updateRememberToken; no registration/deletion route |
| `accounts` | yes | G | G | G | AccountController::store; PostTransaction DB::update(balance); DeleteAccount |
| `categories` | yes | G | R | G | CategoryController::store; DeleteCategory; DELETE already supports locking; no normal UPDATE |
| `budgets` | yes | G | G | G | SaveBudget::updateOrCreate; BudgetController::destroy |
| `chart_of_accounts` | yes | G | G | G | Create/Update/DeleteChartAccount; ChartHierarchy locks |
| `journal_entries` | yes | G | G | G | Save/DeleteJournalDraft; PostJournalEntry; ReverseJournalEntry; opening/fiscal posting |
| `journal_lines` | yes | G | R | G | Save/DeleteJournalDraft replace lines; posting/reversal inserts; DELETE supports locks; no UPDATE of existing lines |
| `transactions` | yes | G | R | R | PostTransaction inserts only; CaptureOperationalReconciliationBoundary is unwired maintenance |
| `accounting_periods` | yes | G | G | G | AccountingPeriodController Create/Update/Close/Reopen/Delete actions |
| `operational_accounting_integrations` | yes | R | G | R | PostTransaction and accounting actions invalidate metadata; Begin/Complete/Ready are unwired maintenance |
| `operational_accounting_account_scope` | yes | R | R | R | OperationalReconciliationChartDependencies uses sharedLock; no request/worker scope mutations |
| `operational_account_mappings` | yes | R | R | R | ChartDependencies reads with sharedLock; MapOperationalAccount exists but has no route/worker caller |
| `operational_accounting_category_scope` | yes | R | R | R | OperationalReconciliationChartDependencies uses sharedLock; no request/worker scope mutations |
| `operational_category_mappings` | yes | R | R | R | ChartDependencies reads with sharedLock; MapOperationalCategory exists but has no route/worker caller |
| `opening_balance_batches` | yes | G | G | G | Save/Post/DeleteOpeningBalanceDraft and PostOpeningBalanceBatch |
| `opening_balance_lines` | yes | G | R | G | Draft save replaces lines; deletion; DELETE supports locks; no UPDATE |
| `journal_line_allocations` | yes | G | R | G | Save/DeleteJournalDraft; CompleteHistoricalCashFlowJournal; CopyReversalCashFlowAllocations; DELETE supports locks |
| `cash_flow_journal_completions` | yes | G | L | R | PostJournalEntry/ReverseJournalEntry/CompleteHistoricalCashFlowJournal INSERT; FOR UPDATE reads need retained UPDATE |
| `fiscal_year_closes` | yes | G | L | R | CloseFiscalYear INSERT; overlap/reversal/completion FOR UPDATE need retained UPDATE |
| `operational_transaction_journals` | yes | T | T | T | Read by ReverseJournalEntry/history; no runtime finalizer exists; direct mutations routine-only later |
| `operational_reconciliation_reviews` | yes | R | R | R | Request Chart/HistoryDependencies uses sharedLock; Complete/Ready actions unwired; UPDATE belongs to separate maintenance profile |
| `operational_reconciliation_charts` | yes | R | R | R | Chart/HistoryDependencies shared reads; evidence population is controlled maintenance, no request/worker writer |
| `operational_reconciliation_accounts` | no | R | R | R | Chart/HistoryDependencies shared reads; evidence population is controlled maintenance, no request/worker writer |
| `operational_reconciliation_categories` | no | R | R | R | Chart/HistoryDependencies shared reads; evidence population is controlled maintenance, no request/worker writer |
| `operational_reconciliation_journals` | yes | R | R | R | Chart/HistoryDependencies shared reads; evidence population is controlled maintenance, no request/worker writer |
| `operational_reconciliation_attributions` | no | R | R | R | HistoryDependencies/evidence read; no request/worker mutation |
| `sessions` | yes | G | G | G | SESSION_DRIVER=database; DatabaseSessionHandler writes/sweeps; user_id has no FK in actual schema |
| `cache` | yes | G | G | G | CACHE_STORE=database; login throttle RateLimiter/DatabaseStore add/upsert/increment/delete |
| `jobs` | yes | G | G | G | QUEUE_CONNECTION=database; composer dev queue:listen; DatabaseQueue insert/reserve/release/delete |
| `failed_jobs` | no | G | R | R | DatabaseUuidFailedJobProvider worker failure INSERT; forget/prune/retry are operator maintenance, not normal worker mutations |

The machine-readable inventory is `tests/Proofs/d3a1-application-matrix.json`.
It includes per-operation classifications, actual key/reference column names,
code evidence, excluded backends and SHA-256 fingerprints of the audited sources.
The installer, proof and local self-test reject changed source fingerprints.
The accompanying grants SQL grants only the proposed profile to a fresh identity;
the harness compares SHOW GRANTS exactly and refuses broader schema/global grants,
roles, DDL, TRIGGER, EXECUTE or GRANT OPTION. Needed INSERT is not withheld merely
to make an attack privilege-denied; needed current mutations remain G. Journal
posting/lines are potential future D3a/D3b routine-boundary candidates but retain
current required direct privileges in this model so existing request writes are
not silently removed.

### Model, attacks, controls and reporting

The installer creates a minimum relationship projection of all 30 inventoried
tables, not a migrated full application copy. Only real baseline column names and
owner/reference tuples are used: nonexistent/foreign users and parents, source/
chart mappings, journal/chart/line/reversal relationships, operational account/
category links, batch/line ownership, fiscal retained-earnings charts, allocations,
review identity/reviewer/predecessor and reconciliation evidence dependencies.
The cash-flow completion table has no reviewer column in this baseline, so no
fictional reviewer attack is invented there. Sessions.user_id has no actual FK;
the model does not add one. Backend key/payload attacks have no invented owner FK.

The projection keeps actual primary-key shapes, referenced owner keys, restrictive
FK relationships, one-sided line CHECKs and positive/distinct allocation CHECKs.
Non-key attributes/types, unrelated CHECK/unique constraints, generated fields
and original production triggers are deliberately not fully reproduced. The model
therefore cannot establish full application compatibility or existing D1/D2
trigger integration. D1/D2 source files and production definitions are untouched.
No existing database logic receives an INDEPENDENTLY_PROTECTED classification:
that total is zero, since trigger presence alone is not evidence. Future removal
of a modeled guard would require a targeted independent rejection proof.

Each G or L operation has a TEST-ONLY owner-defined BEFORE row trigger. The deny
predicate is exactly the proven FK/unique flag primitive. Its unique diagnostics
are SQLSTATE 45004, errno 60041 and message
`D3A1 application matrix: checks must be enabled`. For L UPDATE, after that
predicate the trigger unconditionally rejects mutation with 45005/60042 and
`D3A1 matrix lock-only mutation denied`. These are deny-only rules; they grant
no authority. Runtime cannot ALTER tables or DROP triggers; both denials are
actually probed, and guard names/timing/events/definer are verified.

For all 90 table/operation paths, the proof sets and verifies flags 0/1, 1/0 and
0/0 on the same runtime connection. It generates attacks from the real owner/
reference columns and parent identities, and tests parent/row deletion. Each
write must reach its exact guard or native privilege error 42000/1142. FK errors
alone are not accepted as disabled-state protection. Each rejection is followed
by SQL COMMIT and independent owner reads hashing the complete baseline.

The proof additionally tests INSERT IGNORE, INSERT SELECT, multi-row INSERT,
REPLACE, INSERT-on-duplicate-update, and multi-row UPDATE/DELETE. Compound statements
requiring a deliberately omitted DELETE/UPDATE privilege must fail by privilege;
other writable variants must reach the guard. The baseline relationships are
restrictive, with no cascade introduced by this model; deployed custom referential
rules/cascade behavior require a full-schema integration proof, not inference.

After every disabled state, finally restores and verifies 1/1 and the same
CONNECTION_ID. Enabled controls repeat G writes, test native duplicate/FK/CHECK
errors where modeled, or re-prove privilege denial for R/T. L controls prove FOR
UPDATE succeeds while actual UPDATE is denied. Other request locking-read paths
are also exercised under disabled settings. State/status controls retain valid
values; matched-row UPDATE is measured with PDO FOUND_ROWS. Successful control
writes are rolled back. Independent baseline hashes must remain unchanged.

A proof-specific named lock serializes runs. The schema's modeled tables must be
empty before seeding; occupied data is refused rather than erased. Owner seed
inserts commit once; every attack and control has explicit transactional handling.
Finally rolls back active transactions, restores both runtime flags to 1/1 and
verifies them; a restoration/rollback failure discards the runtime connection and
blocks. Cleanup removes only data in this exclusive matrix fixture, child-first,
and verifies emptiness. Fixtures/guards remain installed for repeat runs; no
schema DROP or server configuration change is issued.

Stdout is JSON Lines. Every record includes table, operation, runtime_needed,
mutation_needed (distinct for lock-only UPDATE), classification, attack_tested,
checks_state, expected_protection and observed_result. Attack rows also record
independent baseline equality after COMMIT. The deterministic summary reports:

| Counter | Expected model total |
|---|---:|
| total_write_paths | 90 |
| privilege_denied_paths | 47 |
| guard_protected_paths | 43 |
| independently_protected_paths | 0 |
| trusted_routine_later_paths | 3 (subset of privilege-denied) |
| lock_only_update_paths | 2 (subset of guard-protected) |
| unresolved_paths | 0 only after every required path completes successfully |

A failed diagnostic, unexpected write or incomplete path is unresolved. Any
unresolved path or cleanup failure yields BLOCKED and exit 1. Unexpected errors
abort safely with incomplete paths counted unresolved; no partial run is reported
as a full pass. Only a completed, cleaned-up run can produce TEST_MODEL_PASS.
Neither that verdict nor the static classification totals certify production
closure. The operator has now reported the completed TEST_MODEL_PASS with the
totals above, zero unresolved paths and cleanup_failed=false. Codex has not run
the live matrix.

### Exact isolated operator setup

Use the test container's local mysql socket and interactive root password prompt:

```sh
sudo docker exec -it budget-phase23-mysql-test mysql -uroot -p
```

In that test-admin session only, create NEW dedicated schema/identities (replace
secret placeholders privately). Existing-name errors require review, not DROP:

```sql
CREATE DATABASE phase3_d3a1_matrix;
CREATE USER 'd3a1_matrix_owner'@'%' IDENTIFIED BY '<matrix-owner-secret>';
CREATE USER 'd3a1_matrix_runtime'@'%' IDENTIFIED BY '<matrix-runtime-secret>';
GRANT SELECT, INSERT, UPDATE, DELETE, TRIGGER ON phase3_d3a1_matrix.*
  TO 'd3a1_matrix_owner'@'%';
```

Keep the existing D3A1_ISOLATED_TEST acknowledgement and pinned test server UUID.
Root is used ONLY by the installer so MySQL trigger binary-log privilege policy
requires no server setting change or global privilege grant to the test owner.
In the private operator shell:

```sh
export D3A1_DSN='mysql:host=127.0.0.1;port=3306;dbname=phase3_d3a1_matrix;charset=utf8mb4'
export D3A1_OWNER_USER=root
read -rs -p 'Isolated root password: ' D3A1_OWNER_PASSWORD
export D3A1_OWNER_PASSWORD
export D3A1_RUNTIME_USER=d3a1_matrix_runtime
read -rs -p 'Matrix runtime password: ' D3A1_RUNTIME_PASSWORD
export D3A1_RUNTIME_PASSWORD
```

Run the Docker invocation below with `--install-application-matrix` substituted.
Installation refuses a nonempty schema. DDL is nontransactional: a partial
installation is BLOCKED for explicit isolated-admin review; no automatic repair
or object replacement. It loads only the JSON fixture and PDO, not Laravel, .env,
application providers, artisan or production migration execution.

Apply only the generated table-level runtime grants, forwarding the root secret
by environment NAME (not expanding it into docker arguments):

```sh
export MYSQL_PWD="$D3A1_OWNER_PASSWORD"
sudo --preserve-env=MYSQL_PWD docker exec -i --env MYSQL_PWD \
  budget-phase23-mysql-test mysql -uroot \
  < /home/mhmed216/budget-system-phase3/tests/Proofs/d3a1-application-matrix-grants.sql
unset MYSQL_PWD
export D3A1_OWNER_USER=d3a1_matrix_owner
read -rs -p 'Matrix owner password: ' D3A1_OWNER_PASSWORD
export D3A1_OWNER_PASSWORD
```

Proof command (replace its final option for installation as described above):

```sh
sudo --preserve-env=D3A1_ISOLATED_TEST,D3A1_SERVER_UUID,D3A1_OWNER_USER,D3A1_OWNER_PASSWORD,D3A1_RUNTIME_USER,D3A1_RUNTIME_PASSWORD \
  docker run --rm \
  --network container:budget-phase23-mysql-test \
  --mount type=bind,src=/home/mhmed216/budget-system-phase3,dst=/proof,readonly \
  --workdir /proof \
  --env D3A1_ISOLATED_TEST --env D3A1_SERVER_UUID \
  --env D3A1_OWNER_USER --env D3A1_OWNER_PASSWORD \
  --env D3A1_RUNTIME_USER --env D3A1_RUNTIME_PASSWORD \
  --env 'D3A1_DSN=mysql:host=127.0.0.1;port=3306;dbname=phase3_d3a1_matrix;charset=utf8mb4' \
  "$(sudo docker build -q -f /home/mhmed216/budget-system-phase3/Dockerfile /home/mhmed216/budget-system-phase3)" \
  php tests/Proofs/d3a1.php --application-matrix-proof
```

Secrets stay in environment/hidden prompts and are never printed by the harness.
The PHP container shares only the isolated test network namespace, mounts the
repository read-only, and never starts/execs/deploys the production application.
After proof, unset both D3A1 password variables. Owner authority/replay is covered
by the separate completed isolated proof below. Test guards/profile cannot approve production guard
installation, grant revocation or application behavior under a different profile.

## Owner-authority/replay harness (operator-proven TEST_MODEL_PASS)

This TEST-ONLY slice is implemented in `tests/Proofs/d3a1-owner-authority.php`,
its SQL/grants/case catalogue and dedicated Laravel fixture. Existing successful
proof modes remain independent. The operator confirmed these results on isolated
MySQL 8.4.11 in `budget-phase23-mysql-test`, schema `phase3_d3a1_authority`:

| Stage / proof | Operator-observed result |
|---|---|
| Bootstrap | PASS |
| Authority object installation | PASS |
| Restricted grants | PASS |
| Auth proof | 11/11 PASS |
| Lock proof | 4/4 PASS |
| Replay proof | 24/24 PASS |
| Full owner-authority proof | 39/39 PASS |
| unresolved_cases | 0 |
| cleanup_failed | false |
| verdict | TEST_MODEL_PASS |

These are operator-reported live isolated results, not Codex execution or
database-free self-test observations. They close the D3A1 isolated test model only.
**PRODUCTION CLOSURE NOT PROVEN.** No production deployment, provisioning,
principal/grant changes or activation occurred as part of this proof.

Resolved validation/review findings:

- Named-route lookups refresh after fixture routes are registered and named.
- Laravel reconnects use the existing role-aware OA connection factory for AUTH
  and ACCOUNTING, including read handles and session-baseline registration.
- Trusted-clock probes use fixed MySQL 8.4-compatible numeric literals
  `946684800` and `1893456000`. Cleanup restores `timestamp = DEFAULT` and the
  original time zone, attempting both actions and blocking on any cleanup failure.
- The verification-only issuer check now requires HTTP 403 with
  `issuer_rejected=true` and `reason=signing_key_inactive`, mapped to a dedicated
  proof exception. Unrelated failures and successful issuance cannot satisfy it.
  This post-review specificity fix retains the same 39-case catalogue and changes
  no production behavior; its database-free regression checks are separate from
  the operator-reported live results above.

The confirmed production architecture uses one broadly privileged DB principal
for Eloquent authentication, unencrypted database sessions, database cache,
database jobs and failed jobs. This fixture instead isolates auth/web-state from
accounting SQL. A connection-aware test Eloquent provider retains the real login
controller, cookie encryption, CSRF, session regeneration, auth/guest middleware,
login throttling, hashing/rehash and logout. It never loads application `.env`,
configuration caches, normal migrations or `Tests\TestCase`. Fresh fixture
applications share one ephemeral APP_KEY, separate from the authority secret.
All compiled views/temporary fixture paths are private under the system temp dir.

The accounting process receives only its own credentials. Protected state covers
users credentials/identity, sessions, cache, jobs and failed-job payloads. Stock
Laravel deserializes database cache/job values; leaving these writable by the
compromised accounting credential would undermine the protected-process premise.
This updates the earlier proposed matrix profile: matrix PASS did not prove
trusted serialization or authentication integrity with checks=1.

The model contains 13 tables, five procedures, one neutral constraint-state trigger,
and 43 explicit grants. Definer accounts are locked. Only deployment admin seeds
fixture keys/password hashes or performs cleanup. Accounting has SELECT(id) on
users, designated prepared-intent UPDATE, caller-marker access and EXECUTE on the
two public routines. It has no credential/session/cache/queue/private-key access
or schema-object privilege. The owner-lock routine returns only an ID, holds its
lock on the caller connection, and performs no mutation or internal COMMIT.

`oa_finalize` authenticates a 17-field canonical length-prefixed payload and
rechecks it against locked owner/account/intent facts. Intent money is exact
DECIMAL; identifiers/timestamps are canonical unsigned strings limited to PHP's
signed 64-bit positive range. The issuer takes owner exclusively from protected
authentication; command UUID/intent selection cannot override it. A trusted
fixture adapter supplies the target accounting connection, representing the
future application's physical-connection adapter, never a public request field.
No production provider or connection routing has been changed or certified.

Tickets normally last 55 seconds; the hard maximum is 60 seconds. Clock preflight
uses two widely separated caller timestamp values and different time zones, with
bounded UTC SYSDATE readings. Unsuitable clock behavior blocks the replay proof.
The routine checks freshness again after lock waits. SQL HMAC reuses RFC 2104
HMAC-SHA-256; SQL/PHP vectors must agree. Vector agreement is not independent
cryptographic review or a constant-time guarantee.

Replay policy is the approved strict execution policy: same-transaction repeats
and committed replays are denied. Outer rollback removes claim/effect/result and
permits a still-valid authorization to retry. Rotation does not remove command
consumption. Private result retrieval is not implemented. A cached result-return
policy from earlier design notes is superseded by this strict policy.

The catalogue requires 39 cases: auth=11, owner locking=4, replay/key/clock=24.
Concurrency uses separate PHP processes, IPC and observed PFS lock waits, with
10-second monotonic coordination deadlines and no fixed sleeps. Native 1062 after
claim and after effect must remain 23000/1062; cleanup preserves caller markers
while removing CALL-local protected state. Only the command INSERT duplicate is
translated to replay rejection. Savepoint cleanup preserves other native errors,
including the existing 1213 handling pattern; this slice does not claim a newly
observed deadlock-victim result.

Finally paths restore session flags/time zones, roll back surviving transactions,
acknowledge worker shutdown and remove session/cache/job/command/effect/result
probes. The installed tables, two neutral users/facts and private fixture keys
remain for repeat runs. Missing observations, coordination errors or cleanup
failure yield BLOCKED. Submodes report their requested groups and cannot certify
unexecuted groups. Installer diagnostics show repository SQL templates and
phase/object plus native SQLSTATE/errno; bindings and driver messages are withheld.

### Exact isolated operator setup and installation

Run these commands only in the operator shell. Codex has not run Docker/MySQL.
The repository is mounted read-only and the container network is exclusively the
named isolated MySQL container. This setup creates NEW test objects/identities;
existing-name errors require review, with no automatic DROP or overwrite.

```bash
cd /home/mhmed216/budget-system-phase3
export D3A1_ISOLATED_TEST=budget-phase23-mysql-test
export D3A1_SERVER_UUID=9277202f-c26a-11f1-af5b-e6c2735e5133
export D3A1_OA_DSN='mysql:host=127.0.0.1;port=3306;dbname=phase3_d3a1_authority;charset=utf8mb4'
export D3A1_OA_INSTALLER_USER=root
read -rs -p 'Isolated MySQL root password: ' D3A1_OA_INSTALLER_PASSWORD
export D3A1_OA_INSTALLER_PASSWORD
export D3A1_OA_AUTH_USER=d3a1_oa_auth
export D3A1_OA_ACCOUNTING_USER=d3a1_oa_accounting
export D3A1_OA_OBSERVER_USER=d3a1_oa_observer
export D3A1_OA_AUTH_PASSWORD="$(php -r 'echo bin2hex(random_bytes(32));')"
export D3A1_OA_ACCOUNTING_PASSWORD="$(php -r 'echo bin2hex(random_bytes(32));')"
export D3A1_OA_OBSERVER_PASSWORD="$(php -r 'echo bin2hex(random_bytes(32));')"
export D3A1_OA_FIXTURE_PASSWORD="$(php -r 'echo bin2hex(random_bytes(32));')"
export D3A1_OA_K1_HEX="$(php -r 'echo bin2hex(random_bytes(32));')"
export D3A1_OA_K2_HEX="$(php -r 'echo bin2hex(random_bytes(32));')"
OA_IMAGE="$(sudo docker build -q -f Dockerfile .)"
OA_ENV_NAMES=(D3A1_ISOLATED_TEST D3A1_SERVER_UUID D3A1_OA_DSN
  D3A1_OA_INSTALLER_USER D3A1_OA_INSTALLER_PASSWORD
  D3A1_OA_AUTH_USER D3A1_OA_AUTH_PASSWORD
  D3A1_OA_ACCOUNTING_USER D3A1_OA_ACCOUNTING_PASSWORD
  D3A1_OA_OBSERVER_USER D3A1_OA_OBSERVER_PASSWORD
  D3A1_OA_FIXTURE_PASSWORD D3A1_OA_K1_HEX D3A1_OA_K2_HEX)
oa_run() {
  local name
  local forwarded=()
  for name in "${OA_ENV_NAMES[@]}"; do forwarded+=(--env "$name"); done
  sudo --preserve-env="$(IFS=,; printf '%s' "${OA_ENV_NAMES[*]}")" docker run --rm -i \
    --network container:budget-phase23-mysql-test \
    --mount type=bind,src=/home/mhmed216/budget-system-phase3,dst=/proof,readonly \
    --workdir /proof "${forwarded[@]}" "$OA_IMAGE" "$@"
}
```

Provisioning, object installation, and restricted grants remain three explicit stages.
`D3A1_OA_DSN` stays schema-specific for installation and every proof mode. Only
`--bootstrap-owner-authority` opens an installer SERVER connection without a selected
database. Both scopes use the shared endpoint, PDO options, acknowledgement and
server/principal validation. A missing-schema connection error is classified as a
bootstrap prerequisite failure, with native SQLSTATE/errno but no driver message.

Stage A bootstraps only the dedicated schema and five identities. It verifies the
pinned server/version/root principal and rejects an existing authority schema or
any of the five identity names at any host before issuing DDL. Runtime passwords
must be non-empty generated secrets (at least 32 characters). MySQL requires a
password literal in CREATE USER: a dedicated helper uses PDO::quote() on the
validated connection and executes the statement directly, without native parameter
markers or hand-written escaping. Secret-bearing SQL never enters the normal SQL
diagnostic helper; quoting/execution failures expose only a fixed redacted message,
without retaining the original exception. Passwords are never printed or passed
as shell arguments. No schema objects or grants are
installed. DDL is not transactional: a failure can leave partial state, and reruns
refuse it for manual review without adopting, dropping, or resetting anything.

```bash
oa_run php tests/Proofs/d3a1.php --bootstrap-owner-authority
```

Expected bootstrap message:
`READY: new isolated authority schema/identities only; install objects next; no grants or live proof`.

Stage B requires completed provisioning and installs the SQL objects only. It
remains schema-bound and never automatically invokes bootstrap:

```bash
oa_run php tests/Proofs/d3a1.php --install-owner-authority
```

Expected installer message:
`READY: isolated owner-authority schema installed; apply dedicated grants; no live proof`.
Stage C applies the exact checked restricted grant catalogue afterward:

```bash
oa_run php <<'PHP'
<?php
require 'tests/Proofs/d3a1-owner-authority.php';
try {
    oaSelfTest();
    $p = oaConnect('INSTALLER');
    foreach (oaGrantCatalogue() as $grant) {
        oaSql($p, $grant, [], 'GRANT');
    }
    echo "READY: isolated authority grants applied\n";
} catch (OaFailure $e) {
    fwrite(STDERR, 'BLOCKED: '.$e->getMessage()."\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "BLOCKED: isolated grant setup failed; details withheld\n");
    exit(1);
}
PHP

oa_run php tests/Proofs/d3a1.php --owner-authority-proof
```

For diagnostics, substitute exactly one of `--owner-authority-auth-proof`,
`--owner-authority-lock-proof`, `--owner-authority-replay-proof`. These submodes
are separate runs and do not depend on an earlier successful group. A full
TEST_MODEL_PASS requires all 39 observations, unresolved_cases=0 and
cleanup_failed=false; the scope remains isolated and production closure NOT PROVEN.
Keep generated test credentials in the private operator environment for reruns;
unset secret environment variables when finished. No production rollout follows
a model pass automatically.

## Next phase: D3a.2 production provisioning design/rehearsal

Prepare and rehearse the production provisioning design, including principal/grant
separation, key lifecycle, full-schema compatibility, clean-data checks and recovery
procedures. This is design/rehearsal/preparation only, not production deployment
or permission to change production. D3a.2, D3b and D4 are not complete.

## Primary references

- [MySQL encryption functions](https://dev.mysql.com/doc/refman/8.4/en/encryption-functions.html)
- [RFC 2104 HMAC](https://www.rfc-editor.org/rfc/rfc2104)
- [RFC 4231 test vectors](https://www.rfc-editor.org/rfc/rfc4231)
- [MySQL savepoints](https://dev.mysql.com/doc/refman/8.4/en/savepoint.html)
- [InnoDB error handling](https://dev.mysql.com/doc/refman/8.4/en/innodb-error-handling.html)
- [Transaction instrumentation](https://dev.mysql.com/doc/refman/8.4/en/performance-schema-events-transactions-current-table.html)
- [Foreign-key constraints and disabling checks](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html)
- [MySQL 8.4.11 variable definitions](https://raw.githubusercontent.com/mysql/mysql-server/mysql-8.4.11/sql/sys_vars.cc)
