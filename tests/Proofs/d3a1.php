<?php

// Standalone, opt-in research harness. Never boot Laravel or read .env.
// No application tables, migrations, users or grants are changed here.
declare(strict_types=1);

function demand(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function payload(array $fields): string
{
    $encoded = '';
    foreach ($fields as $field) {
        $encoded .= pack('N', strlen($field)).$field;
    }

    return $encoded;
}

function vectors(): array
{
    // RFC 4231 cases 1, 2, 3 and 6 (including a key longer than one hash block).
    return [
        [str_repeat("\x0b", 20), 'Hi There', 'b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7'],
        ['Jefe', 'what do ya want for nothing?', '5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843'],
        [str_repeat("\xaa", 20), str_repeat("\xdd", 50), '773ea91e36800e46854db8ebd09181a72959098b3ef8c122d9635514ced565fe'],
        [str_repeat("\xaa", 131), 'Test Using Larger Than Block-Size Key - Hash Key First', '60e431591ee0b67f0d8a26aacbf5b77f8e0bc6213728c5140546040f0ee37f54'],
    ];
}

function connect(string $role): PDO
{
    demand(getenv('D3A1_ISOLATED_TEST') === 'budget-phase23-mysql-test', 'Isolated container acknowledgement required');
    $dsn = getenv('D3A1_DSN') ?: '';
    demand((bool) preg_match('/^mysql:host=[a-zA-Z0-9_.-]+;port=([0-9]+);dbname=phase3_d3a1_proof;charset=utf8mb4$/D', $dsn, $match), 'Use the documented fixed proof-schema DSN');
    demand($match[1] !== '3307', 'Production port is forbidden');
    $pdo = new PDO($dsn, getenv('D3A1_'.$role.'_USER') ?: '', getenv('D3A1_'.$role.'_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    demand(str_starts_with((string) $pdo->query('SELECT VERSION()')->fetchColumn(), '8.4.11'), 'Proof requires MySQL 8.4.11');
    $uuid = getenv('D3A1_SERVER_UUID') ?: '';
    demand($uuid !== '' && $pdo->query('SELECT @@server_uuid')->fetchColumn() === $uuid, 'Isolated server UUID does not match');

    return $pdo;
}

function call(PDO $pdo, string $name, array $parameters = []): array
{
    demand(in_array($name, ['proof_hmac', 'proof_verify', 'proof_failure', 'proof_transaction'], true), 'Unknown proof procedure');
    $statement = $pdo->prepare('CALL '.$name.'('.implode(',', array_fill(0, count($parameters), '?')).')');
    $statement->execute($parameters);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    while ($statement->nextRowset()) {
        // Drain CALL results before reusing the connection.
    }
    $statement->closeCursor();

    return $rows;
}

function rejected(callable $operation, array $codes): PDOException
{
    try {
        $operation();
    } catch (PDOException $error) {
        demand(in_array((int) $error->errorInfo[1], $codes, true), 'Unexpected driver error '.($error->errorInfo[1] ?? 'unknown'));

        return $error;
    }
    throw new RuntimeException('Expected database rejection did not occur');
}

function deadlockEvent(array $event): void
{
    demand(fwrite(STDOUT, json_encode($event, JSON_THROW_ON_ERROR)."\n") !== false, 'Worker pipe write failed');
    fflush(STDOUT);
}

function deadlockNow(): float
{
    return hrtime(true) / 1000000000;
}

function deadlockWorker(): void
{
    deadlockEnvironment();
    $role = getenv('D3A1_DEADLOCK_ROLE');
    demand(in_array($role, ['O', 'R'], true), 'Invalid deadlock worker role');
    $pdo = connect($role === 'O' ? 'OWNER' : 'RUNTIME');
    try {
        deadlockEvent(['event' => 'ready', 'id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()]);
        while (true) {
            $read = [STDIN];
            $write = $except = [];
            demand(stream_select($read, $write, $except, 30) === 1, 'Worker command deadline expired');
            $line = fgets(STDIN);
            demand($line !== false, 'Controller disconnected');
            $command = trim($line);
            if ($command === 'finish') {
                break;
            }
            if ($command === 'setup') {
                $pdo->beginTransaction();
                if ($role === 'O') {
                    $pdo->exec('UPDATE lock_rows SET value = value + 1 WHERE id = 2');
                    // More changed rows favor O surviving under documented InnoDB
                    // transaction-size heuristics. Victim choice is still observed.
                    $pdo->exec('INSERT INTO caller_writes VALUES '.implode(',', array_map(fn ($id) => '('.$id.')', range(810000, 810063))));
                } else {
                    $pdo->exec('INSERT INTO caller_writes VALUES (800)');
                    $pdo->exec('UPDATE lock_rows SET value = value + 1 WHERE id = 1');
                }
                deadlockEvent(['event' => 'setup']);

                continue;
            }
            demand(in_array($command, ['lock1', 'call', 'commit', 'rollback'], true), 'Unknown worker command');
            try {
                if ($command === 'lock1') {
                    demand($role === 'O', 'Only O may request row 1');
                    $pdo->exec('UPDATE lock_rows SET value = value + 1 WHERE id = 1');
                } elseif ($command === 'call') {
                    demand($role === 'R', 'Only R may issue the CALL');
                    call($pdo, 'proof_failure', ['timeout', 800]);
                } else {
                    $pdo->exec($command === 'commit' ? 'COMMIT' : 'ROLLBACK');
                }
                deadlockEvent(['event' => $command, 'ok' => true]);
            } catch (PDOException $error) {
                $errno = (int) ($error->errorInfo[1] ?? 0);
                deadlockEvent([
                    'event' => $command, 'ok' => false,
                    'sqlstate' => (string) ($error->errorInfo[0] ?? $error->getCode()),
                    'errno' => $errno,
                    // Preserve native deadlock diagnostics, never connection errors
                    // that might disclose configuration or credentials.
                    'native_message' => $errno === 1213 ? ($error->errorInfo[2] ?? '') : '',
                ]);
            }
        }
    } finally {
        // Unconditional SQL handles an engine-ended transaction as well as a
        // survivor. EOF, protocol failure and setup errors take this same path.
        $pdo->exec('ROLLBACK');
    }
    deadlockEvent(['event' => 'closed']);
}

function deadlockStart(string $role): array
{
    $environment = getenv();
    $environment['D3A1_DEADLOCK_ROLE'] = $role;
    // Array argv bypasses the shell. Passwords exist only in inherited environment.
    $process = proc_open([PHP_BINARY, __FILE__, '--deadlock-worker'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a'],
    ], $pipes, __DIR__, $environment);
    demand(is_resource($process), 'Cannot start isolated PHP worker');
    stream_set_blocking($pipes[1], false);

    return ['process' => $process, 'input' => $pipes[0], 'output' => $pipes[1], 'buffer' => '', 'id' => null, 'closed' => false];
}

function deadlockSend(array $worker, string $command): void
{
    demand(fwrite($worker['input'], $command."\n") === strlen($command) + 1, 'Worker command write failed');
    fflush($worker['input']);
}

function deadlockRead(array &$worker, float $deadline): array
{
    while (true) {
        $newline = strpos($worker['buffer'], "\n");
        if ($newline !== false) {
            $line = substr($worker['buffer'], 0, $newline);
            $worker['buffer'] = substr($worker['buffer'], $newline + 1);
            $event = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            demand(is_array($event) && isset($event['event']), 'Malformed worker response');

            return $event;
        }
        $remaining = $deadline - deadlockNow();
        demand($remaining > 0, 'Worker response deadline expired');
        $read = [$worker['output']];
        $write = $except = [];
        $seconds = (int) $remaining;
        demand(stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1000000)) === 1, 'Worker response deadline expired');
        $chunk = fread($worker['output'], 8192);
        demand($chunk !== false && $chunk !== '', 'Worker exited without a complete response');
        $worker['buffer'] .= $chunk;
        demand(strlen($worker['buffer']) < 32768, 'Oversized worker response');
    }
}

function deadlockExpect(array &$worker, string $event): array
{
    $result = deadlockRead($worker, deadlockNow() + 15);
    demand($result['event'] === $event, 'Unexpected worker response');

    return $result;
}

function deadlockStop(array &$worker): void
{
    // Give an idle survivor a finally/ROLLBACK path first. A worker still blocked
    // in PDO is terminated only after this bounded graceful-cleanup opportunity.
    if (! $worker['closed']) {
        @fwrite($worker['input'], "finish\n");
    }
    fclose($worker['input']);
    $status = proc_get_status($worker['process']);
    $deadline = deadlockNow() + ($worker['closed'] ? 5 : 1);
    while ($status['running'] && deadlockNow() < $deadline) {
        // Pipe readiness/EOF is an event, not a sleep used to establish locks.
        $read = [$worker['output']];
        $write = $except = [];
        stream_select($read, $write, $except, 0, 10000);
        stream_get_contents($worker['output']);
        $status = proc_get_status($worker['process']);
    }
    if ($status['running']) {
        demand(proc_terminate($worker['process'], 9), 'Cannot terminate blocked proof worker');
        $deadline = deadlockNow() + 5;
        do {
            $status = proc_get_status($worker['process']);
            demand(deadlockNow() < $deadline, 'Proof worker did not exit within cleanup deadline');
        } while ($status['running']);
    }
    fclose($worker['output']);
    proc_close($worker['process']);
    demand($status['exitcode'] === 0, 'Proof worker exited abnormally; connection closed for rollback');
}

function deadlockEnvironment(): void
{
    // The dedicated Docker invocation shares ONLY the isolated test container's
    // network namespace; the UUID is checked independently by every connection.
    demand(getenv('D3A1_DSN') === 'mysql:host=127.0.0.1;port=3306;dbname=phase3_d3a1_proof;charset=utf8mb4', 'Deadlock mode requires isolated-container loopback DSN');
    demand(getenv('D3A1_OWNER_USER') === 'd3a1_proof_owner' && getenv('D3A1_RUNTIME_USER') === 'd3a1_proof_runtime', 'Deadlock mode requires fixed proof identities');
}

function deadlockVictim(array $results): string
{
    demand(array_keys($results) === ['R', 'O'], 'Both identified worker outcomes required');
    $victims = array_keys(array_filter($results, fn ($result) => ($result['ok'] ?? null) === false && ($result['errno'] ?? null) === 1213 && ($result['sqlstate'] ?? null) === '40001'));
    demand(count($victims) === 1, 'Exactly one native 1213/40001 victim required');
    $victim = $victims[0];
    $survivor = $victim === 'R' ? 'O' : 'R';
    demand(($results[$survivor]['ok'] ?? null) === true, 'Surviving operation did not succeed');

    return $victim;
}

function runDeadlockProof(): void
{
    demand(function_exists('proc_open') && function_exists('stream_select'), 'PHP child-process support required');
    deadlockEnvironment();
    $monitor = connect('OWNER');
    demand((int) $monitor->query('SELECT @@session.autocommit')->fetchColumn() === 1, 'Independent monitor must use autocommit');
    // Preflight metadata permissions BEFORE creating transactional probe writes.
    $monitor->query('SELECT ENGINE_LOCK_ID, ENGINE_TRANSACTION_ID, THREAD_ID, LOCK_DATA FROM performance_schema.data_locks LIMIT 0');
    $monitor->query('SELECT REQUESTING_ENGINE_LOCK_ID, BLOCKING_ENGINE_LOCK_ID FROM performance_schema.data_lock_waits LIMIT 0');
    $monitor->query('SELECT THREAD_ID, PROCESSLIST_ID, PROCESSLIST_USER FROM performance_schema.threads LIMIT 0');
    demand((int) $monitor->query("SELECT GET_LOCK('phase3_d3a1_proof.deadlock', 0)")->fetchColumn() === 1, 'Another deadlock proof owns the coordination lock');
    $workers = [];
    $reserved = false;
    $outcome = null;
    try {
        demand((int) $monitor->query('SELECT COUNT(*) FROM caller_writes WHERE id = 800 OR id BETWEEN 810000 AND 810063')->fetchColumn() === 0, 'Caller probe IDs already occupied');
        demand((int) $monitor->query('SELECT COUNT(*) FROM protected_writes WHERE id IN (800, 801)')->fetchColumn() === 0, 'Protected probe IDs already occupied');
        demand((int) $monitor->query('SELECT COUNT(*) FROM lock_rows WHERE id IN (1, 2)')->fetchColumn() === 2, 'Missing lock fixture rows');
        demand((int) $monitor->query("SELECT COUNT(*) FROM performance_schema.data_locks WHERE OBJECT_SCHEMA = 'phase3_d3a1_proof'")->fetchColumn() === 0, 'Other proof transactions hold locks');
        $reserved = true;
        foreach (['O', 'R'] as $role) {
            $workers[$role] = deadlockStart($role);
            $ready = deadlockExpect($workers[$role], 'ready');
            demand(is_int($ready['id'] ?? null) && $ready['id'] > 0, 'Missing worker connection ID');
            $workers[$role]['id'] = $ready['id'];
            $thread = $monitor->prepare('SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID = ?');
            $thread->execute([$ready['id']]);
            $workers[$role]['thread_id'] = (int) $thread->fetchColumn();
            demand($workers[$role]['thread_id'] > 0, 'Worker missing from thread metadata');
            deadlockSend($workers[$role], 'setup');
            deadlockExpect($workers[$role], 'setup');
        }
        demand($workers['O']['id'] !== $workers['R']['id'], 'Workers share a connection');
        deadlockSend($workers['O'], 'lock1');
        $edge = $monitor->prepare(<<<'SQL'
SELECT w.REQUESTING_ENGINE_TRANSACTION_ID AS o_trx,
       w.BLOCKING_ENGINE_TRANSACTION_ID AS r_trx,
       ot.PROCESSLIST_ID AS o_id, rt.PROCESSLIST_ID AS r_id,
       ot.PROCESSLIST_USER AS o_user, rt.PROCESSLIST_USER AS r_user
FROM performance_schema.data_lock_waits w
JOIN performance_schema.data_locks requested
  ON requested.ENGINE = w.ENGINE AND requested.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
JOIN performance_schema.data_locks blocking
  ON blocking.ENGINE = w.ENGINE AND blocking.ENGINE_LOCK_ID = w.BLOCKING_ENGINE_LOCK_ID
JOIN performance_schema.threads ot ON ot.THREAD_ID = w.REQUESTING_THREAD_ID
JOIN performance_schema.threads rt ON rt.THREAD_ID = w.BLOCKING_THREAD_ID
WHERE ot.PROCESSLIST_ID = ? AND rt.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = 'phase3_d3a1_proof' AND requested.OBJECT_NAME = 'lock_rows'
  AND requested.INDEX_NAME = 'PRIMARY' AND requested.LOCK_TYPE = 'RECORD'
  AND requested.LOCK_DATA = '1' AND requested.LOCK_STATUS = 'WAITING'
  AND blocking.OBJECT_SCHEMA = 'phase3_d3a1_proof' AND blocking.OBJECT_NAME = 'lock_rows'
  AND blocking.INDEX_NAME = 'PRIMARY' AND blocking.LOCK_TYPE = 'RECORD'
  AND blocking.LOCK_DATA = '1' AND blocking.LOCK_STATUS = 'GRANTED'
  AND blocking.LOCK_MODE IN ('X', 'X,REC_NOT_GAP')
  AND EXISTS (
      SELECT 1 FROM performance_schema.data_locks held
      WHERE held.ENGINE = w.ENGINE
        AND held.ENGINE_TRANSACTION_ID = w.REQUESTING_ENGINE_TRANSACTION_ID
        AND held.THREAD_ID = w.REQUESTING_THREAD_ID
        AND held.OBJECT_SCHEMA = 'phase3_d3a1_proof' AND held.OBJECT_NAME = 'lock_rows'
        AND held.INDEX_NAME = 'PRIMARY' AND held.LOCK_TYPE = 'RECORD'
        AND held.LOCK_DATA = '2' AND held.LOCK_STATUS = 'GRANTED'
        AND held.LOCK_MODE IN ('X', 'X,REC_NOT_GAP')
  )
SQL);
        $deadline = deadlockNow() + 15;
        do {
            demand(! feof($workers['O']['output']) && ! feof($workers['R']['output']), 'Worker disconnected during coordination');
            $edge->execute([$workers['O']['id'], $workers['R']['id']]);
            $rows = $edge->fetchAll(PDO::FETCH_ASSOC);
            demand(count($rows) <= 1, 'Ambiguous live lock edge');
            if ($rows !== []) {
                break;
            }
            demand(deadlockNow() < $deadline, 'Cannot establish O waiting on R with O holding row 2');
        } while (true);
        $mapping = $rows[0];
        demand($mapping['o_user'] === 'd3a1_proof_owner' && $mapping['r_user'] === 'd3a1_proof_runtime', 'Live metadata identities disagree');
        echo 'IDENTIFIED: '.json_encode($mapping, JSON_THROW_ON_ERROR)."\n";
        deadlockSend($workers['R'], 'call');
        // Both operations already run independently. Reading either result first
        // cannot prevent the other worker from completing the deadlock cycle.
        $deadline = deadlockNow() + 15;
        $results = [];
        foreach (['R' => 'call', 'O' => 'lock1'] as $role => $event) {
            $results[$role] = deadlockRead($workers[$role], $deadline);
            demand($results[$role]['event'] === $event, 'Unexpected deadlock operation response');
            echo $role.' connection='.$workers[$role]['id'].' '.json_encode($results[$role], JSON_THROW_ON_ERROR)."\n";
        }
        $victim = deadlockVictim($results);
        echo 'VICTIM: '.$victim.' connection='.$workers[$victim]['id']." native 1213/40001\n";
        deadlockSend($workers['R'], $victim === 'R' ? 'commit' : 'rollback');
        $cleanup = deadlockExpect($workers['R'], $victim === 'R' ? 'commit' : 'rollback');
        echo 'R post-victim '.json_encode($cleanup, JSON_THROW_ON_ERROR)."\n";
        demand(($cleanup['ok'] ?? null) === true, 'R COMMIT attempt / ROLLBACK failed');
        // Monitor has never opened a transaction: these are independent fresh
        // committed reads, including AFTER R's explicit COMMIT attempt.
        $caller = (int) $monitor->query('SELECT COUNT(*) FROM caller_writes WHERE id = 800')->fetchColumn();
        $protected = (int) $monitor->query('SELECT COUNT(*) FROM protected_writes WHERE id IN (800, 801)')->fetchColumn();
        echo 'VERIFIED: caller_800='.$caller.' protected_800_801='.$protected."\n";
        demand($caller === 0 && $protected === 0, 'Probe writes survived transaction cleanup');
        foreach (['O', 'R'] as $role) {
            deadlockSend($workers[$role], 'finish');
            deadlockExpect($workers[$role], 'closed');
            $workers[$role]['closed'] = true;
        }
        $outcome = $victim === 'R'
            ? 'PASS: R victim; native 1213/40001 preserved; COMMIT cannot retain caller or protected writes'
            : 'INCONCLUSIVE/SKIP: O victim; CALL succeeded; R explicitly rolled back; R-victim cleanup NOT PROVEN';
    } finally {
        $cleanupErrors = [];
        foreach ($workers as &$worker) {
            try {
                deadlockStop($worker);
            } catch (Throwable $error) {
                $cleanupErrors[] = 'Worker cleanup failed';
            }
        }
        unset($worker);
        try {
            // Reaping a child closes its connection. Observe lock release before
            // DELETE so failure cleanup never waits on a surviving worker lock.
            $ids = array_column($workers, 'thread_id');
            $ids = array_filter($ids, fn ($id) => is_int($id) && $id > 0);
            if ($ids !== []) {
                // Cached THREAD_IDs keep this check effective even after the
                // threads rows disappear during disconnected-session rollback.
                $released = $monitor->prepare('SELECT COUNT(*) FROM performance_schema.data_locks WHERE THREAD_ID IN ('.implode(',', array_fill(0, count($ids), '?')).')');
                $deadline = deadlockNow() + 15;
                do {
                    $released->execute(array_values($ids));
                    if ((int) $released->fetchColumn() === 0) {
                        break;
                    }
                    demand(deadlockNow() < $deadline, 'Proof worker locks remain after cleanup');
                } while (true);
            }
            if ($reserved) {
                $monitor->exec('DELETE FROM caller_writes WHERE id = 800 OR id BETWEEN 810000 AND 810063');
                $monitor->exec('DELETE FROM protected_writes WHERE id IN (800, 801)');
            }
        } finally {
            $monitor->query("SELECT RELEASE_LOCK('phase3_d3a1_proof.deadlock')");
        }
        demand($cleanupErrors === [], 'Worker cleanup failed; deadlock proof blocked');
    }
    echo $outcome."\n";
}

function constraintGuardEnvironment(): void
{
    demand(getenv('D3A1_DSN') === 'mysql:host=127.0.0.1;port=3306;dbname=phase3_d3a1_proof;charset=utf8mb4', 'Guard mode requires isolated-container loopback DSN');
    demand(getenv('D3A1_OWNER_USER') === 'd3a1_proof_owner' && getenv('D3A1_RUNTIME_USER') === 'd3a1_proof_runtime', 'Guard mode requires fixed proof identities');
}

function installConstraintGuard(): void
{
    constraintGuardEnvironment();
    $owner = connect('OWNER');
    demand((int) $owner->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'phase3_d3a1_proof' AND TABLE_NAME IN ('d3a1_guard_parents', 'd3a1_guard_children')")->fetchColumn() === 0, 'Guard fixture tables already exist; do not overwrite');
    demand((int) $owner->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'phase3_d3a1_proof' AND TRIGGER_NAME LIKE 'd3a1_guard_%'")->fetchColumn() === 0, 'Guard triggers already exist; do not overwrite');
    $fixture = file_get_contents(__DIR__.'/d3a1.sql');
    foreach (array_slice(explode('-- D3A1 GUARD STATEMENT', $fixture), 1) as $sql) {
        $owner->exec(trim($sql));
    }
    echo "PASS: neutral constraint-guard fixture installed; test admin must grant runtime DML on these two tables (no live guard proof)\n";
}

function constraintChecks(PDO $pdo): array
{
    $row = $pdo->query('SELECT @@session.foreign_key_checks AS fk, @@session.unique_checks AS uq')->fetch(PDO::FETCH_ASSOC);

    return [(int) $row['fk'], (int) $row['uq']];
}

function restoreConstraintChecks(?PDO &$pdo): void
{
    $failed = false;
    // Attempt BOTH restorations even if the first one fails. Never restore a
    // previously weakened state: this proof always returns both flags to one.
    foreach (['foreign_key_checks', 'unique_checks'] as $variable) {
        try {
            $pdo->exec('SET SESSION '.$variable.' = 1');
        } catch (Throwable $error) {
            $failed = true;
        }
    }
    try {
        $failed = $failed || constraintChecks($pdo) !== [1, 1];
    } catch (Throwable $error) {
        $failed = true;
    }
    if ($failed) {
        $pdo = null; // Discard a connection whose safe session state is unknown.
        throw new RuntimeException('Guard proof cannot restore both session checks; runtime connection discarded');
    }
}

function constraintGuardDiagnostic(array $info): bool
{
    return ($info[0] ?? null) === '45003'
        && (int) ($info[1] ?? 0) === 60031
        && ($info[2] ?? null) === 'D3A1 constraint-state guard: checks must be enabled';
}

function constraintValidWrites(PDO $runtime): void
{
    foreach ([
        'INSERT INTO d3a1_guard_children VALUES (920002, 910002, 1)',
        'UPDATE d3a1_guard_children SET parent_id = 910002 WHERE id = 920001',
        'UPDATE d3a1_guard_parents SET value = 2 WHERE id = 910003',
        'DELETE FROM d3a1_guard_parents WHERE id = 910003',
    ] as $sql) {
        demand($runtime->exec($sql) === 1, 'Valid guarded write did not affect exactly one row');
    }
}

function constraintGuardSnapshot(PDO $owner): array
{
    return [
        $owner->query('SELECT id, value FROM d3a1_guard_parents WHERE id BETWEEN 910001 AND 910009 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $owner->query('SELECT id, parent_id, value FROM d3a1_guard_children WHERE id BETWEEN 920001 AND 920002 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function runConstraintGuardProof(): void
{
    constraintGuardEnvironment();
    $owner = connect('OWNER');
    $runtime = connect('RUNTIME');
    $reserved = false;
    $locked = false;
    try {
        restoreConstraintChecks($runtime);
        demand(constraintChecks($owner) === [1, 1], 'Owner cleanup connection must have both checks enabled');
        demand((int) $owner->query('SELECT @@session.autocommit')->fetchColumn() === 1 && (int) $runtime->query('SELECT @@session.autocommit')->fetchColumn() === 1, 'Guard proof requires autocommit outside explicit probes');
        $connection = (int) $runtime->query('SELECT CONNECTION_ID()')->fetchColumn();
        $definer = (string) $owner->query('SELECT CURRENT_USER()')->fetchColumn();
        $triggers = $owner->query("SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'phase3_d3a1_proof' AND EVENT_OBJECT_TABLE IN ('d3a1_guard_parents', 'd3a1_guard_children') ORDER BY TRIGGER_NAME")->fetchAll(PDO::FETCH_ASSOC);
        $expected = [
            ['d3a1_guard_child_insert', 'INSERT', 'd3a1_guard_children'],
            ['d3a1_guard_child_update', 'UPDATE', 'd3a1_guard_children'],
            ['d3a1_guard_parent_delete', 'DELETE', 'd3a1_guard_parents'],
            ['d3a1_guard_parent_update', 'UPDATE', 'd3a1_guard_parents'],
        ];
        demand(count($triggers) === 4, 'Install the separate four-trigger guard fixture first');
        foreach ($triggers as $index => $trigger) {
            demand([$trigger['TRIGGER_NAME'], $trigger['EVENT_MANIPULATION'], $trigger['EVENT_OBJECT_TABLE']] === $expected[$index] && $trigger['ACTION_TIMING'] === 'BEFORE' && $trigger['DEFINER'] === $definer, 'Guard trigger shape/owner differs from the proof fixture');
        }
        demand((int) $owner->query("SELECT GET_LOCK('phase3_d3a1_proof.constraint_guard', 0)")->fetchColumn() === 1, 'Another guard proof is running');
        $locked = true;
        demand(constraintGuardSnapshot($owner) === [[], []], 'Guard probe IDs already occupied; do not overwrite');
        $reserved = true;
        $owner->beginTransaction();
        $owner->exec('INSERT INTO d3a1_guard_parents VALUES (910001, 1), (910002, 1), (910003, 1)');
        $owner->exec('INSERT INTO d3a1_guard_children VALUES (920001, 910001, 1)');
        $owner->commit();
        $baseline = constraintGuardSnapshot($owner);

        $runtime->beginTransaction();
        // Ordinary FK, unique and CHECK errors must remain distinguishable from
        // the guard when checks are enabled. All target rows exist where needed.
        foreach ([
            ['INSERT INTO d3a1_guard_children VALUES (920002, -1, 1)', 1452],
            ['UPDATE d3a1_guard_children SET parent_id = -1 WHERE id = 920001', 1452],
            ['UPDATE d3a1_guard_parents SET id = 910009 WHERE id = 910001', 1451],
            ['DELETE FROM d3a1_guard_parents WHERE id = 910001', 1451],
            ['INSERT INTO d3a1_guard_children VALUES (920001, 910001, 1)', 1062],
            ['INSERT INTO d3a1_guard_children VALUES (920002, 910001, 0)', 3819],
        ] as [$sql, $errno]) {
            rejected(fn () => $runtime->exec($sql), [$errno]);
        }
        constraintValidWrites($runtime);
        $runtime->rollBack();
        demand(constraintGuardSnapshot($owner) === $baseline, 'Enabled-check probes changed the fixture');
        echo "PASS: checks=1/1; four valid write classes succeed; ordinary FK/unique/CHECK errors remain native\n";

        foreach ([[0, 1], [1, 0], [0, 0]] as [$fk, $unique]) {
            try {
                $runtime->exec('SET SESSION foreign_key_checks = '.$fk);
                $runtime->exec('SET SESSION unique_checks = '.$unique);
                demand(constraintChecks($runtime) === [$fk, $unique], 'Runtime could not set requested session flags');
                $runtime->beginTransaction();
                // Each disabled state covers four otherwise-valid writes AND
                // four FK-inconsistent writes: errno alone is insufficient.
                foreach ([
                    'INSERT INTO d3a1_guard_children VALUES (920002, 910002, 1)',
                    'UPDATE d3a1_guard_children SET parent_id = 910002 WHERE id = 920001',
                    'UPDATE d3a1_guard_parents SET value = 2 WHERE id = 910003',
                    'DELETE FROM d3a1_guard_parents WHERE id = 910003',
                    'INSERT INTO d3a1_guard_children VALUES (920002, -1, 1)',
                    'UPDATE d3a1_guard_children SET parent_id = -1 WHERE id = 920001',
                    'UPDATE d3a1_guard_parents SET id = 910009 WHERE id = 910001',
                    'DELETE FROM d3a1_guard_parents WHERE id = 910001',
                ] as $sql) {
                    $error = rejected(fn () => $runtime->exec($sql), [60031]);
                    demand(constraintGuardDiagnostic($error->errorInfo), 'Rejection is not the exact proof guard diagnostic');
                }
                // Malicious catch-and-COMMIT: independent reads must still see
                // unchanged baseline rows, including no orphan or removed parent.
                $runtime->exec('COMMIT');
                demand(constraintGuardSnapshot($owner) === $baseline, 'Disabled-check writes survived COMMIT');
                echo 'PASS: flags='.$fk.'/'.$unique." writable; all eight writes denied by guard 45003/60031; COMMIT leaves baseline unchanged\n";
            } finally {
                try {
                    $runtime->exec('ROLLBACK');
                } finally {
                    restoreConstraintChecks($runtime);
                }
            }
            demand((int) $runtime->query('SELECT CONNECTION_ID()')->fetchColumn() === $connection, 'Runtime connection changed during reuse proof');
            $runtime->beginTransaction();
            constraintValidWrites($runtime);
            $runtime->rollBack();
            demand(constraintGuardSnapshot($owner) === $baseline, 'Reuse probes changed the fixture');
            echo 'PASS: same runtime connection='.$connection." restored to 1/1; all four valid write classes succeed again\n";
        }
    } finally {
        $cleanupFailed = false;
        if ($runtime !== null) {
            try {
                try {
                    $runtime->exec('ROLLBACK');
                } finally {
                    restoreConstraintChecks($runtime);
                }
            } catch (Throwable $error) {
                $runtime = null;
                $cleanupFailed = true;
            }
        }
        try {
            $owner->exec('ROLLBACK');
            if ($reserved) {
                $owner->exec('DELETE FROM d3a1_guard_children WHERE id BETWEEN 920001 AND 920002');
                $owner->exec('DELETE FROM d3a1_guard_parents WHERE id BETWEEN 910001 AND 910009');
                demand(constraintGuardSnapshot($owner) === [[], []], 'Guard probe rows remain after cleanup');
            }
        } finally {
            if ($locked) {
                $owner->query("SELECT RELEASE_LOCK('phase3_d3a1_proof.constraint_guard')");
            }
        }
        demand(! $cleanupFailed, 'Runtime rollback/check restoration failed; connection discarded; guard proof BLOCKED');
    }
    echo "PASS: constraint-state guard primitive; runtime flags restored to 1/1; probe rows removed (application matrix NOT PROVEN)\n";
}

function runProof(): void
{
    foreach (['--bootstrap-owner-authority', '--install-owner-authority', '--owner-authority-proof', '--owner-authority-auth-proof', '--owner-authority-replay-proof', '--owner-authority-lock-proof'] as $mode) {
        if (in_array($mode, $_SERVER['argv'], true)) {
            require_once __DIR__.'/d3a1-owner-authority.php';
            oaDemand(count($_SERVER['argv']) === 2, 'OA exactly one CLI mode required');
            oaMain($mode);

            return;
        }
    }
    foreach (vectors() as [$key, $message, $expected]) {
        demand(hash_hmac('sha256', $message, $key) === $expected, 'Local RFC vector failed');
    }
    demand(payload(['ab', 'c']) !== payload(['a', 'bc']), 'Encoding is ambiguous');
    if (in_array('--self-test', $_SERVER['argv'], true)) {
        require_once __DIR__.'/d3a1-owner-authority.php';
        oaSelfTest();
        require_once __DIR__.'/d3a1-application-matrix.php';
        $matrix = applicationMatrixManifest();
        applicationMatrixValidate($matrix);
        applicationMatrixInstallerSelfTest($matrix);
        applicationMatrixRuntimeSelfTest($matrix);
        $totals = applicationMatrixTotals($matrix);
        demand($totals['total_write_paths'] === count($matrix['tables']) * 3, 'Matrix inventory has missing operations');
        demand($totals['total_write_paths'] === $totals['privilege_denied_paths'] + $totals['guard_protected_paths'], 'Matrix paths are not fully classified');
        foreach ($matrix['tables'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
                demand(applicationMatrixAttacks($table, $operation) !== [], 'Matrix operation has no attack');
            }
        }
        echo 'STATIC MATRIX INVENTORY: '.json_encode($totals, JSON_THROW_ON_ERROR)." (no database observations)\n";
        $success = ['ok' => true];
        $deadlock = ['ok' => false, 'errno' => 1213, 'sqlstate' => '40001'];
        demand(deadlockVictim(['R' => $deadlock, 'O' => $success]) === 'R', 'R victim classification failed');
        demand(deadlockVictim(['R' => $success, 'O' => $deadlock]) === 'O', 'O victim must remain inconclusive');
        foreach ([
            ['R' => $success, 'O' => $success],
            ['R' => $deadlock, 'O' => $deadlock],
            ['R' => ['ok' => false, 'errno' => 1213, 'sqlstate' => '45000'], 'O' => $success],
            ['R' => ['ok' => false, 'errno' => 1205, 'sqlstate' => 'HY000'], 'O' => $success],
            ['R' => $deadlock, 'O' => ['ok' => false, 'errno' => 1062, 'sqlstate' => '23000']],
            ['R' => $deadlock],
        ] as $invalid) {
            $blocked = false;
            try {
                deadlockVictim($invalid);
            } catch (RuntimeException $error) {
                $blocked = true;
            }
            demand($blocked, 'Ambiguous/unexpected victim result must fail closed');
        }
        $guard = ['45003', 60031, 'D3A1 constraint-state guard: checks must be enabled'];
        demand(constraintGuardDiagnostic($guard), 'Exact guard diagnostic was not recognized');
        foreach ([
            ['45000', 60031, $guard[2]],
            ['45003', 1452, $guard[2]],
            ['45003', 60031, 'Other trigger'],
            ['23000', 1062, 'Duplicate entry'],
            ['23000', 1452, 'Foreign key failure'],
            [],
        ] as $ordinary) {
            demand(! constraintGuardDiagnostic($ordinary), 'Ordinary or unrelated error was mistaken for the proof guard');
        }
        echo "PASS: four PHP RFC vectors, length-prefix encoding, deadlock classification and exact guard diagnostics (no MySQL proof)\n";

        return;
    }

    if (in_array('--deadlock-worker', $_SERVER['argv'], true)) {
        deadlockWorker();

        return;
    }
    if (in_array('--deadlock-proof', $_SERVER['argv'], true)) {
        runDeadlockProof();

        return;
    }
    if (in_array('--install-constraint-guard', $_SERVER['argv'], true)) {
        installConstraintGuard();

        return;
    }
    if (in_array('--constraint-guard-proof', $_SERVER['argv'], true)) {
        runConstraintGuardProof();

        return;
    }
    if (in_array('--install-application-matrix', $_SERVER['argv'], true) || in_array('--application-matrix-proof', $_SERVER['argv'], true)) {
        require_once __DIR__.'/d3a1-application-matrix.php';
        if (in_array('--install-application-matrix', $_SERVER['argv'], true)) {
            installApplicationMatrix();
        } else {
            runApplicationMatrixProof();
        }

        return;
    }

    $owner = connect('OWNER');
    if (in_array('--install', $_SERVER['argv'], true)) {
        demand((int) $owner->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'phase3_d3a1_proof'")->fetchColumn() === 0, 'Proof schema must be empty');
        demand((int) $owner->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = 'phase3_d3a1_proof'")->fetchColumn() === 0, 'Proof routines already exist');
        $fixture = explode('-- D3A1 GUARD STATEMENT', file_get_contents(__DIR__.'/d3a1.sql'), 2)[0];
        foreach (array_slice(explode('-- D3A1 STATEMENT', $fixture), 1) as $sql) {
            $owner->exec(trim($sql));
        }
        echo "PASS: test-only fixture installed; operator must grant individual procedure EXECUTE before running proofs\n";

        return;
    }

    $runtime = connect('RUNTIME');
    demand($owner->query('SELECT CURRENT_USER()')->fetchColumn() !== $runtime->query('SELECT CURRENT_USER()')->fetchColumn(), 'Two different identities required');

    // Tests are repeatable; only proof-owned data is reset. Key never printed.
    foreach (['caller_writes', 'protected_writes', 'child_rows', 'authority_key'] as $table) {
        $owner->exec('DELETE FROM '.$table);
    }
    $secret = random_bytes(32);
    $owner->prepare('INSERT INTO authority_key VALUES (1, ?)')->execute([$secret]);
    foreach (vectors() as [$key, $message, $expected]) {
        demand(bin2hex(call($owner, 'proof_hmac', [$key, $message])[0]['tag']) === $expected, 'MySQL RFC vector failed');
    }
    rejected(fn () => $runtime->query('SELECT secret FROM authority_key'), [1142]);
    rejected(fn () => $runtime->exec('INSERT INTO authority_key VALUES (2, 0x01)'), [1142]);
    rejected(fn () => $runtime->exec('INSERT INTO protected_writes VALUES (999, 1)'), [1142]);
    rejected(fn () => call($runtime, 'proof_hmac', ['x', 'y']), [1370]);
    rejected(fn () => $runtime->exec('CREATE TABLE runtime_ddl_probe (id INT)'), [1044, 1142]);
    rejected(fn () => $runtime->exec('CREATE TEMPORARY TABLE runtime_temp_probe (id INT)'), [1044, 1142]);
    echo "PASS: SQL HMAC vectors, private-object denials, and CREATE/TEMPORARY denials\n";

    $connection = (string) $runtime->query('SELECT CONNECTION_ID()')->fetchColumn();
    $fields = ['d3a1/v1', 'f65f1078-fdc1-4f22-9bfb-c1aab2ef5430', '10', '20', '30', '40', '50', 'income', '123.45', '2026-10-14', 'USD', 'integration-cutover-nonce', '2026-10-14T00:00:00.000000Z', '2026-10-14T00:01:00.000000Z', $connection];
    $message = payload($fields);
    $tag = hash_hmac('sha256', 'd3a1-proof-connection:'.$connection."\0".$message, $secret, true);
    call($runtime, 'proof_verify', [$connection, $message, $tag]);
    foreach (array_keys($fields) as $index) {
        $changed = $fields;
        $changed[$index] .= 'x';
        rejected(fn () => call($runtime, 'proof_verify', [$connection, payload($changed), $tag]), [1644]);
    }
    $other = connect('RUNTIME');
    rejected(fn () => call($other, 'proof_verify', [$connection, $message, $tag]), [1644]);
    $otherConnection = (string) $other->query('SELECT CONNECTION_ID()')->fetchColumn();
    rejected(fn () => call($other, 'proof_verify', [$otherConnection, $message, $tag]), [1644]);
    // This verifier deliberately has no command-consumption table. Replay MUST
    // still succeed here; documenting that prevents confusing MAC with one-use.
    call($runtime, 'proof_verify', [$connection, $message, $tag]);
    echo "PASS: payload modification and different-connection rejection; replay remains possible in this primitive\n";

    foreach (['foreign_key_checks', 'unique_checks'] as $variable) {
        $original = (int) $runtime->query('SELECT @@session.'.$variable)->fetchColumn();
        try {
            $runtime->exec('SET SESSION '.$variable.' = 0');
            demand((int) $runtime->query('SELECT @@session.'.$variable)->fetchColumn() === 0, 'Variable did not change');
            if ($variable === 'foreign_key_checks') {
                $runtime->exec('INSERT INTO child_rows VALUES (9000, -1)');
                demand((int) $owner->query('SELECT COUNT(*) FROM child_rows WHERE id = 9000')->fetchColumn() === 1, 'FK-disabled counterexample absent');
                $runtime->exec('DELETE FROM child_rows WHERE id = 9000');
            }
        } finally {
            $runtime->exec('SET SESSION '.$variable.' = '.$original);
        }
    }
    $binlog = (int) $runtime->query('SELECT @@session.sql_log_bin')->fetchColumn();
    rejected(fn () => $runtime->exec('SET SESSION sql_log_bin = '.(1 - $binlog)), [1227]);
    echo "PASS: constraint session variables are writable; sql_log_bin change denied\n";

    $base = 100;
    foreach (['before' => 1644, 'first' => 1644, 'second' => 1644, 'duplicate' => 1062, 'fk' => 1452, 'check' => 3819, 'timeout' => 1205] as $kind => $errno) {
        $runtime->beginTransaction();
        $runtime->exec('INSERT INTO caller_writes VALUES ('.$base.')');
        $timeout = (int) $runtime->query('SELECT @@session.innodb_lock_wait_timeout')->fetchColumn();
        try {
            if ($kind === 'timeout') {
                $owner->beginTransaction();
                $owner->exec('UPDATE lock_rows SET value = value + 1 WHERE id = 2');
                $runtime->exec('SET SESSION innodb_lock_wait_timeout = 1');
            }
            $error = rejected(fn () => call($runtime, 'proof_failure', [$kind, $base]), [$errno]);
            // Explicit SQL COMMIT tests the malicious catch-and-commit case even
            // when the engine has already ended a transaction.
            $runtime->exec('COMMIT');
            $fullRollback = $kind === 'timeout' && (int) $owner->query('SELECT @@innodb_rollback_on_timeout')->fetchColumn() === 1;
            demand((int) $owner->query('SELECT COUNT(*) FROM caller_writes WHERE id = '.$base)->fetchColumn() === ($fullRollback ? 0 : 1), 'Unexpected caller write survival');
            demand((int) $owner->query('SELECT COUNT(*) FROM protected_writes WHERE id BETWEEN '.$base.' AND '.($base + 2))->fetchColumn() === 0, 'Protected writes survived failed CALL');
            echo 'PASS: '.$kind.' preserves '.$error->errorInfo[0].'/'.$errno.'; B/C absent; A '.($fullRollback ? 'absent' : 'survives')."\n";
        } finally {
            if ($runtime->inTransaction()) {
                $runtime->rollBack();
            }
            if ($owner->inTransaction()) {
                $owner->rollBack();
            }
            $runtime->exec('SET SESSION innodb_lock_wait_timeout = '.$timeout);
        }
        $base += 10;
    }
    $runtime->beginTransaction();
    call($runtime, 'proof_failure', ['success', 500]);
    $runtime->rollBack();
    demand((int) $owner->query('SELECT COUNT(*) FROM protected_writes WHERE id IN (500, 501)')->fetchColumn() === 0, 'Outer rollback failed');

    rejected(fn () => call($runtime, 'proof_transaction'), [1644]);
    foreach (['begin', 'read', 'write', 'savepoint'] as $stage) {
        $runtime->beginTransaction();
        try {
            if ($stage === 'read') {
                $runtime->query('SELECT id FROM child_rows')->fetchAll();
            } elseif ($stage === 'write') {
                $runtime->exec('INSERT INTO caller_writes VALUES (1000)');
            } elseif ($stage === 'savepoint') {
                $runtime->exec('SAVEPOINT laravel_like_nested');
            }
            call($runtime, 'proof_transaction');
        } finally {
            $runtime->rollBack();
        }
        rejected(fn () => call($runtime, 'proof_transaction'), [1644]);
    }
    rejected(fn () => call($other, 'proof_transaction'), [1644]);
    $runtime->exec('SET SESSION autocommit = 0');
    try {
        rejected(fn () => call($runtime, 'proof_transaction'), [1644]);
    } finally {
        $runtime->exec('ROLLBACK');
        $runtime->exec('SET SESSION autocommit = 1');
    }
    echo "PASS: transaction candidate cases 1-7 plus autocommit=0 exclusion\n";
    echo "NOT PROVEN: deadlock victim cleanup, disabled instrumentation, full application-table attacks, durable replay consumption, and production owner authorization\n";
}

try {
    runProof();
} catch (Throwable $error) {
    // SQL/connection exceptions may embed configuration. Do not print their text.
    if ($error instanceof PDOException) {
        fwrite(STDERR, 'BLOCKED: database error '.($error->errorInfo[0] ?? $error->getCode()).'/'.($error->errorInfo[1] ?? 'unknown')." (details withheld)\n");
    } else {
        fwrite(STDERR, 'BLOCKED: '.$error->getMessage()."\n");
    }
    exit(1);
}
