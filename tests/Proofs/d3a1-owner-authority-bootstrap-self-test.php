<?php

// Pure doubles: constructors deliberately never initialize a PDO connection.
class OaBootstrapTestPDO extends PDO
{
    public array $queries = [];

    public array $server = [OA_UUID, '8.4.11', 'root@localhost', null, 'NONE', 'SYSTEM', 1, 1, 1];

    public int $schemas = 0;

    public int $accounts = 0;

    public ?int $failAtWrite = null;

    public int $writes = 0;

    public array $quotedInputs = [];

    // Opaque canned driver result: the double does not implement SQL escaping.
    public string|false $quotedPassword = "'PRIVATE_SENTINEL_DRIVER_LITERAL'";

    public bool $failQuote = false;

    public function __construct() {}

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $statement = new OaBootstrapTestStatement($this, $query);
        $statement->execute();

        return $statement;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        oaDemand(! preg_match('/^CREATE USER .* IDENTIFIED BY /', $query), 'OA runtime passwords cannot use native prepares');

        return new OaBootstrapTestStatement($this, $query);
    }

    public function quote(#[SensitiveParameter] string $string, int $type = PDO::PARAM_STR): string|false
    {
        $this->quotedInputs[] = [$string, $type];
        if ($this->failQuote) {
            throw new PDOException('PRIVATE_SENTINEL: '.$string);
        }

        return $this->quotedPassword;
    }

    public function run(string $sql, #[SensitiveParameter] array $parameters): mixed
    {
        $runtime = str_starts_with($sql, 'CREATE USER ') && str_contains($sql, ' IDENTIFIED BY ');
        if ($runtime) {
            oaDemand(! str_contains($sql, 'IDENTIFIED BY ?'), 'OA invalid CREATE USER parameter marker');
            oaDemand(is_string($this->quotedPassword) && preg_match("/^CREATE USER 'd3a1_oa_(auth|accounting|observer)'@'%' IDENTIFIED BY /", $sql) === 1
                && str_ends_with($sql, ' IDENTIFIED BY '.$this->quotedPassword) && $parameters === [], 'OA runtime SQL must use the opaque driver-quoted literal');
        }
        $this->queries[] = $runtime ? strstr($sql, ' IDENTIFIED BY ', true).' IDENTIFIED BY [DRIVER_QUOTED_PASSWORD]' : $sql;
        if (str_starts_with($sql, 'SELECT @@server_uuid')) {
            return $this->server;
        }
        if (str_starts_with($sql, 'SELECT COUNT(*) FROM information_schema.SCHEMATA')) {
            oaDemand($parameters === [OA_SCHEMA], 'OA bootstrap preflight schema changed');

            return $this->schemas;
        }
        if (str_starts_with($sql, 'SELECT COUNT(*) FROM mysql.user')) {
            oaDemand($parameters === oaBootstrapIdentities() && ! str_contains($sql, 'Host='), 'OA bootstrap identity preflight must cover every host');

            return $this->accounts;
        }
        oaDemand(str_starts_with($sql, 'CREATE '), 'OA unexpected bootstrap SQL');
        $this->writes++;
        if ($this->writes === $this->failAtWrite) {
            $error = new PDOException('PRIVATE_SENTINEL: '.$sql);
            $error->errorInfo = ['HY000', 1396, 'PRIVATE_SENTINEL: '.$sql];
            throw $error;
        }
        if (str_starts_with($sql, 'CREATE DATABASE')) {
            $this->schemas++;
        } else {
            $this->accounts++;
            oaDemand($parameters === [], 'OA identity DDL must use the text protocol');
        }

        return null;
    }
}

class OaBootstrapTestStatement extends PDOStatement
{
    private mixed $result = null;

    public function __construct(private OaBootstrapTestPDO $connection, private string $sql) {}

    public function execute(?array $params = null): bool
    {
        $this->result = $this->connection->run($this->sql, $params ?? []);

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->result;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->result;
    }

    public function closeCursor(): bool
    {
        return true;
    }
}

function oaBootstrapExpectFailure(callable $action, string $expected): void
{
    try {
        $action();
    } catch (OaFailure $error) {
        oaDemand(str_contains($error->getMessage(), $expected), 'OA bootstrap failure classification changed');
        oaDemand(! str_contains($error->getMessage(), 'PRIVATE_SENTINEL'), 'OA bootstrap diagnostic leaked private data');

        return;
    }
    throw new LogicException('OA bootstrap expected rejection did not occur');
}

function oaBootstrapSelfTest(): void
{
    $values = ['D3A1_ISOLATED_TEST' => 'budget-phase23-mysql-test', 'D3A1_SERVER_UUID' => OA_UUID, 'D3A1_OA_DSN' => OA_DSN];
    foreach (['INSTALLER' => 'root', 'AUTH' => 'd3a1_oa_auth', 'ACCOUNTING' => 'd3a1_oa_accounting', 'OBSERVER' => 'd3a1_oa_observer'] as $role => $user) {
        $values['D3A1_OA_'.$role.'_USER'] = $user;
        $values['D3A1_OA_'.$role.'_PASSWORD'] = str_repeat('x', 32);
    }
    $environment = fn ($name) => $values[$name] ?? false;
    oaDemand(oaDsn('SERVER') === 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4' && ! str_contains(oaDsn('SERVER'), 'dbname'), 'OA server DSN selects a database');
    oaDemand(oaDsn('SCHEMA') === 'mysql:host=127.0.0.1;port=3306;dbname=phase3_d3a1_authority;charset=utf8mb4', 'OA schema DSN changed');
    oaBootstrapExpectFailure(fn () => oaDsn('OTHER'), 'invalid connection scope');
    foreach (['--install-owner-authority', '--owner-authority-auth-proof', '--owner-authority-lock-proof', '--owner-authority-replay-proof', '--owner-authority-proof', 'PROOF'] as $mode) {
        foreach (['INSTALLER', 'AUTH', 'ACCOUNTING', 'OBSERVER'] as $role) {
            $settings = oaConnectionSettings($role, 'SCHEMA', $mode, $environment);
            oaDemand($settings['dsn'] === OA_DSN, 'OA installer/proof escaped schema scope');
            oaBootstrapExpectFailure(fn () => oaConnectionSettings($role, 'SERVER', $mode, $environment), 'bootstrap-only');
        }
    }
    foreach (['AUTH', 'ACCOUNTING', 'OBSERVER'] as $role) {
        oaBootstrapExpectFailure(fn () => oaConnectionSettings($role, 'SERVER', '--bootstrap-owner-authority', $environment), 'bootstrap-only');
    }
    foreach (['INSTALLER', 'AUTH', 'ACCOUNTING', 'OBSERVER'] as $role) {
        $schema = new OaBootstrapTestPDO;
        $schema->server[2] = $environment('D3A1_OA_'.$role.'_USER').'@%';
        $schema->server[3] = OA_SCHEMA;
        $schemaConnector = function ($dsn) use ($schema) {
            oaDemand($dsn === OA_DSN, 'OA proof connection attempted server scope');

            return $schema;
        };
        oaDemand(oaOpenConnection($role, 'SCHEMA', 'PROOF', $schemaConnector, $environment) === $schema, 'OA schema connection rejected');
        foreach ([null, 'another_schema'] as $database) {
            $schema->server[3] = $database;
            oaBootstrapExpectFailure(fn () => oaOpenConnection($role, 'SCHEMA', 'PROOF', $schemaConnector, $environment), 'scope mismatch');
        }
        oaDemand($schema->writes === 0, 'OA proof connection provisioned state');
    }

    $pdo = new OaBootstrapTestPDO;
    $connections = [];
    $connector = function ($dsn, $username, #[SensitiveParameter] $password, $options) use ($pdo, &$connections) {
        oaDemand($username === 'root' && $password === str_repeat('x', 32), 'OA bootstrap credential routing changed');
        oaDemand($options === [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5], 'OA shared PDO options changed');
        $connections[] = $dsn;

        return $pdo;
    };
    oaBootstrap($connector, $environment);
    oaDemand($connections === [oaDsn('SERVER')], 'OA bootstrap attempted a schema connection');
    oaDemand(count($pdo->queries) === 9 && str_starts_with($pdo->queries[0], 'SELECT @@server_uuid'), 'OA identity verification must precede bootstrap');
    oaDemand(str_starts_with($pdo->queries[1], 'SELECT COUNT(*) FROM information_schema.SCHEMATA') && str_starts_with($pdo->queries[2], 'SELECT COUNT(*) FROM mysql.user'), 'OA all preflight checks must precede DDL');
    oaDemand(array_slice($pdo->queries, 3) === [
        'CREATE DATABASE `phase3_d3a1_authority`',
        "CREATE USER 'd3a1_oa_auth'@'%' IDENTIFIED BY [DRIVER_QUOTED_PASSWORD]",
        "CREATE USER 'd3a1_oa_accounting'@'%' IDENTIFIED BY [DRIVER_QUOTED_PASSWORD]",
        "CREATE USER 'd3a1_oa_observer'@'%' IDENTIFIED BY [DRIVER_QUOTED_PASSWORD]",
        "CREATE USER 'd3a1_oa_verify'@'%' ACCOUNT LOCK",
        "CREATE USER 'd3a1_oa_lock'@'%' ACCOUNT LOCK",
    ], 'OA bootstrap order/scope changed');
    oaDemand($pdo->quotedInputs === array_fill(0, 3, [str_repeat('x', 32), PDO::PARAM_STR]), 'OA runtime passwords must each pass through driver quoting');
    oaDemand($pdo->schemas === 1 && $pdo->accounts === 5, 'OA bootstrap inventory changed');
    $writes = $pdo->writes;
    oaBootstrapExpectFailure(fn () => oaBootstrap($connector, $environment), 'existing/partial');
    oaDemand($pdo->writes === $writes, 'OA rerun modified existing state');

    foreach ([[0, 'wrong-uuid', 'server identity'], [1, '8.4.7', 'server identity'], [1, '8.4.110', 'server identity'], [2, 'other@localhost', 'principal'], [3, OA_SCHEMA, 'scope mismatch'], [6, 0, 'prerequisites'], [7, 0, 'prerequisites'], [8, 0, 'prerequisites']] as [$index, $value, $expected]) {
        $bad = new OaBootstrapTestPDO;
        $bad->server[$index] = $value;
        oaBootstrapExpectFailure(fn () => oaBootstrap(fn () => $bad, $environment), $expected);
        oaDemand($bad->writes === 0 && count($bad->queries) === 1, 'OA wrong server/session reached provisioning');
    }
    foreach (['D3A1_ISOLATED_TEST' => 'acknowledgement', 'D3A1_SERVER_UUID' => 'pinned UUID', 'D3A1_OA_DSN' => 'pinned UUID', 'D3A1_OA_INSTALLER_USER' => 'identity mismatch', 'D3A1_OA_AUTH_PASSWORD' => 'secret missing'] as $name => $expected) {
        $bad = new OaBootstrapTestPDO;
        $badEnvironment = fn ($key) => $key === $name ? false : $environment($key);
        oaBootstrapExpectFailure(fn () => oaBootstrap(fn () => $bad, $badEnvironment), $expected);
        oaDemand($bad->queries === [], 'OA invalid environment reached SQL');
    }
    foreach ([[1, 0], [0, 1], [0, 5], [1, 3], [1, 5]] as [$schemas, $accounts]) {
        $partial = new OaBootstrapTestPDO;
        $partial->schemas = $schemas;
        $partial->accounts = $accounts;
        oaBootstrapExpectFailure(fn () => oaBootstrap(fn () => $partial, $environment), 'existing/partial');
        oaDemand($partial->writes === 0, 'OA partial state was modified');
    }
    $partial = new OaBootstrapTestPDO;
    $partial->failAtWrite = 3;
    oaBootstrapExpectFailure(fn () => oaBootstrap(fn () => $partial, $environment), 'BOOTSTRAP_RUNTIME_IDENTITY');
    oaDemand($partial->schemas === 1 && $partial->accounts === 1, 'OA failed bootstrap silently repaired partial state');
    $writes = $partial->writes;
    oaBootstrapExpectFailure(fn () => oaBootstrap(fn () => $partial, $environment), 'existing/partial');
    oaDemand($partial->writes === $writes, 'OA failed bootstrap rerun issued DDL');

    oaRuntimeIdentitySelfTest();

    foreach ([1049 => 'bootstrap prerequisite missing', 1045 => 'connection failed'] as $errno => $expected) {
        $connector = function () use ($errno) {
            $error = new PDOException('PRIVATE_SENTINEL');
            $error->errorInfo = ['HY000', $errno, 'PRIVATE_SENTINEL'];
            throw $error;
        };
        oaBootstrapExpectFailure(fn () => oaOpenConnection('INSTALLER', 'SCHEMA', 'PROOF', $connector, $environment), $expected);
        $message = oaConnectionFailure('SCHEMA', ['HY000', $errno, 'PRIVATE_SENTINEL']);
        oaDemand(str_contains($message, 'SQLSTATE=HY000') && str_contains($message, 'ERRNO='.$errno), 'OA native connection classification lost');
    }
    oaDemand(! str_contains(oaConnectionFailure('SERVER', ['PRIVATE_SENTINEL', 'PRIVATE_SENTINEL', 'PRIVATE_SENTINEL']), 'PRIVATE_SENTINEL'), 'OA unsafe connection diagnostic fields');
    $identities = array_fill_keys(oaBootstrapIdentities(), 'N');
    $identities['d3a1_oa_verify'] = $identities['d3a1_oa_lock'] = 'Y';
    oaRequireInstallerIdentities($identities);
    foreach (oaBootstrapIdentities() as $name) {
        $missing = $identities;
        unset($missing[$name]);
        oaBootstrapExpectFailure(fn () => oaRequireInstallerIdentities($missing), 'bootstrap prerequisite missing');
        $unlocked = $identities;
        $unlocked[$name] = $identities[$name] === 'Y' ? 'N' : 'Y';
        oaBootstrapExpectFailure(fn () => oaRequireInstallerIdentities($unlocked), 'lock state mismatch');
    }
    echo "PASS: OA bootstrap scopes/order/refusal/identity/prerequisites/diagnostics (pure doubles; no PDO connection)\n";
}

function oaRuntimeIdentitySelfTest(): void
{
    $password = "PRIVATE_SENTINEL'\\\0\n; CREATE USER";
    foreach (['auth', 'accounting', 'observer'] as $role) {
        $pdo = new OaBootstrapTestPDO;
        oaCreateRuntimeIdentity($pdo, $role, $password);
        oaDemand($pdo->quotedInputs === [[$password, PDO::PARAM_STR]] && $pdo->writes === 1, 'OA password must reach the driver without manual escaping');
        oaDemand(! str_contains(implode('\n', $pdo->queries), 'PRIVATE_SENTINEL'), 'OA double recorded a password literal');
        oaBootstrapExpectFailure(fn () => $pdo->prepare("CREATE USER 'd3a1_oa_{$role}'@'%' IDENTIFIED BY ?"), 'cannot use native prepares');
        oaBootstrapExpectFailure(fn () => $pdo->query("CREATE USER 'd3a1_oa_{$role}'@'%' IDENTIFIED BY ?"), 'invalid CREATE USER parameter marker');
    }
    $pdo = new OaBootstrapTestPDO;
    oaBootstrapExpectFailure(fn () => oaCreateRuntimeIdentity($pdo, "auth'@'other", $password), 'invalid runtime identity');
    oaDemand($pdo->queries === [] && $pdo->quotedInputs === [], 'OA invalid role reached the driver');
    foreach (['quote_false', 'quote_exception', 'query_exception'] as $failure) {
        $pdo = new OaBootstrapTestPDO;
        if ($failure === 'quote_false') {
            $pdo->quotedPassword = false;
        } elseif ($failure === 'quote_exception') {
            $pdo->failQuote = true;
        } else {
            $pdo->failAtWrite = 1;
        }
        try {
            oaCreateRuntimeIdentity($pdo, 'auth', $password);
            throw new LogicException('OA secret-bearing failure was accepted');
        } catch (OaFailure $error) {
            oaDemand($error->getMessage() === 'OA BOOTSTRAP_RUNTIME_IDENTITY: CREATE USER failed; details withheld; inspect partial state'
                && $error->getPrevious() === null && ! str_contains((string) $error, 'PRIVATE_SENTINEL'), 'OA secret-bearing failure leaked exception details');
            oaDemand($pdo->writes === ($failure === 'query_exception' ? 1 : 0), 'OA quoting failure reached DDL');
        }
    }
}
