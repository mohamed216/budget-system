<?php

// TEST ONLY. Loaded by the owner-authority harness; no work runs on inclusion.
function oaDsn(string $scope): string
{
    oaDemand(in_array($scope, ['SERVER', 'SCHEMA'], true), 'OA invalid connection scope');
    $parts = ['host' => '127.0.0.1', 'port' => '3306'];
    if ($scope === 'SCHEMA') {
        $parts['dbname'] = OA_SCHEMA;
    }
    $parts['charset'] = 'utf8mb4';

    return 'mysql:'.http_build_query($parts, '', ';', PHP_QUERY_RFC3986);
}

function oaConnectionSettings(string $role, string $scope, string $purpose, callable $environment): array
{
    oaDemand($scope === 'SCHEMA' || ($scope === 'SERVER' && $role === 'INSTALLER' && $purpose === '--bootstrap-owner-authority'), 'OA SERVER scope is installer bootstrap-only');
    oaDemand($environment('D3A1_ISOLATED_TEST') === 'budget-phase23-mysql-test', 'OA isolated acknowledgement required');
    oaDemand($environment('D3A1_SERVER_UUID') === OA_UUID && $environment('D3A1_OA_DSN') === OA_DSN, 'OA pinned UUID/exact isolated DSN required');
    $users = ['AUTH' => 'd3a1_oa_auth', 'ACCOUNTING' => 'd3a1_oa_accounting', 'OBSERVER' => 'd3a1_oa_observer', 'INSTALLER' => 'root'];
    oaDemand(isset($users[$role]) && $environment('D3A1_OA_'.$role.'_USER') === $users[$role], 'OA role identity mismatch');

    return [
        'dsn' => oaDsn($scope), 'username' => $users[$role],
        'password' => $environment('D3A1_OA_'.$role.'_PASSWORD') ?: '',
        'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5],
    ];
}

function oaValidateConnection(array $server, string $role, string $scope, string $username): void
{
    oaDemand($server[0] === OA_UUID && preg_match('/^8\.4\.11(?:[^0-9]|$)/D', $server[1]) === 1, 'OA server identity mismatch');
    oaDemand($server[3] === ($scope === 'SERVER' ? null : OA_SCHEMA), 'OA selected database/scope mismatch');
    oaDemand($role === 'INSTALLER' ? str_starts_with($server[2], 'root@') : $server[2] === $username.'@%' && $server[4] === 'NONE', 'OA effective principal/role mismatch');
    oaDemand(array_map('intval', array_slice($server, 6)) === [1, 1, 1], 'OA fresh session prerequisites unavailable');
}

function oaConnectionFailure(string $scope, array $native): string
{
    $state = is_string($native[0] ?? null) && preg_match('/^[A-Z0-9]{5}$/D', $native[0]) ? $native[0] : 'unknown';
    $errno = is_int($native[1] ?? null) ? $native[1] : 0;
    $reason = $scope === 'SCHEMA' && $errno === 1049
        ? 'bootstrap prerequisite missing: authority schema required; inspect partial state before --bootstrap-owner-authority'
        : 'connection failed';

    return "OA {$scope} {$reason}; SQLSTATE={$state}; ERRNO={$errno}; connection details withheld";
}

/** Connector/environment injection permits pure sequencing tests without PDO connections. */
function oaOpenConnection(string $role, string $scope, string $purpose = 'PROOF', ?callable $connector = null, ?callable $environment = null): PDO
{
    $settings = oaConnectionSettings($role, $scope, $purpose, $environment ?? 'getenv');
    $connector ??= fn ($dsn, $username, #[SensitiveParameter] $password, $options) => new PDO($dsn, $username, $password, $options);
    try {
        $pdo = $connector($settings['dsn'], $settings['username'], $settings['password'], $settings['options']);
    } catch (PDOException $error) {
        throw new OaFailure(oaConnectionFailure($scope, $error->errorInfo ?? []));
    }
    $server = oaSql($pdo, 'SELECT @@server_uuid, VERSION(), CURRENT_USER(), DATABASE(), CURRENT_ROLE(), @@session.time_zone, @@session.autocommit, @@session.foreign_key_checks, @@session.unique_checks', [], 'CONNECTION_IDENTITY')->fetch(PDO::FETCH_NUM);
    oaValidateConnection($server, $role, $scope, $settings['username']);
    global $oaSessionDefaults;
    $oaSessionDefaults ??= new WeakMap;
    $oaSessionDefaults[$pdo] = $server[5];

    return $pdo;
}

function oaBootstrapIdentities(): array
{
    return ['d3a1_oa_auth', 'd3a1_oa_accounting', 'd3a1_oa_observer', 'd3a1_oa_verify', 'd3a1_oa_lock'];
}

function oaRequireInstallerIdentities(array $identities): void
{
    oaDemand(count($identities) === count(oaBootstrapIdentities()), 'OA bootstrap prerequisite missing: all five isolated identities required; inspect partial state before --bootstrap-owner-authority');
    foreach (oaBootstrapIdentities() as $name) {
        $expected = in_array($name, ['d3a1_oa_verify', 'd3a1_oa_lock'], true) ? 'Y' : 'N';
        oaDemand(($identities[$name] ?? null) === $expected, 'OA bootstrap identity/lock state mismatch; manual review required');
    }
}

function oaRequireFreshBootstrap(PDO $root): void
{
    $schemas = oaSql($root, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [OA_SCHEMA], 'BOOTSTRAP_PREFLIGHT')->fetchColumn();
    // Refuse these names at ANY host, not just the intended '%' identities.
    $accounts = oaSql($root, 'SELECT COUNT(*) FROM mysql.user WHERE User IN (?,?,?,?,?)', oaBootstrapIdentities(), 'BOOTSTRAP_PREFLIGHT')->fetchColumn();
    oaDemand((int) $schemas === 0 && (int) $accounts === 0, 'OA bootstrap refuses existing/partial schema or identities; manual review required; nothing adopted or reset');
}

function oaBootstrapPasswords(callable $environment): array
{
    $passwords = [];
    foreach (['AUTH', 'ACCOUNTING', 'OBSERVER'] as $role) {
        oaDemand($environment('D3A1_OA_'.$role.'_USER') === 'd3a1_oa_'.strtolower($role), 'OA bootstrap runtime identity mismatch');
        $password = $environment('D3A1_OA_'.$role.'_PASSWORD');
        oaDemand(is_string($password) && strlen($password) >= 32, 'OA bootstrap runtime secret missing or too short');
        $passwords[strtolower($role)] = $password;
    }

    return $passwords;
}

/** CREATE USER requires a literal; never send secret-bearing SQL through oaSql(). */
function oaCreateRuntimeIdentity(PDO $root, string $role, #[SensitiveParameter] string $password): void
{
    oaDemand(in_array($role, ['auth', 'accounting', 'observer'], true), 'OA invalid runtime identity');
    try {
        $literal = $root->quote($password, PDO::PARAM_STR);
        oaDemand(is_string($literal) && $literal !== '', 'OA password quoting unavailable');
        $statement = $root->query("CREATE USER 'd3a1_oa_{$role}'@'%' IDENTIFIED BY ".$literal);
        oaDemand($statement instanceof PDOStatement, 'OA runtime identity creation failed');
        $statement->closeCursor();
    } catch (Throwable $error) {
        // Even quote()/query() errors can contain the password or complete SQL.
        // Do not retain the original exception, SQL, bindings, or driver message.
        throw new OaFailure('OA BOOTSTRAP_RUNTIME_IDENTITY: CREATE USER failed; details withheld; inspect partial state');
    }
}

/** Explicit provisioning only: no fixture objects, grants, adoption, or repair. */
function oaBootstrap(?callable $connector = null, ?callable $environment = null): void
{
    $environment ??= 'getenv';
    $passwords = oaBootstrapPasswords($environment);
    $root = oaOpenConnection('INSTALLER', 'SERVER', '--bootstrap-owner-authority', $connector, $environment);
    oaRequireFreshBootstrap($root);
    // DDL commits implicitly. Any failure leaves partial state for manual review;
    // reruns refuse it rather than dropping, adopting, or resetting anything.
    oaSql($root, 'CREATE DATABASE '.oaIdentifier(OA_SCHEMA), [], 'BOOTSTRAP_SCHEMA')->closeCursor();
    foreach ($passwords as $role => $password) {
        oaCreateRuntimeIdentity($root, $role, $password);
    }
    foreach (['verify', 'lock'] as $role) {
        oaSql($root, "CREATE USER 'd3a1_oa_{$role}'@'%' ACCOUNT LOCK", [], 'BOOTSTRAP_DEFINER_IDENTITY')->closeCursor();
    }
}
