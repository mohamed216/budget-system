<?php

// TEST ONLY. No Laravel/.env/bootstrap or PDO work is performed by --self-test.
const OA_SCHEMA = 'phase3_d3a1_authority';
const OA_UUID = '9277202f-c26a-11f1-af5b-e6c2735e5133';
const OA_FIELDS = ['protocol_version', 'audience', 'operation', 'key_id', 'command_uuid', 'owner_id', 'account_id', 'intent_id', 'amount', 'direction', 'accounting_date', 'currency', 'account_revision', 'intent_revision', 'issued_at', 'expires_at', 'target_connection_id'];
const OA_TABLES = ['users', 'sessions', 'cache', 'jobs', 'failed_jobs', 'proof_accounts', 'proof_intents', 'proof_commands', 'proof_effects', 'proof_results', 'proof_keys', 'proof_caller_writes', 'proof_manifest'];
const OA_ROUTINES = ['oa_hmac', 'oa_clock', 'oa_require_transaction', 'oa_lock_owner', 'oa_finalize'];

require_once __DIR__.'/d3a1-owner-authority-bootstrap.php';
define('OA_DSN', oaDsn('SCHEMA'));

class OaFailure extends RuntimeException {}

class OaSigningKeyInactive extends OaFailure
{
    public const REASON = 'signing_key_inactive';

    public function __construct()
    {
        parent::__construct('OA signing key inactive');
    }
}

class OaSqlFailure extends OaFailure
{
    public function __construct(public array $diagnostic)
    {
        parent::__construct(json_encode($diagnostic, JSON_THROW_ON_ERROR));
    }
}

function oaDemand(bool $condition, string $message): void
{
    if (! $condition) {
        throw new OaFailure($message);
    }
}

function oaIdentifier(string $name): string
{
    oaDemand((bool) preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name), 'OA invalid repository identifier');

    return '`'.$name.'`';
}

function oaSql(PDO $pdo, string $sql, array $parameters = [], string $phase = 'PROOF'): PDOStatement
{
    try {
        if ($parameters === []) {
            // Stored-program DDL/GRANT are sent intact over the text protocol;
            // do not rely on native-prepare support or driver emulation fallback.
            return $pdo->query($sql);
        }
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    } catch (PDOException $error) {
        // Templates are repository-owned. Never attach bindings or driver messages.
        throw new OaSqlFailure(oaDiagnostic($sql, $phase, $error->errorInfo ?? []));
    }
}

function oaDiagnostic(string $sql, string $phase, array $native): array
{
    return ['phase' => $phase, 'sql' => $sql, 'sqlstate' => $native[0] ?? 'unknown', 'errno' => (int) ($native[1] ?? 0), 'details' => 'bindings and native message withheld'];
}

function oaConnect(string $role): PDO
{
    return oaOpenConnection($role, 'SCHEMA');
}

function oaEncode(array $values): string
{
    oaDemand(array_keys($values) === OA_FIELDS, 'OA field order/catalogue mismatch');
    oaValidate($values);

    return implode('', array_map(fn (string $value): string => strlen($value).':'.$value, array_values($values)));
}

function oaValidate(array $v): void
{
    foreach (OA_FIELDS as $field) {
        oaDemand(isset($v[$field]) && is_string($v[$field]) && strlen($v[$field]) <= 255, 'OA missing or non-string field');
    }
    oaDemand($v['protocol_version'] === 'd3a1-oa/1' && $v['audience'] === OA_SCHEMA && $v['operation'] === 'apply-neutral-intent', 'OA invalid protocol scope');
    oaDemand((bool) preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $v['key_id']), 'OA invalid key identifier');
    oaDemand((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $v['command_uuid']), 'OA invalid UUID');
    foreach (['owner_id', 'account_id', 'intent_id', 'account_revision', 'intent_revision', 'issued_at', 'expires_at', 'target_connection_id'] as $field) {
        oaDemand((bool) preg_match('/^(0|[1-9][0-9]{0,18})$/D', $v[$field]) && (strlen($v[$field]) < 19 || strcmp($v[$field], '9223372036854775807') <= 0), 'OA invalid unsigned integer');
    }
    oaDemand((bool) preg_match('/^(0|[1-9][0-9]{0,15})\.[0-9]{2}$/D', $v['amount']) && $v['amount'] !== '0.00', 'OA invalid exact decimal');
    oaDemand(in_array($v['direction'], ['income', 'expense'], true) && (bool) preg_match('/^[A-Z]{3}$/D', $v['currency']), 'OA invalid accounting enum');
    $date = $v['accounting_date'];
    oaDemand((bool) preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $date) && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)), 'OA invalid calendar date');
}

function oaFresh(int $issued, int $expires, int $now): bool
{
    return $issued >= 0 && $issued <= $now && $expires > $now && $expires > $issued && $expires - $issued <= 60000000;
}

function oaClock(PDO $pdo): int
{
    $s = oaSql($pdo, 'CALL oa_clock(@oa_clock)');
    $s->closeCursor();

    return (int) oaSql($pdo, 'SELECT @oa_clock')->fetchColumn();
}

function oaClockPreflight(PDO $pdo): void
{
    $zone = oaSql($pdo, 'SELECT @@session.time_zone')->fetchColumn();
    try {
        $readings = [];
        foreach ([['SET SESSION timestamp = 946684800', '+14:00'], ['SET SESSION timestamp = 1893456000', '-12:00']] as [$timestampSql, $timezone]) {
            oaSql($pdo, $timestampSql, [], 'CLOCK');
            oaSql($pdo, 'SET SESSION time_zone = ?', [$timezone], 'CLOCK');
            $readings[] = oaClock($pdo);
            oaDemand(oaSql($pdo, 'SELECT @@session.time_zone')->fetchColumn() === $timezone, 'OA clock failed to restore time zone');
        }
        oaDemand(abs($readings[1] - $readings[0]) < 10000000, 'OA SYSDATE follows caller timestamp; clock unsuitable');
        oaDemand($readings[0] > 1700000000000000 && $readings[0] < 4102444800000000, 'OA clock outside proof range');
    } finally {
        $failures = [];
        // Restore live time, then the caller's zone; attempt both even on failure.
        foreach ([['SET SESSION timestamp = DEFAULT', []], ['SET SESSION time_zone = ?', [$zone]]] as [$sql, $parameters]) {
            try {
                oaSql($pdo, $sql, $parameters, 'CLOCK_CLEANUP');
            } catch (Throwable $error) {
                $failures[] = $error instanceof OaSqlFailure ? $error->diagnostic : ['exception' => get_class($error)];
            }
        }
        oaDemand($failures === [], 'OA clock cleanup failed; BLOCKED: '.json_encode($failures, JSON_THROW_ON_ERROR));
    }
}

function oaInstallerStatements(): array
{
    $sql = file_get_contents(__DIR__.'/d3a1-owner-authority.sql');
    oaDemand($sql !== false, 'OA SQL fixture missing');
    preg_match_all('/^-- OA (TABLE|ROUTINE|TRIGGER) ([a-z_]+)\n([\s\S]*?)(?=^-- OA |\z)/m', $sql, $matches, PREG_SET_ORDER);

    return array_map(function (array $match): array {
        oaIdentifier($match[2]);

        return ['phase' => 'CREATE_'.$match[1], 'object' => $match[2], 'sql' => trim($match[3])];
    }, $matches);
}

function oaFixtureFingerprint(): string
{
    return hash('sha256', file_get_contents(__DIR__.'/d3a1-owner-authority.sql').file_get_contents(__DIR__.'/d3a1-owner-authority-grants.sql').file_get_contents(__DIR__.'/d3a1-owner-authority-cases.json'));
}

function oaGrantCatalogue(): array
{
    $grants = [];
    $add = function (string $role, string $privileges, string $table) use (&$grants): void {
        $grants[] = 'GRANT '.$privileges.' ON '.oaIdentifier(OA_SCHEMA).'.'.oaIdentifier($table)." TO 'd3a1_oa_{$role}'@'%';";
    };
    $execute = function (string $role, string $routine) use (&$grants): void {
        $grants[] = 'GRANT EXECUTE ON PROCEDURE '.oaIdentifier(OA_SCHEMA).'.'.oaIdentifier($routine)." TO 'd3a1_oa_{$role}'@'%';";
    };
    $add('auth', 'SELECT', 'users');
    $add('auth', 'UPDATE (`password`, `remember_token`, `updated_at`)', 'users');
    foreach (['sessions', 'cache', 'jobs', 'failed_jobs'] as $table) {
        $add('auth', 'SELECT, INSERT, UPDATE, DELETE', $table);
    }
    $execute('auth', 'oa_clock');
    $add('accounting', 'SELECT (`id`)', 'users');
    foreach (['proof_accounts', 'proof_intents'] as $table) {
        $add('accounting', 'SELECT', $table);
    }
    $add('accounting', 'UPDATE (`amount`, `direction`, `accounting_date`, `currency`, `revision`)', 'proof_intents');
    $add('accounting', 'SELECT, INSERT, DELETE', 'proof_caller_writes');
    foreach (['oa_lock_owner', 'oa_finalize'] as $routine) {
        $execute('accounting', $routine);
    }
    $add('verify', 'SELECT', 'proof_keys');
    $add('verify', 'TRIGGER', 'proof_intents');
    foreach (['proof_accounts', 'proof_intents'] as $table) {
        $add('verify', 'SELECT, UPDATE (`id`)', $table);
    }
    foreach (['proof_commands', 'proof_effects', 'proof_results'] as $table) {
        $add('verify', 'SELECT, INSERT', $table);
    }
    $add('verify', 'SELECT', 'proof_manifest');
    $add('verify', 'SELECT (`id`), UPDATE (`id`)', 'users');
    foreach (array_diff(OA_ROUTINES, ['oa_lock_owner']) as $routine) {
        $execute('verify', $routine);
    }
    $add('lock', 'SELECT (`id`), UPDATE (`id`)', 'users');
    foreach (['oa_require_transaction', 'oa_lock_owner'] as $routine) {
        $execute('lock', $routine);
    }
    foreach (['proof_manifest', 'proof_accounts', 'proof_intents', 'proof_commands', 'proof_effects', 'proof_results', 'proof_caller_writes'] as $table) {
        $add('observer', 'SELECT', $table);
    }
    foreach (['verify' => ['threads', 'events_transactions_current'], 'observer' => ['threads', 'events_transactions_current', 'data_locks', 'data_lock_waits']] as $role => $tables) {
        foreach ($tables as $table) {
            $grants[] = 'GRANT SELECT ON `performance_schema`.'.oaIdentifier($table)." TO 'd3a1_oa_{$role}'@'%';";
        }
    }

    return $grants;
}

function oaGrantText(): string
{
    return "-- TEST ONLY. Existing isolated identities only; apply after --install-owner-authority.\n-- No CREATE USER, secrets, production grants, or schema-wide runtime privileges.\n".implode("\n", oaGrantCatalogue())."\n";
}

function oaInstall(): void
{
    $root = oaConnect('INSTALLER');
    foreach (['TABLES', 'ROUTINES', 'TRIGGERS', 'EVENTS'] as $kind) {
        $column = match ($kind) {
            'ROUTINES' => 'ROUTINE_SCHEMA', 'TRIGGERS' => 'TRIGGER_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA', default => 'TABLE_SCHEMA'
        };
        oaDemand((int) oaSql($root, "SELECT COUNT(*) FROM information_schema.$kind WHERE $column = ?", [OA_SCHEMA], 'EMPTY_SCHEMA')->fetchColumn() === 0, 'OA installation requires empty dedicated schema');
    }
    $identities = oaSql($root, "SELECT User, account_locked FROM mysql.user WHERE Host='%' AND User IN ('d3a1_oa_auth','d3a1_oa_accounting','d3a1_oa_verify','d3a1_oa_lock','d3a1_oa_observer')", [], 'IDENTITIES')->fetchAll(PDO::FETCH_KEY_PAIR);
    oaRequireInstallerIdentities($identities);
    foreach (oaInstallerStatements() as $entry) {
        oaSql($root, $entry['sql'], [], $entry['phase'].':'.$entry['object'])->closeCursor();
    }
    oaSql($root, 'INSERT INTO proof_manifest VALUES (1, ?)', [oaFixtureFingerprint()], 'MANIFEST');
    echo "READY: isolated owner-authority schema installed; apply dedicated grants; no live proof\n";
}

function oaSecret(string $name): string
{
    $value = getenv($name);
    oaDemand(is_string($value) && strlen($value) >= 32, 'OA required fixture secret missing or too short');

    return $value;
}

function oaSeed(PDO $root): array
{
    $password = oaSecret('D3A1_OA_FIXTURE_PASSWORD');
    $keys = [];
    foreach (['K1', 'K2'] as $id) {
        $hex = oaSecret('D3A1_OA_'.$id.'_HEX');
        oaDemand(strlen($hex) === 64 && ctype_xdigit($hex), 'OA key must be 64 hex characters');
        $keys[$id] = hex2bin($hex);
    }
    foreach ($keys as $key) {
        oaDemand(is_string($key) && strlen($key) === 32, 'OA key must be exactly 32 random bytes encoded as hex');
    }
    oaDemand($keys['K1'] !== $keys['K2'], 'OA fixture keys must differ');
    foreach ([1, 2] as $owner) {
        oaSql($root, 'INSERT INTO users (id,name,email,password) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE password = ?, remember_token = NULL', [$owner, 'Proof owner '.$owner, 'owner'.$owner.'@example.test', $hash = password_hash($password.($owner === 2 ? '-owner2' : ''), PASSWORD_BCRYPT, ['cost' => 4]), $hash], 'SEED');
        oaSql($root, 'INSERT IGNORE INTO proof_accounts VALUES (?,?,?,1)', [$owner, $owner, 'USD'], 'SEED');
    }
    foreach ([[1, 1], [2, 2], [3, 1], [4, 1]] as [$id, $owner]) {
        oaSql($root, 'INSERT INTO proof_intents VALUES (?,?,?,10.00,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE amount=10.00, revision=1', [$id, $owner, $owner, 'income', '2026-01-02', 'USD', 1, $id * 1000 + 1, $id * 1000 + 1], 'SEED');
    }
    foreach ($keys as $id => $key) {
        oaSql($root, 'INSERT INTO proof_keys VALUES (?,?,?,9223372036854775807,9223372036854775807) ON DUPLICATE KEY UPDATE secret=?, status=\'active\', sign_until_us=9223372036854775807, verify_until_us=9223372036854775807', [$id, $key, 'active', $key], 'SEED');
    }

    return $keys;
}

function oaUuid(): string
{
    $hex = bin2hex(random_bytes(16));

    return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
}

function oaFinalizeSql(): string
{
    return 'CALL oa_finalize('.implode(',', array_fill(0, 18, '?')).')';
}

function oaFinalize(PDO $pdo, array $ticket): array
{
    $parameters = [];
    foreach (OA_FIELDS as $field) {
        $parameters[] = $ticket[$field] ?? null;
    }
    $parameters[] = isset($ticket['tag']) && ctype_xdigit($ticket['tag']) ? hex2bin($ticket['tag']) : '';
    $statement = oaSql($pdo, oaFinalizeSql(), $parameters, 'FINALIZE');
    $result = $statement->fetch(PDO::FETCH_ASSOC);
    $statement->closeCursor();
    oaDemand(is_array($result) && isset($result['result_uuid']), 'OA result missing');

    return $result;
}

function oaExpect(callable $action, string $state, array $numbers): array
{
    try {
        $action();
    } catch (OaSqlFailure $error) {
        oaDemand($error->diagnostic['sqlstate'] === $state && in_array($error->diagnostic['errno'], $numbers, true), 'OA unexpected native diagnostic: '.$error->getMessage());

        return ['sqlstate' => $state, 'errno' => $error->diagnostic['errno']];
    }
    throw new OaFailure('OA expected rejection did not occur');
}

function oaRecord(string $case, array $evidence = []): void
{
    global $oaObserved;
    oaDemand(! isset($oaObserved[$case]), 'OA duplicate case observation');
    $oaObserved[$case] = true;
    echo json_encode(['case' => $case, 'observed' => 'PASS'] + $evidence, JSON_THROW_ON_ERROR)."\n";
}

function oaCounts(PDO $observer, string $uuid): array
{
    $counts = [];
    foreach (['proof_commands', 'proof_effects', 'proof_results'] as $table) {
        $counts[] = (int) oaSql($observer, 'SELECT COUNT(*) FROM '.oaIdentifier($table).' WHERE command_uuid=?', [$uuid], 'BASELINE_VERIFY')->fetchColumn();
    }

    return $counts;
}

function oaRestore(PDO $pdo): void
{
    global $oaSessionDefaults;
    oaSql($pdo, 'ROLLBACK', [], 'CLEANUP');
    oaSql($pdo, 'SET SESSION autocommit=1', [], 'CLEANUP');
    oaSql($pdo, 'SET SESSION foreign_key_checks=1', [], 'CLEANUP');
    oaSql($pdo, 'SET SESSION unique_checks=1', [], 'CLEANUP');
    oaSql($pdo, 'SET SESSION timestamp=DEFAULT', [], 'CLEANUP');
    oaDemand(isset($oaSessionDefaults[$pdo]), 'OA session baseline unavailable');
    oaSql($pdo, 'SET SESSION time_zone=?', [$oaSessionDefaults[$pdo]], 'CLEANUP');
    oaDemand(array_map('intval', oaSql($pdo, 'SELECT @@foreign_key_checks, @@unique_checks')->fetch(PDO::FETCH_NUM)) === [1, 1], 'OA flags not restored');
}

function oaDenials(PDO $accounting): void
{
    $sql = oaDenialStatements();
    foreach ($sql as $statement) {
        oaExpect(fn () => oaSql($accounting, $statement, array_fill(0, substr_count($statement, '?'), 'proof-placeholder'), 'PRIVILEGE_DENIAL'), '42000', [1044, 1142, 1143, 1227, 1370]);
    }
    foreach (['oa_hmac(?,?,@oa_tag)', 'oa_require_transaction()', 'oa_clock(@oa_time)'] as $call) {
        oaExpect(fn () => oaSql($accounting, 'CALL '.$call, array_fill(0, substr_count($call, '?'), 'proof-placeholder'), 'PRIVATE_ROUTINE_DENIAL'), '42000', [1370]);
    }
}

function oaDenialStatements(): array
{
    $sql = [
        'SELECT password FROM users', 'SELECT remember_token FROM users', 'SELECT email FROM users', 'SELECT * FROM users',
        'UPDATE users SET password=? WHERE id=2', 'UPDATE users SET remember_token=? WHERE id=2', 'UPDATE users SET id=3 WHERE id=2', 'UPDATE users SET email=? WHERE id=2',
        'INSERT INTO users(id,name,email,password) VALUES(3,?,?,?)', 'DELETE FROM users WHERE id=2',
        'SELECT secret FROM proof_keys', 'UPDATE proof_keys SET status=\'active\'', 'INSERT INTO proof_commands VALUES(?,?,?)',
        'CREATE TABLE oa_forbidden(id INT)', 'ALTER TABLE proof_intents ADD COLUMN forbidden INT', 'DROP TABLE proof_intents',
        'CREATE TRIGGER oa_forbidden BEFORE UPDATE ON proof_intents FOR EACH ROW SET NEW.amount=1',
        'DROP TRIGGER oa_intent_guard', 'CREATE PROCEDURE oa_forbidden() SELECT 1', 'ALTER PROCEDURE oa_finalize COMMENT \'forbidden\'', 'DROP PROCEDURE oa_finalize',
    ];
    foreach (['sessions', 'cache', 'jobs', 'failed_jobs'] as $table) {
        $sql[] = 'SELECT * FROM '.oaIdentifier($table);
        $sql[] = 'DELETE FROM '.oaIdentifier($table);
        $sql[] = match ($table) {
            'sessions' => 'INSERT INTO sessions(id,payload,last_activity) VALUES(?,?,1)',
            'cache' => 'INSERT INTO cache(`key`,value,expiration) VALUES(?,?,1)',
            'jobs' => 'INSERT INTO jobs(queue,payload,attempts,available_at,created_at) VALUES(?,?,0,1,1)',
            default => 'INSERT INTO failed_jobs(uuid,connection,queue,payload,exception) VALUES(?,?,?,?,?)',
        };
        $sql[] = 'UPDATE '.oaIdentifier($table).' SET '.($table === 'cache' ? 'value' : 'payload').'=?';
    }

    return $sql;
}

function oaStartWorker(): array
{
    $environment = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
    foreach (['D3A1_ISOLATED_TEST', 'D3A1_SERVER_UUID', 'D3A1_OA_DSN', 'D3A1_OA_ACCOUNTING_USER', 'D3A1_OA_ACCOUNTING_PASSWORD'] as $name) {
        $environment[$name] = getenv($name) ?: '';
    }
    $process = proc_open([PHP_BINARY, __FILE__, '--oa-worker'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes, __DIR__, $environment);
    oaDemand(is_resource($process), 'OA worker launch failed');
    stream_set_blocking($pipes[1], false);
    $worker = ['process' => $process, 'input' => $pipes[0], 'output' => $pipes[1], 'buffer' => ''];
    $ready = oaReadWorker($worker);
    oaDemand(($ready['event'] ?? '') === 'ready', 'OA worker failed preflight');
    $worker['id'] = $ready['id'];

    return $worker;
}

function oaSendWorker(array $worker, array $message): void
{
    $bytes = json_encode($message, JSON_THROW_ON_ERROR)."\n";
    oaDemand(fwrite($worker['input'], $bytes) === strlen($bytes), 'OA worker pipe failed');
    fflush($worker['input']);
}

function oaReadWorker(array &$worker): array
{
    $deadline = hrtime(true) + 10000000000;
    while (true) {
        if (($end = strpos($worker['buffer'], "\n")) !== false) {
            $line = substr($worker['buffer'], 0, $end);
            $worker['buffer'] = substr($worker['buffer'], $end + 1);

            return json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        }
        $left = $deadline - hrtime(true);
        oaDemand($left > 0, 'OA worker deadline expired');
        $read = [$worker['output']];
        $write = $except = [];
        $seconds = intdiv($left, 1000000000);
        oaDemand(stream_select($read, $write, $except, $seconds, intdiv($left % 1000000000, 1000)) === 1, 'OA worker response unavailable');
        $chunk = fread($worker['output'], 8192);
        oaDemand(is_string($chunk) && $chunk !== '', 'OA worker exited before response');
        $worker['buffer'] .= $chunk;
        oaDemand(strlen($worker['buffer']) < 65536, 'OA worker response oversized');
    }
}

function oaStopWorker(array &$worker): void
{
    if (! is_resource($worker['process'])) {
        return;
    }
    try {
        oaSendWorker($worker, ['action' => 'close']);
        for ($pending = 0; $pending < 3; $pending++) {
            $event = oaReadWorker($worker);
            if (($event['event'] ?? '') === 'closed') {
                fclose($worker['input']);
                fclose($worker['output']);
                $exit = proc_close($worker['process']);
                $worker['process'] = null;
                oaDemand($exit === 0, 'OA worker abnormal cleanup exit');

                return;
            }
        }
        throw new OaFailure('OA worker did not acknowledge cleanup');
    } catch (Throwable $error) {
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            fclose($worker['input']);
            fclose($worker['output']);
            proc_close($worker['process']);
            $worker['process'] = null;
        }
        throw new OaFailure('OA worker cleanup failed; private connection discarded');
    }
}

function oaWorker(): void
{
    $pdo = oaConnect('ACCOUNTING');
    echo json_encode(['event' => 'ready', 'id' => (int) oaSql($pdo, 'SELECT CONNECTION_ID()')->fetchColumn()])."\n";
    fflush(STDOUT);
    try {
        while (($line = fgets(STDIN)) !== false) {
            $message = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            try {
                if ($message['action'] === 'begin') {
                    oaSql($pdo, 'START TRANSACTION');
                    $result = [];
                } elseif ($message['action'] === 'finalize') {
                    $result = oaFinalize($pdo, $message['ticket']);
                } elseif ($message['action'] === 'lock') {
                    oaSql($pdo, 'CALL oa_lock_owner(?)', [1])->closeCursor();
                    $result = [];
                } elseif (in_array($message['action'], ['commit', 'rollback'], true)) {
                    oaSql($pdo, strtoupper($message['action']));
                    $result = [];
                } elseif ($message['action'] === 'close') {
                    oaRestore($pdo);
                    echo "{\"event\":\"closed\"}\n";
                    fflush(STDOUT);

                    return;
                } else {
                    throw new OaFailure('OA invalid worker action');
                }
                $event = ['event' => 'result', 'ok' => true, 'result' => $result];
            } catch (OaSqlFailure $error) {
                $event = ['event' => 'result', 'ok' => false, 'sqlstate' => $error->diagnostic['sqlstate'], 'errno' => $error->diagnostic['errno']];
            }
            echo json_encode($event, JSON_THROW_ON_ERROR)."\n";
            fflush(STDOUT);
        }
    } finally {
        oaRestore($pdo);
    }
}

function oaWaitLock(PDO $observer, int $requester, int $blocker, string $table): void
{
    $deadline = hrtime(true) + 10000000000;
    do {
        $row = oaSql($observer, 'SELECT r.PROCESSLIST_ID requester, b.PROCESSLIST_ID blocker, l.OBJECT_NAME object_name FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE=w.ENGINE WHERE l.OBJECT_SCHEMA=? AND r.PROCESSLIST_ID=? AND b.PROCESSLIST_ID=?', [OA_SCHEMA, $requester, $blocker], 'LOCK_COORDINATION')->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            oaDemand($row['object_name'] === $table, 'OA unexpected blocking object');

            return;
        }
        oaDemand(hrtime(true) < $deadline, 'OA required lock wait not observed');
    } while (true);
}

function oaCloseWorker(array &$worker): void
{
    oaSendWorker($worker, ['action' => 'close']);
    oaDemand((oaReadWorker($worker)['event'] ?? '') === 'closed', 'OA worker cleanup not acknowledged');
    fclose($worker['input']);
    fclose($worker['output']);
    $exit = proc_close($worker['process']);
    $worker['process'] = null;
    oaDemand($exit === 0, 'OA worker exit failed');
}

function oaAuthProof(PDO $root, PDO $accounting, OaLaravelFixture $web): void
{
    $page = $web->request('GET', '/login');
    oaDemand($page['status'] === 200, 'OA real login page failed');
    $anonymous = $page['session_id'];
    $csrf = $web->csrf();
    oaDemand($web->request('POST', '/ticket', ['_token' => $csrf, 'intent_id' => '1', 'command_uuid' => oaUuid()])['status'] === 401, 'OA anonymous issuer access accepted');
    oaDemand($web->request('POST', '/login', ['email' => 'owner1@example.test', 'password' => 'not-a-password'])['status'] === 419, 'OA CSRF middleware bypassed');
    oaRecord('auth_csrf');
    $response = $web->request('POST', '/login', ['_token' => $csrf, 'email' => 'owner1@example.test', 'password' => oaSecret('D3A1_OA_FIXTURE_PASSWORD')]);
    oaDemand($response['status'] === 302, 'OA login failed');
    oaRecord('auth_login');
    oaDemand($response['session_id'] !== $anonymous && (int) oaSql($root, 'SELECT COUNT(*) FROM sessions WHERE id=?', [$anonymous])->fetchColumn() === 0, 'OA session not regenerated');
    oaRecord('auth_regeneration');
    $identity = $web->request('GET', '/');
    oaDemand($identity['status'] === 200 && ($identity['body']['owner'] ?? null) === '1', 'OA authenticated session reuse failed');
    oaRecord('auth_reuse');
    $hash = oaSql($root, 'SELECT password FROM users WHERE id=1')->fetchColumn();
    oaDemand(password_verify(oaSecret('D3A1_OA_FIXTURE_PASSWORD'), $hash) && password_get_info($hash)['options']['cost'] === 6, 'OA protected rehash did not occur');
    unset($hash);
    oaRecord('auth_rehash');
    $users = array_values(array_filter($web->trace, fn ($event) => $event['users']));
    oaDemand(count(array_filter($users, fn ($event) => $event['connection'] === 'oa_auth' && $event['write'])) > 0 && count(array_filter($users, fn ($event) => $event['connection'] === 'oa_accounting' && ! $event['write'])) > 0, 'OA connection routing not observed');
    oaDemand(count(array_filter($users, fn ($event) => $event['connection'] === 'oa_accounting' && $event['write'])) === 0, 'OA accounting executed auth write');
    oaRecord('auth_connection_routing');
    // Real framework token rotation, through the protected adapter, even though login does not request remember-me.
    $auth = oaConnect('AUTH');
    require_once __DIR__.'/d3a1-owner-authority-laravel.php';
    $web->rotateRememberToken();
    oaDemand(oaSql($auth, 'SELECT remember_token FROM users WHERE id=1')->fetchColumn() !== null, 'OA remember token update missing');
    oaRecord('auth_remember_rotation');
    oaDenials($accounting);
    $web->infrastructure();
    oaRecord('auth_infrastructure');
    $old = $web->cookies;
    $oldId = $identity['session_id'];
    oaDemand($web->request('POST', '/logout', ['_token' => $web->csrf()])['status'] === 302, 'OA logout failed');
    oaDemand((int) oaSql($root, 'SELECT COUNT(*) FROM sessions WHERE id=?', [$oldId])->fetchColumn() === 0, 'OA old session remains');
    $web->cookies = $old;
    oaDemand($web->request('GET', '/')['status'] === 401, 'OA old cookie still authenticates');
    $web->cookies = [];
    oaRecord('auth_logout');
    oaDemand($web->request('POST', '/login', ['_token' => $web->csrf(), 'email' => 'owner2@example.test', 'password' => 'attacker-chosen-password'])['status'] === 422, 'OA attacker authenticated victim');
    oaRecord('auth_bad_password');
    $web->login();
    $target = (int) oaSql($accounting, 'SELECT CONNECTION_ID()')->fetchColumn();
    oaDemand($web->request('POST', '/ticket', ['_token' => $web->csrf(), 'owner_id' => '2', 'intent_id' => '2', 'command_uuid' => oaUuid()], $target)['status'] === 403, 'OA issuer trusted caller owner');
    oaRecord('auth_denials');
}

function oaLockProof(PDO $accounting, PDO $observer): void
{
    oaExpect(fn () => oaSql($accounting, 'CALL oa_lock_owner(?)', [1]), '45013', [60054]);
    oaRecord('lock_explicit_transaction');
    oaExpect(fn () => oaSql($accounting, 'UPDATE users SET password=? WHERE id=1', ['placeholder']), '42000', [1142, 1143]);
    oaRecord('lock_credential_denial');
    foreach (['COMMIT' => 'lock_commit_release', 'ROLLBACK' => 'lock_rollback_release'] as $release => $case) {
        $worker = oaStartWorker();
        try {
            oaSql($accounting, 'START TRANSACTION');
            oaSql($accounting, 'CALL oa_lock_owner(?)', [1])->closeCursor();
            oaSendWorker($worker, ['action' => 'begin']);
            oaDemand((oaReadWorker($worker)['ok'] ?? false) === true, 'OA lock worker transaction failed');
            oaSendWorker($worker, ['action' => 'lock']);
            oaWaitLock($observer, $worker['id'], (int) oaSql($accounting, 'SELECT CONNECTION_ID()')->fetchColumn(), 'users');
            oaSql($accounting, $release);
            oaDemand((oaReadWorker($worker)['ok'] ?? false) === true, 'OA owner lock did not release');
            oaSendWorker($worker, ['action' => 'rollback']);
            oaDemand((oaReadWorker($worker)['ok'] ?? false) === true, 'OA lock worker rollback failed');
            oaCloseWorker($worker);
            oaRecord($case);
        } finally {
            oaRestore($accounting);
            oaStopWorker($worker);
        }
    }
}

function oaTryTicket(PDO $accounting, PDO $observer, array $ticket, string $state, array $numbers): void
{
    oaSql($accounting, 'START TRANSACTION');
    try {
        oaExpect(fn () => oaFinalize($accounting, $ticket), $state, $numbers);
        oaSql($accounting, 'COMMIT');
        oaDemand(oaCounts($observer, $ticket['command_uuid']) === [0, 0, 0], 'OA rejected CALL left protected state');
    } finally {
        oaRestore($accounting);
    }
}

function oaRetag(array $ticket, string $key): array
{
    unset($ticket['tag']);
    $ticket['tag'] = hash_hmac('sha256', oaEncode($ticket), $key);

    return $ticket;
}

function oaExpectSigningKeyInactive(callable $issue): void
{
    try {
        $issue();
    } catch (OaSigningKeyInactive $error) {
        return;
    }
    throw new OaFailure('OA issuer signed with verification-only key');
}

function oaReplayProof(PDO $root, PDO $auth, PDO $accounting, PDO $observer, OaLaravelFixture $web, array $keys): void
{
    oaClockPreflight($auth);
    oaRecord('clock_preflight');
    foreach (oaVectors() as [$key, $message, $expected]) {
        oaSql($root, 'CALL oa_hmac(?,?,@oa_tag)', [$key, $message])->closeCursor();
        oaDemand(oaSql($root, 'SELECT LOWER(HEX(@oa_tag))')->fetchColumn() === $expected, 'OA SQL RFC vector failed');
    }
    oaRecord('sql_hmac_vectors');
    $connection = (int) oaSql($accounting, 'SELECT CONNECTION_ID()')->fetchColumn();
    $ticket = $web->ticket($connection);
    oaSql($accounting, 'START TRANSACTION');
    try {
        $result = oaFinalize($accounting, $ticket);
        oaDemand(oaCounts($observer, $ticket['command_uuid']) === [0, 0, 0], 'OA uncommitted state leaked');
        oaRecord('first_provisional');
        oaExpect(fn () => oaFinalize($accounting, $ticket), '45012', [60053]);
        oaSql($accounting, 'COMMIT');
        oaDemand(oaCounts($observer, $ticket['command_uuid']) === [1, 1, 1] && oaSql($observer, 'SELECT result_uuid FROM proof_results WHERE command_uuid=?', [$ticket['command_uuid']])->fetchColumn() === $result['result_uuid'], 'OA repeat damaged original pending result');
        oaRecord('same_transaction_replay');
        oaSql($accounting, 'START TRANSACTION');
        oaExpect(fn () => oaFinalize($accounting, $ticket), '45012', [60053]);
        oaSql($accounting, 'COMMIT');
        oaDemand(oaCounts($observer, $ticket['command_uuid']) === [1, 1, 1], 'OA replay changed committed result');
        oaRecord('committed_replay');
    } finally {
        oaRestore($accounting);
        oaDeleteCommand($root, $ticket['command_uuid']);
    }
    $ticket = $web->ticket($connection);
    oaSql($accounting, 'START TRANSACTION');
    oaFinalize($accounting, $ticket);
    oaSql($accounting, 'ROLLBACK');
    oaDemand(oaCounts($observer, $ticket['command_uuid']) === [0, 0, 0], 'OA rollback retained consumption');
    try {
        oaSql($accounting, 'START TRANSACTION');
        oaFinalize($accounting, $ticket);
        oaSql($accounting, 'COMMIT');
        oaDemand(oaCounts($observer, $ticket['command_uuid']) === [1, 1, 1], 'OA rollback retry did not commit once');
        oaRecord('rollback_retry');
    } finally {
        oaRestore($accounting);
        oaDeleteCommand($root, $ticket['command_uuid']);
    }
    foreach (['COMMIT' => 'concurrent_commit', 'ROLLBACK' => 'concurrent_rollback'] as $release => $case) {
        $worker = oaStartWorker();
        $uuid = oaUuid();
        try {
            $first = $web->ticket($connection, 1, $uuid);
            $second = $web->ticket($worker['id'], 1, $uuid);
            oaSql($accounting, 'START TRANSACTION');
            oaFinalize($accounting, $first);
            oaSendWorker($worker, ['action' => 'begin']);
            oaDemand((oaReadWorker($worker)['ok'] ?? false) === true, 'OA concurrent BEGIN failed');
            oaSendWorker($worker, ['action' => 'finalize', 'ticket' => $second]);
            oaWaitLock($observer, $worker['id'], $connection, 'proof_commands');
            oaSql($accounting, $release);
            $event = oaReadWorker($worker);
            oaDemand($release === 'COMMIT' ? ($event['ok'] === false && $event['sqlstate'] === '45012' && $event['errno'] === 60053) : $event['ok'] === true, 'OA concurrent outcome incorrect');
            oaSendWorker($worker, ['action' => 'commit']);
            oaDemand((oaReadWorker($worker)['ok'] ?? false) === true, 'OA concurrent COMMIT failed');
            oaDemand(oaCounts($observer, $uuid) === [1, 1, 1], 'OA concurrent claim duplicated effect');
            oaCloseWorker($worker);
            oaRecord($case);
        } finally {
            oaRestore($accounting);
            oaStopWorker($worker);
            oaDeleteCommand($root, $uuid);
        }
    }
    $fresh = fn (int $intent = 1) => $web->ticket($connection, $intent);
    $other = oaConnect('ACCOUNTING');
    try {
        oaTryTicket($other, $observer, $fresh(), '45010', [60051]);
    } finally {
        oaRestore($other);
    }
    oaRecord('wrong_connection');
    $ticket = $fresh();
    $ticket['owner_id'] = '2';
    oaTryTicket($accounting, $observer, $ticket, '45010', [60051]);
    // Authorized owner 2 cannot reuse owner 1's globally consumed command UUID.
    $first = $fresh();
    oaSql($accounting, 'START TRANSACTION');
    oaFinalize($accounting, $first);
    oaSql($accounting, 'COMMIT');
    $secondWeb = new OaLaravelFixture($keys);
    try {
        $secondWeb->login(2);
        $second = $secondWeb->ticket($connection, 2, $first['command_uuid']);
        oaSql($accounting, 'START TRANSACTION');
        oaExpect(fn () => oaFinalize($accounting, $second), '45012', [60053]);
        oaSql($accounting, 'COMMIT');
        oaDemand(oaCounts($observer, $first['command_uuid']) === [1, 1, 1], 'OA owner collision changed result');
    } finally {
        $secondWeb->close();
        oaRestore($accounting);
        oaDeleteCommand($root, $first['command_uuid']);
    }
    oaRecord('different_owner');
    foreach (OA_FIELDS as $field) {
        if ($field === 'target_connection_id') {
            continue;
        }
        $ticket = $fresh();
        $ticket[$field] = match ($field) {
            'command_uuid' => oaUuid(), 'key_id' => 'K2', 'amount' => '11.00', 'direction' => 'expense', 'currency' => 'EUR', 'accounting_date' => '2026-01-03',
            'issued_at', 'expires_at' => (string) ((int) $ticket[$field] - 1),
            'owner_id', 'account_id', 'intent_id', 'account_revision', 'intent_revision' => '2',
            default => 'modified',
        };
        oaTryTicket($accounting, $observer, $ticket, '45010', [60051]);
    }
    oaRecord('modified_fields');
    $ticket = $fresh();
    oaSql($accounting, 'UPDATE proof_intents SET amount=11.00, revision=2 WHERE id=1');
    try {
        oaTryTicket($accounting, $observer, $ticket, '45010', [60051]);
    } finally {
        oaSql($root, 'UPDATE proof_intents SET amount=10.00, revision=1 WHERE id=1');
    }
    oaRecord('changed_facts');
    foreach (['expired', 'future_issued', 'max_lifetime'] as $case) {
        $ticket = $fresh();
        $now = (int) $ticket['issued_at'];
        [$issued, $expires] = match ($case) {
            'expired' => [$now - 60000000, $now - 1], 'future_issued' => [$now + 60000000, $now + 120000000], default => [$now, $now + 60000001],
        };
        $ticket['issued_at'] = (string) $issued;
        $ticket['expires_at'] = (string) $expires;
        oaTryTicket($accounting, $observer, oaRetag($ticket, $keys['K1']), '45011', [60052]);
        oaRecord($case);
    }
    $ticket = $fresh();
    $ticket['key_id'] = 'unknown';
    oaTryTicket($accounting, $observer, oaRetag($ticket, random_bytes(32)), '45010', [60051]);
    oaRecord('unknown_key');
    $ticket = $fresh();
    oaSql($root, "UPDATE proof_keys SET status='revoked' WHERE key_id='K1'");
    try {
        oaTryTicket($accounting, $observer, $ticket, '45010', [60051]);
    } finally {
        oaSql($root, "UPDATE proof_keys SET status='active' WHERE key_id='K1'");
    }
    oaRecord('revoked_key');
    $old = $fresh();
    $cutover = oaClock($auth);
    oaSql($root, "UPDATE proof_keys SET status='verify_only', sign_until_us=?, verify_until_us=? WHERE key_id='K1'", [$cutover, $cutover + 60000000]);
    $web->signingStates['K1'] = 'verify_only';
    try {
        oaExpectSigningKeyInactive(fn () => $web->ticket($connection));
        oaSql($accounting, 'START TRANSACTION');
        oaFinalize($accounting, $old);
        oaSql($accounting, 'COMMIT');
        $web->activeKey = 'K2';
        $new = $web->ticket($connection, 1, $old['command_uuid']);
        oaDemand($new['key_id'] === 'K2', 'OA issuer did not rotate');
        oaSql($accounting, 'START TRANSACTION');
        oaExpect(fn () => oaFinalize($accounting, $new), '45012', [60053]);
        oaSql($accounting, 'COMMIT');
        oaDemand(oaCounts($observer, $old['command_uuid']) === [1, 1, 1], 'OA rotation reset consumption');
        oaRecord('rotated_key');
        oaDeleteCommand($root, $old['command_uuid']);
        $tooNew = $web->ticket($connection);
        $tooNew['key_id'] = 'K1';
        oaTryTicket($accounting, $observer, oaRetag($tooNew, $keys['K1']), '45011', [60052]);
        oaSql($root, "UPDATE proof_keys SET verify_until_us=? WHERE key_id='K1'", [oaClock($auth) - 1]);
        oaTryTicket($accounting, $observer, $old, '45011', [60052]);
        oaRecord('verification_only');
    } finally {
        oaRestore($accounting);
        $web->activeKey = 'K1';
        $web->signingStates['K1'] = 'active';
        oaSql($root, "UPDATE proof_keys SET status='active', sign_until_us=9223372036854775807, verify_until_us=9223372036854775807 WHERE key_id='K1'");
    }
    foreach ([3 => 'native_after_claim', 4 => 'native_after_effect'] as $intent => $case) {
        $collision = oaUuid();
        oaSql($root, 'INSERT INTO proof_commands VALUES(?,1,?)', [$collision, random_bytes(32)]);
        oaSql($root, 'INSERT INTO proof_effects VALUES(?,?,1,10.00)', [$collision, $intent === 3 ? 3001 : 9004]);
        if ($intent === 4) {
            oaSql($root, 'INSERT INTO proof_results VALUES(?,?,4001)', [$collision, oaUuid()]);
        }
        $ticket = $fresh($intent);
        try {
            oaSql($accounting, 'START TRANSACTION');
            oaSql($accounting, 'INSERT INTO proof_caller_writes VALUES(?)', [$intent]);
            oaExpect(fn () => oaFinalize($accounting, $ticket), '23000', [1062]);
            oaSql($accounting, 'COMMIT');
            oaDemand(oaCounts($observer, $ticket['command_uuid']) === [0, 0, 0], 'OA native failure retained partial protected state');
            oaDemand((int) oaSql($observer, 'SELECT COUNT(*) FROM proof_caller_writes WHERE id=?', [$intent])->fetchColumn() === 1, 'OA caller work lost on local failure');
            oaRecord($case, ['sqlstate' => '23000', 'errno' => 1062]);
        } finally {
            oaRestore($accounting);
            oaDeleteCommand($root, $collision);
        }
    }
    oaRecord('caller_preserved');
    $ticket = $fresh();
    oaExpect(fn () => oaFinalize($accounting, $ticket), '45013', [60054]);
    foreach (['foreign_key_checks', 'unique_checks', 'both'] as $flag) {
        try {
            if ($flag === 'both') {
                oaSql($accounting, 'SET SESSION foreign_key_checks=0');
                oaSql($accounting, 'SET SESSION unique_checks=0');
            } else {
                oaSql($accounting, 'SET SESSION '.$flag.'=0');
            }
            oaTryTicket($accounting, $observer, $ticket, '45013', [60054]);
        } finally {
            oaRestore($accounting);
        }
    }
    try {
        oaSql($accounting, 'SET SESSION autocommit=0');
        oaExpect(fn () => oaFinalize($accounting, $ticket), '45013', [60054]);
    } finally {
        oaRestore($accounting);
    }
    oaRecord('finalizer_prerequisites');
    oaExpect(fn () => oaSql($accounting, 'SELECT secret FROM proof_keys'), '42000', [1142]);
    oaExpect(fn () => oaSql($accounting, 'CALL oa_hmac(?,?,@oa_tag)', ['placeholder', 'placeholder']), '42000', [1370]);
    oaRecord('key_denials');
}

function oaDeleteCommand(PDO $root, string $uuid): void
{
    foreach (['proof_results', 'proof_effects', 'proof_commands'] as $table) {
        oaSql($root, 'DELETE FROM '.oaIdentifier($table).' WHERE command_uuid=?', [$uuid], 'CLEANUP');
    }
}

function oaVectors(): array
{
    return [
        [str_repeat("\x0b", 20), 'Hi There', 'b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7'],
        ['Jefe', 'what do ya want for nothing?', '5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843'],
        [str_repeat("\xaa", 20), str_repeat("\xdd", 50), '773ea91e36800e46854db8ebd09181a72959098b3ef8c122d9635514ced565fe'],
        [str_repeat("\xaa", 131), 'Test Using Larger Than Block-Size Key - Hash Key First', '60e431591ee0b67f0d8a26aacbf5b77f8e0bc6213728c5140546040f0ee37f54'],
    ];
}

function oaCases(): array
{
    return json_decode(file_get_contents(__DIR__.'/d3a1-owner-authority-cases.json'), true, 32, JSON_THROW_ON_ERROR);
}

function oaRun(string $mode): void
{
    global $oaObserved;
    $oaObserved = [];
    $root = oaConnect('INSTALLER');
    $auth = oaConnect('AUTH');
    $accounting = oaConnect('ACCOUNTING');
    $observer = oaConnect('OBSERVER');
    oaDemand(oaSql($observer, 'SELECT version FROM proof_manifest WHERE id=1')->fetchColumn() === oaFixtureFingerprint(), 'OA installation manifest mismatch');
    oaDemand((int) oaSql($observer, 'SELECT COUNT(*) FROM performance_schema.data_locks WHERE OBJECT_SCHEMA=?', [OA_SCHEMA])->fetchColumn() === 0, 'OA pre-existing proof locks; manual review required');
    oaDemand((int) oaSql($observer, 'SELECT COUNT(*) FROM proof_commands')->fetchColumn() === 0, 'OA pre-existing consumption; manual review required');
    $groups = $mode === '--owner-authority-proof' ? ['auth', 'lock', 'replay'] : [match ($mode) {
        '--owner-authority-auth-proof' => 'auth', '--owner-authority-lock-proof' => 'lock', default => 'replay'
    }];
    $required = array_merge(...array_map(fn ($group) => oaCases()['groups'][$group], $groups));
    $cleanupFailed = false;
    $complete = false;
    $web = null;
    try {
        $keys = oaSeed($root);
        if (in_array('auth', $groups, true) || in_array('replay', $groups, true)) {
            require_once __DIR__.'/d3a1-owner-authority-laravel.php';
            $web = new OaLaravelFixture($keys);
        }
        if (in_array('auth', $groups, true)) {
            oaAuthProof($root, $accounting, $web);
        } elseif (in_array('replay', $groups, true)) {
            $web->login();
        }
        if (in_array('lock', $groups, true)) {
            oaLockProof($accounting, $observer);
        }
        if (in_array('replay', $groups, true)) {
            oaReplayProof($root, $auth, $accounting, $observer, $web, $keys);
        }
        oaDemand(array_diff($required, array_keys($oaObserved)) === [] && count($required) === count($oaObserved), 'OA incomplete case observations');
        $complete = true;
    } finally {
        foreach ([$accounting, $auth, $observer] as $connection) {
            try {
                oaRestore($connection);
            } catch (Throwable $error) {
                $cleanupFailed = true;
            }
        }
        try {
            $deadline = hrtime(true) + 10000000000;
            while ((int) oaSql($observer, 'SELECT COUNT(*) FROM performance_schema.data_locks WHERE OBJECT_SCHEMA=?', [OA_SCHEMA])->fetchColumn() !== 0) {
                oaDemand(hrtime(true) < $deadline, 'OA locks remain during cleanup');
            }
            foreach (['proof_results', 'proof_effects', 'proof_commands', 'proof_caller_writes', 'sessions', 'cache', 'jobs', 'failed_jobs'] as $table) {
                oaSql($root, 'DELETE FROM '.oaIdentifier($table), [], 'CLEANUP');
                oaDemand((int) oaSql($root, 'SELECT COUNT(*) FROM '.oaIdentifier($table))->fetchColumn() === 0, 'OA probe rows remain');
            }
            oaSql($root, 'UPDATE proof_intents SET amount=10.00, revision=1');
            oaSql($root, "UPDATE proof_keys SET status='active', sign_until_us=9223372036854775807, verify_until_us=9223372036854775807");
            $web?->close();
        } catch (Throwable $error) {
            $cleanupFailed = true;
        }
        echo json_encode(['requested_groups' => $groups, 'summary' => ['required_cases' => count($required), 'observed_cases' => count($oaObserved), 'unresolved_cases' => count(array_diff($required, array_keys($oaObserved))), 'cleanup_failed' => $cleanupFailed], 'verdict' => $complete && ! $cleanupFailed ? 'TEST_MODEL_PASS' : 'BLOCKED', 'scope' => 'isolated owner-authority model; production closure NOT PROVEN'], JSON_THROW_ON_ERROR)."\n";
        oaDemand(! $cleanupFailed, 'OA cleanup/restoration failed; BLOCKED');
    }
}

function oaSqlStructure(string $sql): void
{
    oaDemand(trim($sql) !== '' && ! str_contains($sql, 'DELIMITER'), 'OA empty/unsupported statement');
    $unquoted = preg_replace('/\'(?:\'\'|\\\\.|[^\'])*\'|`[^`]*`|--[^\n]*/s', '', $sql);
    $depth = 0;
    foreach (str_split($unquoted) as $character) {
        $depth += $character === '(' ? 1 : ($character === ')' ? -1 : 0);
        oaDemand($depth >= 0, 'OA SQL structure closes early');
    }
    oaDemand($depth === 0, 'OA SQL structure unbalanced');
}

function oaSelfTest(): void
{
    require_once __DIR__.'/d3a1-owner-authority-bootstrap-self-test.php';
    oaBootstrapSelfTest();
    oaDemand(PHP_INT_SIZE === 8, 'OA proof requires 64-bit PHP');
    $sample = array_combine(OA_FIELDS, ['d3a1-oa/1', OA_SCHEMA, 'apply-neutral-intent', 'K1', '12345678-1234-1234-1234-123456789abc', '1', '1', '1', '10.00', 'income', '2024-02-29', 'USD', '1', '1', '100000000', '155000000', '42']);
    $encoded = oaEncode($sample);
    oaDemand(str_starts_with($encoded, '9:d3a1-oa/1') && str_ends_with($encoded, '2:42'), 'OA canonical encoding differs');
    $pack = fn (array $values) => implode('', array_map(fn ($v) => strlen($v).':'.$v, $values));
    oaDemand($pack(['ab', 'c']) !== $pack(['a', 'bc']), 'OA ambiguous encoding');
    foreach ([['owner_id', '01'], ['owner_id', '-1'], ['owner_id', '9223372036854775808'], ['owner_id', 1], ['amount', '1.0'], ['amount', '1e2'], ['amount', '0.00'], ['amount', '01.00'], ['amount', '10000000000000000.00'], ['accounting_date', '2023-02-29'], ['accounting_date', '2024-04-31'], ['currency', 'usd'], ['direction', 'debit'], ['command_uuid', strtoupper($sample['command_uuid'])], ['command_uuid', '1234'], ['key_id', 'x:y'], ['protocol_version', '10:d3a1-oa/1'], ['owner_id', null]] as [$field, $value]) {
        $invalid = $sample;
        $invalid[$field] = $value;
        $rejected = false;
        try {
            oaEncode($invalid);
        } catch (OaFailure $error) {
            $rejected = true;
        }
        oaDemand($rejected, 'OA invalid typed value accepted: '.$field);
    }
    $reordered = array_reverse($sample, true);
    try {
        oaEncode($reordered);
        throw new LogicException('OA reordered fields accepted');
    } catch (OaFailure $expected) {
        // Expected rejection before packing.
    }
    foreach ([[100, 200, 100, true], [100, 200, 199, true], [100, 200, 200, false], [101, 200, 100, false], [100, 100, 100, false], [100, 60000100, 100, true], [100, 60000101, 100, false]] as [$issued, $expires, $now, $expected]) {
        oaDemand(oaFresh($issued, $expires, $now) === $expected, 'OA freshness boundary mismatch');
    }
    foreach (oaVectors() as [$key, $message, $expected]) {
        oaDemand(hash_hmac('sha256', $message, $key) === $expected, 'OA RFC HMAC vector mismatch');
    }
    foreach (['key`', 'schema.table', 'id;drop', '1id', str_repeat('a', 65)] as $identifier) {
        $rejected = false;
        try {
            oaIdentifier($identifier);
        } catch (OaFailure $error) {
            $rejected = true;
        }
        oaDemand($rejected, 'OA unsafe identifier accepted');
    }
    $cases = oaCases();
    $all = array_merge(...array_values($cases['groups']));
    oaDemand($cases['schema'] === OA_SCHEMA && $cases['server_uuid'] === OA_UUID && $cases['expected_cases'] === 39 && count($all) === 39 && count(array_unique($all)) === 39, 'OA case catalogue mismatch');
    oaDemand(array_map('count', $cases['groups']) === ['auth' => 11, 'lock' => 4, 'replay' => 24], 'OA group counts changed');
    $source = file_get_contents(__FILE__);
    foreach ($all as $case) {
        oaDemand(str_contains($source, "'".$case."'"), 'OA case lacks implementation');
    }
    $statements = oaInstallerStatements();
    oaDemand(count($statements) === 19, 'OA installer statement count changed');
    oaDemand(array_column(array_slice($statements, 0, 13), 'object') === OA_TABLES, 'OA table manifest differs');
    oaDemand(array_column(array_slice($statements, 13, 5), 'object') === OA_ROUTINES && $statements[18]['object'] === 'oa_intent_guard', 'OA routine/trigger manifest differs');
    foreach ($statements as $entry) {
        oaSqlStructure($entry['sql']);
        oaDemand(str_starts_with($entry['sql'], 'CREATE ') && ! preg_match('/\b(DROP|COMMIT|START TRANSACTION)\b/', $entry['sql']), 'OA installer contains destructive/transaction-ending SQL');
        if ($entry['phase'] !== 'CREATE_TABLE') {
            oaDemand(str_ends_with($entry['sql'], 'END') && str_contains($entry['sql'], 'BEGIN'), 'OA compound routine structure differs');
        }
    }
    $grants = oaGrantCatalogue();
    oaDemand(count($grants) === 43 && count(array_unique($grants)) === 43, 'OA grant count differs');
    oaDemand(hash('sha256', oaGrantText()) === 'd9e5f1d7f069097abfd7502a681fabb05b897e61df4a19c9213bce66c7c068b9', 'OA approved grant catalogue changed');
    oaDemand(file_get_contents(__DIR__.'/d3a1-owner-authority-grants.sql') === oaGrantText(), 'OA grants file is stale');
    foreach ($grants as $grant) {
        oaSqlStructure($grant);
        oaDemand(! str_contains($grant, 'ALL') && ! str_contains($grant, '.*') && ! str_contains($grant, 'GRANT OPTION'), 'OA overly broad grant');
        if (str_contains($grant, "'d3a1_oa_accounting'")) {
            oaDemand(! preg_match('/`(password|remember_token|email|sessions|cache|jobs|failed_jobs|proof_keys)`/', $grant), 'OA accounting trusts auth inputs');
        }
    }
    oaDemand(count(oaDenialStatements()) === 37, 'OA denial catalogue count changed');
    foreach (oaDenialStatements() as $statement) {
        oaSqlStructure($statement);
    }
    oaDemand(count(OA_FIELDS) === 17 && substr_count(oaFinalizeSql(), '?') === 18, 'OA CALL parameter count changed');
    oaSqlStructure(oaFinalizeSql());
    $diagnostic = json_encode(oaDiagnostic('INSERT INTO proof_keys VALUES(?,?,?,?,?)', 'SEED', ['23000', 1062, 'SECRET_SENTINEL']), JSON_THROW_ON_ERROR);
    oaDemand(! str_contains($diagnostic, 'SECRET_SENTINEL') && ! str_contains($diagnostic, 'password=') && str_contains($diagnostic, '23000') && str_contains($diagnostic, '1062'), 'OA unsafe diagnostics');
    echo "PASS: OA pure self-test: 39 cases, 19 installer statements, 43 exact grants, 37 DML/DDL denial templates plus 3 private-routine denials; encoding/types/freshness/RFC vectors/diagnostics (no PDO or live proof)\n";
}

function oaMain(string $mode): void
{
    if ($mode !== '--self-test') {
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        ini_set('zend.exception_ignore_args', '1');
    }
    try {
        if ($mode === '--self-test') {
            oaSelfTest();
        } elseif ($mode === '--oa-worker') {
            oaWorker();
        } elseif ($mode === '--bootstrap-owner-authority') {
            oaSelfTest();
            oaBootstrap();
            echo "READY: new isolated authority schema/identities only; install objects next; no grants or live proof\n";
        } elseif ($mode === '--install-owner-authority') {
            oaSelfTest();
            oaInstall();
        } elseif (in_array($mode, ['--owner-authority-proof', '--owner-authority-auth-proof', '--owner-authority-replay-proof', '--owner-authority-lock-proof'], true)) {
            oaSelfTest();
            oaRun($mode);
        } else {
            throw new OaFailure('OA explicit supported mode required');
        }
    } catch (OaFailure $error) {
        throw $error;
    } catch (Throwable $error) {
        // Framework/driver/JSON exception text may contain secrets.
        throw new OaFailure('OA unexpected '.get_class($error).'; details withheld');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        oaDemand(count($_SERVER['argv']) === 2, 'OA exactly one CLI mode required');
        oaMain($_SERVER['argv'][1]);
    } catch (OaFailure $error) {
        fwrite(STDERR, 'BLOCKED: '.$error->getMessage()."\n");
        exit(1);
    }
}
