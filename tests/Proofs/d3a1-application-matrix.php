<?php

// TEST ONLY: projected application relationships, never the application database.
declare(strict_types=1);

function applicationMatrixManifest(): array
{
    return json_decode(file_get_contents(__DIR__.'/d3a1-application-matrix.json'), true, 512, JSON_THROW_ON_ERROR);
}

function applicationMatrixIdentifier(string $identifier): string
{
    demand((bool) preg_match('/^[a-z_][a-z0-9_]{0,63}$/D', $identifier), 'Invalid matrix SQL identifier');

    return '`'.$identifier.'`';
}

function applicationMatrixIdentifierList(array $identifiers): string
{
    demand($identifiers !== [] && count($identifiers) === count(array_unique($identifiers)), 'Empty/duplicate matrix key columns');

    return implode(', ', array_map('applicationMatrixIdentifier', $identifiers));
}

function applicationMatrixValidate(array $manifest): void
{
    demand($manifest['schema'] === 'phase3_d3a1_matrix' && $manifest['baseline_head'] === '438cb8c', 'Unexpected matrix baseline/schema');
    foreach ($manifest['source_sha256'] as $path => $digest) {
        demand(hash_file('sha256', dirname(__DIR__, 2).'/'.$path) === $digest, 'Matrix inventory source changed: '.$path);
    }
    foreach ($manifest['tables'] as $name => $table) {
        applicationMatrixIdentifier($name);
        foreach ($table['columns'] as $column => $type) {
            applicationMatrixIdentifier($column);
            demand(in_array($type, ['BIGINT UNSIGNED NOT NULL', 'BIGINT UNSIGNED NULL', 'VARCHAR(255) NOT NULL'], true), 'Unexpected projected column type');
        }
        demand(array_keys($table['columns']) === array_keys($table['values']), 'Missing projected column defaults');
        applicationMatrixIdentifierList($table['primary_key']);
        foreach ($table['primary_key'] as $column) {
            demand(isset($table['columns'][$column]), 'Unknown primary-key column');
        }
        foreach ($table['references'] as $reference) {
            applicationMatrixIdentifier($reference['table']);
            demand(isset($manifest['tables'][$reference['table']]), 'Unknown referenced table');
            applicationMatrixIdentifierList($reference['columns']);
            applicationMatrixIdentifierList($reference['parent_columns']);
            demand(count($reference['columns']) === count($reference['parent_columns']), 'Mismatched reference arity');
            foreach ($reference['columns'] as $column) {
                demand(isset($table['columns'][$column]), 'Unknown child reference column');
            }
            foreach ($reference['parent_columns'] as $column) {
                demand(isset($manifest['tables'][$reference['table']]['columns'][$column]), 'Unknown parent reference column');
            }
        }
        demand(array_keys($table['classifications']) === ['INSERT', 'UPDATE', 'DELETE'], 'Incomplete operation inventory');
        foreach ($table['classifications'] as $classification) {
            demand(in_array($classification, ['GUARDED_WRITE', 'LOCK_ONLY_UPDATE', 'REMOVE_RUNTIME_PRIVILEGE', 'TRUSTED_ROUTINE_ONLY_LATER'], true), 'Unproven independent classification');
        }
    }
}

function applicationMatrixTotals(array $manifest): array
{
    $totals = ['total_write_paths' => 0, 'privilege_denied_paths' => 0, 'guard_protected_paths' => 0, 'independently_protected_paths' => 0, 'trusted_routine_later_paths' => 0, 'lock_only_update_paths' => 0];
    foreach ($manifest['tables'] as $table) {
        foreach ($table['classifications'] as $classification) {
            $totals['total_write_paths']++;
            if (in_array($classification, ['GUARDED_WRITE', 'LOCK_ONLY_UPDATE'], true)) {
                $totals['guard_protected_paths']++;
                if ($classification === 'LOCK_ONLY_UPDATE') {
                    $totals['lock_only_update_paths']++;
                }
            } else {
                $totals['privilege_denied_paths']++;
                if ($classification === 'TRUSTED_ROUTINE_ONLY_LATER') {
                    $totals['trusted_routine_later_paths']++;
                }
            }
        }
    }

    return $totals;
}

function applicationMatrixRuntimeDiagnostic(array $context, string $sql, PDOException $error): array
{
    // SQL comes only from repository renderers/static templates. Never include
    // execute parameters, driver text, exception traces or connection options.
    return array_intersect_key($context, array_flip(['phase', 'table', 'operation', 'attack_variant', 'checks_state'])) + [
        'sql' => $sql, 'sqlstate' => $error->errorInfo[0] ?? $error->getCode(), 'errno' => $error->errorInfo[1] ?? null,
    ];
}

class ApplicationMatrixPDO extends PDO
{
    private array $matrixContext = ['phase' => 'BASELINE_VERIFY', 'table' => '_connection', 'operation' => 'SELECT', 'attack_variant' => 'connection_preflight'];

    private array $matrixChecks = [null, null];

    public function matrixContext(?array $context = null): array
    {
        if ($context !== null) {
            demand(in_array($context['phase'], ['PRIVILEGE_DENIAL', 'ENABLED_VALID_WRITE', 'ENABLED_NATIVE_CONSTRAINT', 'DISABLED_GUARD_WRITE', 'LOCK_ONLY_READ', 'LOCK_ONLY_MUTATION', 'BASELINE_VERIFY', 'CLEANUP'], true), 'Invalid matrix diagnostic phase');
            applicationMatrixIdentifier($context['table']);
            $this->matrixContext = $context;
        }

        return $this->matrixContext + ['checks_state' => $this->matrixChecks];
    }

    public function matrixChecks(array $checks): void
    {
        $this->matrixChecks = $checks;
    }

    public function matrixFailure(string $sql, PDOException $error, ?array $context = null): void
    {
        $context ??= $this->matrixContext();
        $phase = $context['phase'];
        $expected = match ($phase) {
            'PRIVILEGE_DENIAL' => applicationMatrixDiagnostic('PRIVILEGE_DENIAL', $error),
            'DISABLED_GUARD_WRITE' => applicationMatrixDiagnostic('MATRIX_GUARD', $error),
            'LOCK_ONLY_MUTATION' => applicationMatrixDiagnostic('LOCK_DENIAL', $error),
            'ENABLED_NATIVE_CONSTRAINT' => in_array((int) ($error->errorInfo[1] ?? 0), $context['expected_errnos'] ?? [], true),
            default => false,
        };
        if (! $expected) {
            fwrite(STDERR, 'MATRIX SQL FAILURE: '.json_encode(applicationMatrixRuntimeDiagnostic($context, $sql, $error), JSON_THROW_ON_ERROR)."\n");
        }
    }

    public function exec(string $statement): int|false
    {
        try {
            $result = parent::exec($statement);
            if (preg_match('/^SET SESSION (foreign_key_checks|unique_checks) = ([01])$/D', $statement, $match)) {
                $this->matrixChecks[$match[1] === 'foreign_key_checks' ? 0 : 1] = (int) $match[2];
            }

            return $result;
        } catch (PDOException $error) {
            $this->matrixFailure($statement, $error);
            throw $error;
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        try {
            return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        } catch (PDOException $error) {
            $this->matrixFailure($query, $error);
            throw $error;
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        try {
            return parent::prepare($query, $options);
        } catch (PDOException $error) {
            $this->matrixFailure($query, $error);
            throw $error;
        }
    }

    public function beginTransaction(): bool
    {
        try {
            return parent::beginTransaction();
        } catch (PDOException $error) {
            $this->matrixFailure('START TRANSACTION', $error);
            throw $error;
        }
    }

    public function commit(): bool
    {
        try {
            return parent::commit();
        } catch (PDOException $error) {
            $this->matrixFailure('COMMIT', $error);
            throw $error;
        }
    }

    public function rollBack(): bool
    {
        try {
            return parent::rollBack();
        } catch (PDOException $error) {
            $this->matrixFailure('ROLLBACK', $error);
            throw $error;
        }
    }
}

class ApplicationMatrixPDOStatement extends PDOStatement
{
    private array $matrixContext;

    protected function __construct(private WeakReference $connection)
    {
        $this->matrixContext = $connection->get()->matrixContext();
    }

    public function execute(?array $params = null): bool
    {
        try {
            return parent::execute($params);
        } catch (PDOException $error) {
            $this->connection->get()?->matrixFailure($this->queryString, $error, $this->matrixContext);
            throw $error;
        }
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        try {
            return parent::fetch($mode, $cursorOrientation, $cursorOffset);
        } catch (PDOException $error) {
            $this->connection->get()?->matrixFailure($this->queryString, $error, $this->matrixContext);
            throw $error;
        }
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        try {
            return parent::fetchAll($mode, ...$args);
        } catch (PDOException $error) {
            $this->connection->get()?->matrixFailure($this->queryString, $error, $this->matrixContext);
            throw $error;
        }
    }

    public function fetchColumn(int $column = 0): mixed
    {
        try {
            return parent::fetchColumn($column);
        } catch (PDOException $error) {
            $this->connection->get()?->matrixFailure($this->queryString, $error, $this->matrixContext);
            throw $error;
        }
    }
}

function applicationMatrixContext(PDO $pdo, string $phase, string $table, string $operation, string $variant, array $expectedErrnos = []): void
{
    demand($pdo instanceof ApplicationMatrixPDO, 'Matrix runtime SQL requires diagnostic PDO');
    $pdo->matrixContext(['phase' => $phase, 'table' => $table, 'operation' => $operation, 'attack_variant' => $variant, 'expected_errnos' => $expectedErrnos]);
}

function applicationMatrixConnect(string $role, bool $install = false): PDO
{
    demand(getenv('D3A1_ISOLATED_TEST') === 'budget-phase23-mysql-test', 'Isolated container acknowledgement required');
    demand(getenv('D3A1_DSN') === 'mysql:host=127.0.0.1;port=3306;dbname=phase3_d3a1_matrix;charset=utf8mb4', 'Matrix requires isolated loopback and dedicated schema; port 3307 forbidden');
    $identity = $install ? 'root' : ($role === 'OWNER' ? 'd3a1_matrix_owner' : 'd3a1_matrix_runtime');
    demand(getenv('D3A1_'.$role.'_USER') === $identity, 'Use the dedicated matrix identity (root only for installation)');
    $pdoClass = $install ? PDO::class : ApplicationMatrixPDO::class;
    $pdo = new $pdoClass(getenv('D3A1_DSN'), $identity, getenv('D3A1_'.$role.'_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_FOUND_ROWS => true]);
    if ($pdo instanceof ApplicationMatrixPDO) {
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ApplicationMatrixPDOStatement::class, [WeakReference::create($pdo)]]);
    }
    demand($pdo->query(applicationMatrixFixedSql()['database'][0])->fetchColumn() === 'phase3_d3a1_matrix', 'Wrong matrix database');
    demand(str_starts_with((string) $pdo->query(applicationMatrixFixedSql()['version'][0])->fetchColumn(), '8.4.11'), 'Matrix requires MySQL 8.4.11');
    demand((getenv('D3A1_SERVER_UUID') ?: '') !== '' && $pdo->query(applicationMatrixFixedSql()['server_uuid'][0])->fetchColumn() === getenv('D3A1_SERVER_UUID'), 'Matrix server UUID mismatch');

    return $pdo;
}

function applicationMatrixGranted(array $table): array
{
    return array_merge($table['select_needed'] ? ['SELECT'] : [], array_keys(array_filter($table['classifications'], fn ($classification) => in_array($classification, ['GUARDED_WRITE', 'LOCK_ONLY_UPDATE'], true))));
}

function applicationMatrixVerifyGrants(PDO $runtime, array $manifest): void
{
    $observed = [];
    applicationMatrixContext($runtime, 'BASELINE_VERIFY', '_grants', 'SHOW GRANTS', 'verify_restricted_profile');
    foreach ($runtime->query(applicationMatrixFixedSql()['grants'][0])->fetchAll(PDO::FETCH_COLUMN) as $grant) {
        if (preg_match('/^GRANT USAGE ON \*\.\* TO /', $grant) && ! str_contains($grant, 'WITH GRANT OPTION')) {
            continue;
        }
        demand((bool) preg_match('/^GRANT ([A-Z, ]+) ON `phase3_d3a1_matrix`\.`([a-z_]+)` TO `d3a1_matrix_runtime`@`%`$/D', $grant, $match), 'Runtime has unexpected global/schema/role/DDL/EXECUTE/grant-option privilege');
        demand(isset($manifest['tables'][$match[2]]), 'Runtime has privileges on an unmodeled table');
        $observed[$match[2]] = explode(', ', $match[1]);
    }
    foreach ($manifest['tables'] as $name => $table) {
        $expected = applicationMatrixGranted($table);
        $actual = $observed[$name] ?? [];
        sort($expected);
        sort($actual);
        demand($actual === $expected, 'Runtime grants differ from inventory: '.$name);
    }
}

function applicationMatrixInstallerStatements(array $manifest): array
{
    applicationMatrixValidate($manifest);
    $statements = [];
    $parentKeys = [];
    foreach ($manifest['tables'] as $table) {
        foreach ($table['references'] as $reference) {
            $parentKeys[$reference['table']][implode(',', $reference['parent_columns'])] = $reference['parent_columns'];
        }
    }
    foreach ($manifest['tables'] as $name => $table) {
        $definitions = [];
        foreach ($table['columns'] as $column => $type) {
            $definitions[] = applicationMatrixIdentifier($column).' '.$type;
        }
        $definitions[] = 'PRIMARY KEY ('.applicationMatrixIdentifierList($table['primary_key']).')';
        foreach ($parentKeys[$name] ?? [] as $columns) {
            if ($columns !== $table['primary_key']) {
                $definitions[] = 'UNIQUE ('.applicationMatrixIdentifierList($columns).')';
            }
        }
        if (in_array($name, ['journal_lines', 'opening_balance_lines'], true)) {
            $definitions[] = 'CHECK ((`debit` > 0 AND `credit` = 0) OR (`credit` > 0 AND `debit` = 0))';
        }
        if (in_array($name, ['journal_line_allocations', 'operational_reconciliation_attributions'], true)) {
            $definitions[] = 'CHECK (`amount` > 0)';
        }
        if ($name === 'journal_line_allocations') {
            $definitions[] = 'CHECK (`debit_line_id` <> `credit_line_id`)';
        }
        $statements[] = ['phase' => 'CREATE_TABLE', 'table' => $name, 'sql' => 'CREATE TABLE '.applicationMatrixIdentifier($name).' ('.implode(', ', $definitions).') ENGINE=InnoDB'];
    }
    foreach ($manifest['tables'] as $name => $table) {
        foreach ($table['references'] as $index => $reference) {
            $constraint = 'mx_'.substr(hash('sha256', $name.$index), 0, 20);
            $statements[] = [
                'phase' => 'ADD_FOREIGN_KEY', 'table' => $name, 'reference_index' => $index,
                'sql' => 'ALTER TABLE '.applicationMatrixIdentifier($name).' ADD CONSTRAINT '.applicationMatrixIdentifier($constraint).' FOREIGN KEY ('.applicationMatrixIdentifierList($reference['columns']).') REFERENCES '.applicationMatrixIdentifier($reference['table']).' ('.applicationMatrixIdentifierList($reference['parent_columns']).') ON DELETE RESTRICT ON UPDATE RESTRICT',
            ];
        }
        foreach ($table['classifications'] as $operation => $classification) {
            // Operation tokens are a closed whitelist, never quoted as identifiers.
            demand(in_array($operation, ['INSERT', 'UPDATE', 'DELETE'], true), 'Invalid matrix trigger operation');
            if (! in_array($classification, ['GUARDED_WRITE', 'LOCK_ONLY_UPDATE'], true)) {
                continue;
            }
            $trigger = 'mx_'.substr(hash('sha256', $name.$operation), 0, 20);
            $lockDeny = $classification === 'LOCK_ONLY_UPDATE'
                ? "SIGNAL SQLSTATE '45005' SET MYSQL_ERRNO = 60042, MESSAGE_TEXT = 'D3A1 matrix lock-only mutation denied'; "
                : '';
            $statements[] = [
                'phase' => 'CREATE_TRIGGER', 'table' => $name, 'operation' => $operation,
                'sql' => "CREATE DEFINER = 'd3a1_matrix_owner'@'%' TRIGGER ".applicationMatrixIdentifier($trigger).' BEFORE '.$operation.' ON '.applicationMatrixIdentifier($name)." FOR EACH ROW BEGIN IF @@session.foreign_key_checks <> 1 OR @@session.unique_checks <> 1 THEN SIGNAL SQLSTATE '45004' SET MYSQL_ERRNO = 60041, MESSAGE_TEXT = 'D3A1 application matrix: checks must be enabled'; END IF; ".$lockDeny.'END',
            ];
        }
    }
    $metadata = applicationMatrixIdentifier('_d3a1_matrix_manifest');
    $digest = applicationMatrixIdentifier('digest');
    $statements[] = ['phase' => 'MANIFEST', 'table' => '_d3a1_matrix_manifest', 'operation' => 'CREATE_TABLE', 'sql' => 'CREATE TABLE '.$metadata.' ('.$digest.' CHAR(64) PRIMARY KEY) ENGINE=InnoDB'];
    $statements[] = ['phase' => 'MANIFEST', 'table' => '_d3a1_matrix_manifest', 'operation' => 'INSERT', 'sql' => 'INSERT INTO '.$metadata.' ('.$digest.') VALUES (?)', 'parameters' => [hash_file('sha256', __DIR__.'/d3a1-application-matrix.json')]];

    return $statements;
}

function applicationMatrixInstallerDiagnostic(array $statement, PDOException $error): array
{
    // ONLY the renderer's public DDL/placeholder SQL enters this context. Never
    // append bound values, driver messages, exception traces or connection data.
    return array_intersect_key($statement, array_flip(['phase', 'table', 'operation', 'reference_index', 'sql'])) + [
        'sqlstate' => $error->errorInfo[0] ?? $error->getCode(),
        'errno' => $error->errorInfo[1] ?? null,
    ];
}

function applicationMatrixInstallerStructure(array $statement): void
{
    $sql = $statement['sql'];
    demand(is_string($sql) && trim($sql) !== '', 'Empty installer SQL');
    applicationMatrixIdentifier($statement['table']);
    demand(in_array($statement['phase'], ['CREATE_TABLE', 'ADD_FOREIGN_KEY', 'CREATE_TRIGGER', 'MANIFEST'], true), 'Unknown installer phase');
    // Lexical balance outside literals/quoted identifiers. This deliberately is
    // not a MySQL parser and cannot replace operator execution on MySQL 8.4.11.
    $depth = 0;
    $quote = null;
    for ($index = 0; $index < strlen($sql); $index++) {
        $character = $sql[$index];
        if ($quote !== null) {
            if ($character === $quote) {
                if (($sql[$index + 1] ?? null) === $quote) {
                    $index++;
                } else {
                    $quote = null;
                }
            }
        } elseif (in_array($character, ["'", '`'], true)) {
            $quote = $character;
        } elseif ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
            demand($depth >= 0, 'Unexpected installer closing parenthesis');
        }
    }
    demand($quote === null && $depth === 0, 'Unbalanced installer SQL');
    preg_match_all('/`([^`]*)`/', $sql, $identifiers);
    foreach ($identifiers[1] as $identifier) {
        applicationMatrixIdentifier($identifier);
    }
    preg_match_all('/(?:PRIMARY KEY|UNIQUE|FOREIGN KEY|REFERENCES `[^`]+`)\s*\(([^()]*)\)/', $sql, $keys);
    foreach ($keys[1] as $key) {
        foreach (explode(',', $key) as $column) {
            demand((bool) preg_match('/^`[a-z_][a-z0-9_]{0,63}`$/D', trim($column)), 'Unquoted/invalid installer key column');
        }
    }
    $tokens = preg_replace("/'(?:[^']|'')*'|`[^`]*`/", ' ', $sql);
    demand(! preg_match('/\b(?:DROP|DELIMITER)\b/', $tokens), 'Unsafe installer statement/client delimiter');
    if ($statement['phase'] === 'CREATE_TABLE' || ($statement['phase'] === 'MANIFEST' && $statement['operation'] === 'CREATE_TABLE')) {
        demand(str_starts_with($sql, 'CREATE TABLE '.applicationMatrixIdentifier($statement['table']).' ('), 'Invalid CREATE TABLE structure');
        demand(str_ends_with($sql, ') ENGINE=InnoDB') && substr_count($sql, 'PRIMARY KEY') === 1, 'Invalid table engine/primary key structure');
    } elseif ($statement['phase'] === 'ADD_FOREIGN_KEY') {
        demand(is_int($statement['reference_index']) && $statement['reference_index'] >= 0, 'Invalid reference index');
        demand((bool) preg_match('/^ALTER TABLE `[a-z_][a-z0-9_]*` ADD CONSTRAINT `mx_[a-f0-9]{20}` FOREIGN KEY \(.+\) REFERENCES `[a-z_][a-z0-9_]*` \(.+\) ON DELETE RESTRICT ON UPDATE RESTRICT$/D', $sql), 'Invalid composite FK structure');
    } elseif ($statement['phase'] === 'CREATE_TRIGGER') {
        demand(in_array($statement['operation'], ['INSERT', 'UPDATE', 'DELETE'], true), 'Invalid trigger operation');
        demand((bool) preg_match("/^CREATE DEFINER = 'd3a1_matrix_owner'@'%' TRIGGER `mx_[a-f0-9]{20}` BEFORE (INSERT|UPDATE|DELETE) ON `[a-z_][a-z0-9_]*` FOR EACH ROW BEGIN /", $sql, $operation), 'Invalid CREATE DEFINER TRIGGER structure');
        demand($operation[1] === $statement['operation'], 'Trigger operation differs from context');
        demand(str_ends_with($sql, ' END') && substr_count($tokens, ' BEGIN ') === 1 && substr_count($tokens, ' END IF; ') === 1 && substr_count($tokens, ' THEN ') === 1, 'Unbalanced trigger BEGIN/IF/END structure');
        preg_match_all("/SIGNAL SQLSTATE '([A-Z0-9]{5})' SET MYSQL_ERRNO = ([0-9]+), MESSAGE_TEXT = '[^']*';/", $sql, $signals, PREG_SET_ORDER);
        demand(count($signals) === substr_count($tokens, 'SIGNAL'), 'Invalid SIGNAL statement syntax');
        demand(count($signals) === 1 || count($signals) === 2, 'Wrong trigger signal count');
        foreach ($signals as $signal) {
            demand((int) $signal[2] >= 1 && (int) $signal[2] <= 65535, 'MYSQL_ERRNO out of unsigned-smallint range');
            demand(in_array([$signal[1], (int) $signal[2]], [['45004', 60041], ['45005', 60042]], true), 'Unexpected matrix guard diagnostics');
        }
    } else {
        demand($statement['operation'] === 'INSERT' && $sql === 'INSERT INTO `_d3a1_matrix_manifest` (`digest`) VALUES (?)', 'Invalid manifest INSERT structure');
        demand(count($statement['parameters']) === 1 && (bool) preg_match('/^[a-f0-9]{64}$/D', $statement['parameters'][0]), 'Invalid public manifest digest parameter');
    }
}

function applicationMatrixInstallerSelfTest(array $manifest): void
{
    $statements = applicationMatrixInstallerStatements($manifest);
    demand($statements === applicationMatrixInstallerStatements($manifest), 'Nondeterministic installer rendering');
    $counts = ['CREATE_TABLE' => 0, 'ADD_FOREIGN_KEY' => 0, 'CREATE_TRIGGER' => 0, 'MANIFEST' => 0];
    $cache = null;
    foreach ($statements as $statement) {
        applicationMatrixInstallerStructure($statement);
        $counts[$statement['phase']]++;
        if ($statement['phase'] === 'CREATE_TABLE' && $statement['table'] === 'cache') {
            $cache = $statement;
        }
    }
    demand($counts === ['CREATE_TABLE' => 30, 'ADD_FOREIGN_KEY' => 56, 'CREATE_TRIGGER' => 43, 'MANIFEST' => 2] && count($statements) === 131, 'Installer statement count changed; audit the baseline');
    demand($cache !== null && str_contains($cache['sql'], 'PRIMARY KEY (`key`)'), 'Reserved cache.key regression');
    $unquoted = $cache;
    $unquoted['sql'] = str_replace('PRIMARY KEY (`key`)', 'PRIMARY KEY (key)', $cache['sql']);
    $unbalanced = $cache;
    $unbalanced['sql'] = substr($cache['sql'], 0, strpos($cache['sql'], ') ENGINE=InnoDB')).' ENGINE=InnoDB';
    foreach ([$unquoted, $unbalanced] as $invalid) {
        $blocked = false;
        try {
            applicationMatrixInstallerStructure($invalid);
        } catch (RuntimeException $error) {
            $blocked = true;
        }
        demand($blocked, 'Malformed installer SQL passed structural validation');
    }
    foreach (['', '1column', 'key`', 'schema.table', 'key name', 'key; DROP TABLE users', str_repeat('a', 65)] as $invalid) {
        $blocked = false;
        try {
            applicationMatrixIdentifier($invalid);
        } catch (RuntimeException $error) {
            $blocked = true;
        }
        demand($blocked, 'Unsafe/oversized identifier was accepted');
    }
    $invalid = $manifest;
    $invalid['tables']['cache']['primary_key'] = ['not_a_column'];
    $blocked = false;
    try {
        applicationMatrixInstallerStatements($invalid);
    } catch (RuntimeException $error) {
        $blocked = true;
    }
    demand($blocked, 'Unknown primary-key identifier was rendered');
    $error = new PDOException('private connection diagnostic sentinel');
    $error->errorInfo = ['42000', 1064, 'private driver diagnostic sentinel'];
    $context = applicationMatrixInstallerDiagnostic($statements[array_key_last($statements)], $error);
    $encoded = json_encode($context, JSON_THROW_ON_ERROR);
    demand($context['phase'] === 'MANIFEST' && $context['sqlstate'] === '42000' && $context['errno'] === 1064, 'Missing installer error context');
    demand(! str_contains($encoded, 'sentinel') && ! isset($context['parameters']), 'Installer diagnostics disclosed private/bound data');
    echo 'STATIC INSTALLER SQL: '.json_encode($counts, JSON_THROW_ON_ERROR)."; 131 statements rendered/checked (no MySQL execution)\n";
}

function installApplicationMatrix(): void
{
    $statements = applicationMatrixInstallerStatements(applicationMatrixManifest());
    $admin = applicationMatrixConnect('OWNER', true);
    try {
        restoreConstraintChecks($admin);
        demand((int) $admin->query('SELECT @@session.autocommit')->fetchColumn() === 1, 'Matrix installer requires autocommit');
        demand((int) $admin->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'phase3_d3a1_matrix'")->fetchColumn() === 0, 'Matrix schema must be empty; no DROP/overwrite');
        demand((int) $admin->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = 'phase3_d3a1_matrix'")->fetchColumn() === 0, 'Unexpected matrix routines');
        foreach ($statements as $statement) {
            try {
                if (isset($statement['parameters'])) {
                    $admin->prepare($statement['sql'])->execute($statement['parameters']);
                } else {
                    $admin->exec($statement['sql']);
                }
            } catch (PDOException $error) {
                fwrite(STDERR, 'INSTALLER FAILURE: '.json_encode(applicationMatrixInstallerDiagnostic($statement, $error), JSON_THROW_ON_ERROR)."\n");
                throw $error; // Preserve native SQLSTATE/errno and fail closed.
            }
        }
    } finally {
        if ($admin !== null) {
            restoreConstraintChecks($admin);
        }
    }
    echo "READY: isolated projected application matrix installed; apply the separate restricted grants file; no live matrix proof\n";
}

function applicationMatrixSql(string $name, array $table, string $operation, array $values): array
{
    $identifier = applicationMatrixIdentifier($name);
    demand(in_array($operation, ['INSERT', 'UPDATE', 'DELETE'], true), 'Invalid runtime operation');
    demand(array_diff(array_keys($values), array_keys($table['columns'])) === [], 'Unknown runtime write column');
    if ($operation === 'INSERT') {
        return ['INSERT INTO '.$identifier.' ('.applicationMatrixIdentifierList(array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')', array_values($values)];
    }
    $where = implode(' AND ', array_map(fn ($column) => applicationMatrixIdentifier($column).' = ?', $table['primary_key']));
    $keys = array_map(fn ($column) => $table['values'][$column], $table['primary_key']);
    if ($operation === 'DELETE') {
        return ['DELETE FROM '.$identifier.' WHERE '.$where, $keys];
    }

    demand($values !== [], 'Empty runtime UPDATE');

    return ['UPDATE '.$identifier.' SET '.implode(',', array_map(fn ($column) => applicationMatrixIdentifier($column).' = ?', array_keys($values))).' WHERE '.$where, array_merge(array_values($values), $keys)];
}

function applicationMatrixReadSql(string $name, array $table, string $variant): array
{
    $identifier = applicationMatrixIdentifier($name);

    return match ($variant) {
        'snapshot' => ['SELECT * FROM '.$identifier.' ORDER BY '.applicationMatrixIdentifierList($table['primary_key']), []],
        'count' => ['SELECT COUNT(*) FROM '.$identifier, []],
        'lock' => ['SELECT * FROM '.$identifier.' FOR UPDATE', []],
        'cleanup' => ['DELETE FROM '.$identifier, []],
        default => throw new RuntimeException('Unknown runtime read/cleanup variant'),
    };
}

function applicationMatrixFixedSql(): array
{
    return [
        'database' => ['SELECT DATABASE()', []],
        'version' => ['SELECT VERSION()', []],
        'server_uuid' => ['SELECT @@server_uuid', []],
        'autocommit' => ['SELECT @@session.autocommit', []],
        'connection_id' => ['SELECT CONNECTION_ID()', []],
        'checks' => ['SELECT @@session.foreign_key_checks AS fk, @@session.unique_checks AS uq', []],
        'grants' => ['SHOW GRANTS', []],
        'manifest' => ['SELECT `digest` FROM `_d3a1_matrix_manifest`', []],
        'triggers' => ["SELECT `TRIGGER_NAME`, `EVENT_MANIPULATION`, `EVENT_OBJECT_TABLE`, `ACTION_TIMING`, `DEFINER` FROM `information_schema`.`TRIGGERS` WHERE `TRIGGER_SCHEMA` = 'phase3_d3a1_matrix'", []],
        'get_lock' => ["SELECT GET_LOCK('phase3_d3a1_matrix.proof', 0)", []],
        'release_lock' => ["SELECT RELEASE_LOCK('phase3_d3a1_matrix.proof')", []],
        'alter_denial' => ['ALTER TABLE `accounts` ADD COLUMN `matrix_ddl_probe` INT', []],
        'drop_trigger_denial' => ['DROP TRIGGER '.applicationMatrixIdentifier('mx_'.substr(hash('sha256', 'accountsINSERT'), 0, 20)), []],
        'begin' => ['START TRANSACTION', []],
        'commit' => ['COMMIT', []],
        'rollback' => ['ROLLBACK', []],
        'fk0' => ['SET SESSION foreign_key_checks = 0', []],
        'fk1' => ['SET SESSION foreign_key_checks = 1', []],
        'unique0' => ['SET SESSION unique_checks = 0', []],
        'unique1' => ['SET SESSION unique_checks = 1', []],
    ];
}

function applicationMatrixEnabledStatements(array $manifest, string $name, array $table, string $operation): array
{
    $classification = $table['classifications'][$operation];
    $statements = [];
    if ($classification === 'LOCK_ONLY_UPDATE') {
        $statements['enabled_lock'] = ['phase' => 'LOCK_ONLY_READ', 'query' => applicationMatrixReadSql($name, $table, 'lock')];
        $statements['enabled_lock_mutation'] = ['phase' => 'LOCK_ONLY_MUTATION', 'query' => applicationMatrixSql($name, $table, 'UPDATE', [$table['mutable'] => 'changed']), 'expected_errnos' => [60042]];
    } elseif ($classification === 'GUARDED_WRITE') {
        $row = applicationMatrixNewRow($table);
        if ($operation === 'DELETE') {
            $statements['prepare_disposable_row'] = ['phase' => 'ENABLED_VALID_WRITE', 'query' => applicationMatrixSql($name, $table, 'INSERT', $row)];
            $where = implode(' AND ', array_map(fn ($key) => applicationMatrixIdentifier($key).' = ?', $table['primary_key']));
            $query = ['DELETE FROM '.applicationMatrixIdentifier($name).' WHERE '.$where, array_map(fn ($key) => $row[$key], $table['primary_key'])];
        } else {
            $value = in_array($table['mutable'], ['state', 'status'], true) ? $table['values'][$table['mutable']] : (is_string($table['values'][$table['mutable']]) ? 'changed' : 7);
            $query = applicationMatrixSql($name, $table, $operation, $operation === 'INSERT' ? $row : [$table['mutable'] => $value]);
        }
        $statements['enabled_valid_write'] = ['phase' => 'ENABLED_VALID_WRITE', 'query' => $query];
        if ($operation === 'UPDATE' && $table['references'] !== []) {
            $column = $table['references'][0]['columns'][0];
            $statements['enabled_invalid_reference_update'] = ['phase' => 'ENABLED_NATIVE_CONSTRAINT', 'query' => applicationMatrixSql($name, $table, 'UPDATE', [$column => 9999]), 'expected_errnos' => [1451, 1452]];
        }
        $hasChildren = false;
        foreach ($manifest['tables'] as $childName => $child) {
            foreach ($child['references'] as $reference) {
                if ($reference['table'] === $name && $childName !== $name) {
                    $hasChildren = true;
                }
            }
        }
        if (($operation === 'DELETE' || $operation === 'UPDATE' && $name === 'users') && $hasChildren) {
            $bad = $operation === 'DELETE' ? [] : [$table['primary_key'][0] => 9999];
            $statements['enabled_referenced_parent_mutation'] = ['phase' => 'ENABLED_NATIVE_CONSTRAINT', 'query' => applicationMatrixSql($name, $table, $operation, $bad), 'expected_errnos' => [1451]];
        }
        if ($operation === 'INSERT') {
            $statements['enabled_duplicate'] = ['phase' => 'ENABLED_NATIVE_CONSTRAINT', 'query' => $query, 'expected_errnos' => [1062]];
        }
        if ($operation === 'INSERT' && $table['references'] !== []) {
            $bad = applicationMatrixNewRow($table);
            $bad[$table['primary_key'][0]] = 102;
            $bad[$table['references'][0]['columns'][0]] = 9999;
            $statements['enabled_missing_reference'] = ['phase' => 'ENABLED_NATIVE_CONSTRAINT', 'query' => applicationMatrixSql($name, $table, 'INSERT', $bad), 'expected_errnos' => [1452]];
        }
        if ($operation === 'INSERT' && in_array($name, ['journal_lines', 'opening_balance_lines', 'journal_line_allocations'], true)) {
            $bad = applicationMatrixNewRow($table);
            $bad['id'] = 103;
            $bad[isset($bad['amount']) ? 'amount' : 'debit'] = 0;
            $statements['enabled_check'] = ['phase' => 'ENABLED_NATIVE_CONSTRAINT', 'query' => applicationMatrixSql($name, $table, 'INSERT', $bad), 'expected_errnos' => [3819]];
        }
    } else {
        $attacks = applicationMatrixAttacks($table, $operation);
        $statements['enabled_privilege_denial'] = ['phase' => 'PRIVILEGE_DENIAL', 'query' => applicationMatrixSql($name, $table, $operation, reset($attacks)), 'expected_errnos' => [1142]];
    }

    return $statements;
}

function applicationMatrixExecute(PDO $pdo, array $query): int
{
    $statement = $pdo->prepare($query[0]);
    $statement->execute($query[1]);

    return $statement->rowCount();
}

function applicationMatrixNewRow(array $table): array
{
    $row = $table['values'];
    foreach ($table['primary_key'] as $key) {
        $row[$key] = is_string($row[$key]) ? 'matrix101' : 101;
    }
    if (isset($row['email'])) {
        $row['email'] = 'matrix101@example.test';
    }

    return $row;
}

function applicationMatrixAttacks(array $table, string $operation): array
{
    if ($operation === 'DELETE') {
        return ['parent_deletion_or_row_removal' => []];
    }
    $base = $operation === 'INSERT' ? applicationMatrixNewRow($table) : [];
    $attacks = [];
    foreach (['user_id', ...array_column($table['references'], 'columns')] as $columns) {
        foreach ((array) $columns as $column) {
            if (! isset($table['columns'][$column]) || $column === 'user_id' && ! array_key_exists('user_id', $table['values'])) {
                continue;
            }
            $attacks[$column.'_missing'] = array_replace($base, [$column => 9999]);
            $attacks[$column.'_foreign'] = array_replace($base, [$column => 2]);
        }
    }
    foreach ($table['primary_key'] as $column) {
        if ($operation === 'UPDATE') {
            $attacks['parent_identity_'.$column] = [$column => is_string($table['values'][$column]) ? 'matrix9999' : 9999];
        }
    }
    // Key/value backend paths have no owner FK; row/key overwrite is the real
    // writable surface. No invented owner/reviewer column is tested there.
    if ($attacks === []) {
        $attacks['row_or_payload_write'] = $operation === 'INSERT' ? $base : [$table['mutable'] => is_string($table['values'][$table['mutable']]) ? 'changed' : 7];
    }

    return $attacks;
}

function applicationMatrixSnapshot(PDO $owner, array $manifest): string
{
    $rows = [];
    foreach ($manifest['tables'] as $name => $table) {
        applicationMatrixContext($owner, 'BASELINE_VERIFY', $name, 'SELECT', 'baseline_snapshot');
        $rows[$name] = $owner->query(applicationMatrixReadSql($name, $table, 'snapshot')[0])->fetchAll(PDO::FETCH_ASSOC);
    }

    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}

function applicationMatrixStatementVariants(string $name, array $table, string $operation): array
{
    $identifier = applicationMatrixIdentifier($name);
    $mutable = applicationMatrixIdentifier($table['mutable']);
    demand(isset($table['columns'][$table['mutable']]), 'Unknown runtime mutable column');
    demand(in_array($operation, ['INSERT', 'UPDATE', 'DELETE'], true), 'Invalid runtime variant operation');
    $variants = [];
    if ($operation === 'INSERT') {
        $query = applicationMatrixSql($name, $table, 'INSERT', $table['values']);
        $columns = applicationMatrixIdentifierList(array_keys($table['values']));
        $params = implode(',', array_fill(0, count($table['values']), '?'));
        $variants['insert_ignore'] = [str_replace('INSERT INTO', 'INSERT IGNORE INTO', $query[0]), $query[1]];
        $variants['insert_select'] = ['INSERT INTO '.$identifier.' ('.$columns.') SELECT '.$params, array_values($table['values'])];
        $variants['multi_row_insert'] = ['INSERT INTO '.$identifier.' ('.$columns.') VALUES ('.$params.'),('.$params.')', array_merge(array_values($table['values']), array_values(applicationMatrixNewRow($table)))];
        $variants['replace'] = [str_replace('INSERT INTO', 'REPLACE INTO', $query[0]), $query[1]];
        // Parameter assignment avoids deprecated VALUES(column) and row aliases.
        $variants['on_duplicate_update'] = ['INSERT INTO '.$identifier.' ('.$columns.') VALUES ('.$params.') ON DUPLICATE KEY UPDATE '.$mutable.' = ?', [...array_values($table['values']), $table['values'][$table['mutable']]]];
    } elseif ($operation === 'UPDATE') {
        $variants['multi_row_update'] = ['UPDATE '.$identifier.' SET '.$mutable.' = ?', [$table['values'][$table['mutable']]]];
    } else {
        $variants['multi_row_delete'] = ['DELETE FROM '.$identifier, []];
    }

    return $variants;
}

function applicationMatrixRuntimeCatalogue(array $manifest): array
{
    $entries = [];
    foreach (applicationMatrixFixedSql() as $variant => $query) {
        $entries[] = ['group' => 'fixed', 'query' => $query];
    }
    foreach ($manifest['tables'] as $name => $table) {
        foreach ($table['seed'] as $row) {
            $entries[] = ['group' => 'seed', 'query' => applicationMatrixSql($name, $table, 'INSERT', $row)];
        }
        foreach (['snapshot', 'count', 'cleanup', ...($table['lock_read'] ? ['lock'] : [])] as $variant) {
            $entries[] = ['group' => 'read_cleanup', 'query' => applicationMatrixReadSql($name, $table, $variant)];
        }
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
            foreach ([[0, 1], [1, 0], [0, 0]] as $checks) {
                foreach (applicationMatrixAttacks($table, $operation) as $variant => $values) {
                    $entries[] = ['group' => 'disabled_attack', 'query' => applicationMatrixSql($name, $table, $operation, $values)];
                }
                foreach (applicationMatrixStatementVariants($name, $table, $operation) as $variant => $query) {
                    $entries[] = ['group' => 'disabled_variant', 'query' => $query];
                }
            }
            foreach (applicationMatrixEnabledStatements($manifest, $name, $table, $operation) as $variant => $control) {
                $entries[] = ['group' => 'enabled_control', 'query' => $control['query']];
            }
        }
    }

    return $entries;
}

function applicationMatrixRuntimeStructure(array $query): void
{
    [$sql, $parameters] = $query;
    demand(is_string($sql) && trim($sql) !== '', 'Empty runtime SQL');
    demand(substr_count($sql, '?') === count($parameters), 'Runtime placeholder/binding mismatch');
    $depth = 0;
    $quote = null;
    for ($index = 0; $index < strlen($sql); $index++) {
        $character = $sql[$index];
        if ($quote !== null) {
            if ($character === $quote) {
                if (($sql[$index + 1] ?? null) === $quote) {
                    $index++;
                } else {
                    $quote = null;
                }
            }
        } elseif (in_array($character, ["'", '`'], true)) {
            $quote = $character;
        } elseif ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
            demand($depth >= 0, 'Unexpected runtime closing parenthesis');
        }
    }
    demand($quote === null && $depth === 0, 'Unbalanced runtime SQL');
    preg_match_all('/`([^`]*)`/', $sql, $identifiers);
    $system = ['TRIGGERS', 'TRIGGER_NAME', 'EVENT_MANIPULATION', 'EVENT_OBJECT_TABLE', 'ACTION_TIMING', 'DEFINER', 'TRIGGER_SCHEMA'];
    foreach ($identifiers[1] as $identifier) {
        if (! in_array($identifier, $system, true)) {
            applicationMatrixIdentifier($identifier);
        }
    }
    $tokens = preg_replace("/'(?:[^']|'')*'|`[^`]*`/", ' ', $sql);
    $tokens = str_replace('ON DUPLICATE KEY UPDATE', '', $tokens);
    demand(! preg_match('/\bkey\b/i', $tokens), 'Unquoted reserved runtime key identifier');
    if (preg_match('/^(?:INSERT(?: IGNORE)?|REPLACE) INTO `[a-z_][a-z0-9_]*` \(([^()]*)\)/', $sql, $columns)) {
        foreach (explode(',', $columns[1]) as $column) {
            demand((bool) preg_match('/^`[a-z_][a-z0-9_]{0,63}`$/D', trim($column)), 'Unquoted INSERT/REPLACE column');
        }
        demand((bool) preg_match('/\) (?:VALUES \(|SELECT \?)/', $sql), 'Invalid INSERT/REPLACE body');
    } elseif (preg_match('/^(?:INSERT|REPLACE)\b/', $sql)) {
        throw new RuntimeException('Invalid runtime INSERT/REPLACE structure');
    }
    if (preg_match('/^UPDATE `[a-z_][a-z0-9_]*` SET (.*?)(?: WHERE .*)?$/D', $sql, $assignments)) {
        foreach (explode(',', $assignments[1]) as $assignment) {
            demand((bool) preg_match('/^`[a-z_][a-z0-9_]{0,63}` = \?$/D', trim($assignment)), 'Unquoted UPDATE assignment');
        }
    }
    if (str_contains($sql, ' ORDER BY ')) {
        $columns = explode(' ORDER BY ', $sql, 2)[1];
        foreach (explode(',', $columns) as $column) {
            demand((bool) preg_match('/^`[a-z_][a-z0-9_]{0,63}`$/D', trim($column)), 'Unquoted runtime ORDER BY');
        }
    }
    if (str_contains($sql, ' ON DUPLICATE KEY UPDATE ')) {
        $assignment = explode(' ON DUPLICATE KEY UPDATE ', $sql, 2)[1];
        demand((bool) preg_match('/^`[a-z_][a-z0-9_]{0,63}` = \?$/D', $assignment), 'Unsafe/deprecated duplicate-key assignment');
    }
}

function applicationMatrixRuntimeSelfTest(array $manifest): void
{
    $catalogue = applicationMatrixRuntimeCatalogue($manifest);
    demand($catalogue === applicationMatrixRuntimeCatalogue($manifest), 'Nondeterministic runtime rendering');
    $counts = [];
    foreach ($catalogue as $entry) {
        applicationMatrixRuntimeStructure($entry['query']);
        $counts[$entry['group']] = ($counts[$entry['group']] ?? 0) + 1;
    }
    demand($counts === ['fixed' => 20, 'seed' => 62, 'read_cleanup' => 105, 'disabled_attack' => 1032, 'disabled_variant' => 630, 'enabled_control' => 152] && count($catalogue) === 2001, 'Runtime SQL catalogue count changed; audit coverage');
    $cache = $manifest['tables']['cache'];
    $insert = applicationMatrixSql('cache', $cache, 'INSERT', $cache['values']);
    $snapshot = applicationMatrixReadSql('cache', $cache, 'snapshot');
    demand(str_contains($insert[0], '(`key`,') && str_ends_with($snapshot[0], 'ORDER BY `key`'), 'Reserved cache.key runtime regression');
    foreach ([
        [str_replace('`key`', 'key', $insert[0]), $insert[1]],
        [str_replace('`key`', 'key', $snapshot[0]), []],
        [substr($insert[0], 0, -1), $insert[1]],
        [$insert[0], []],
    ] as $invalid) {
        $blocked = false;
        try {
            applicationMatrixRuntimeStructure($invalid);
        } catch (RuntimeException $error) {
            $blocked = true;
        }
        demand($blocked, 'Malformed runtime SQL passed structural checks');
    }
    foreach ([['cache; DROP TABLE users', 'INSERT', $cache['values']], ['schema.cache', 'INSERT', $cache['values']], ['cache', 'INSERT', ['unknown_column' => 1]], ['cache', 'INSERT; DROP', $cache['values']]] as [$name, $operation, $values]) {
        $blocked = false;
        try {
            applicationMatrixSql($name, $cache, $operation, $values);
        } catch (RuntimeException $error) {
            $blocked = true;
        }
        demand($blocked, 'Unsafe runtime identifier/operation/column rendered');
    }
    $error = new PDOException('private DSN/password sentinel');
    $error->errorInfo = ['42000', 1064, 'private driver/key sentinel'];
    foreach (['PRIVILEGE_DENIAL', 'ENABLED_VALID_WRITE', 'ENABLED_NATIVE_CONSTRAINT', 'DISABLED_GUARD_WRITE', 'LOCK_ONLY_READ', 'LOCK_ONLY_MUTATION', 'BASELINE_VERIFY', 'CLEANUP'] as $phase) {
        $context = ['phase' => $phase, 'table' => 'cache', 'operation' => 'INSERT', 'attack_variant' => 'test', 'checks_state' => [0, 1], 'parameters' => ['secret sentinel']];
        $diagnostic = applicationMatrixRuntimeDiagnostic($context, $insert[0], $error);
        demand(array_keys($diagnostic) === ['phase', 'table', 'operation', 'attack_variant', 'checks_state', 'sql', 'sqlstate', 'errno'], 'Missing/unexpected runtime diagnostic fields');
        demand($diagnostic['sqlstate'] === '42000' && $diagnostic['errno'] === 1064 && ! str_contains(json_encode($diagnostic, JSON_THROW_ON_ERROR), 'sentinel'), 'Runtime diagnostics disclosed private/bound data');
    }
    echo 'STATIC RUNTIME SQL: '.json_encode($counts, JSON_THROW_ON_ERROR).'; '.count($catalogue)." catalogue entries checked (no MySQL execution)\n";
}

function applicationMatrixRow(array $row): void
{
    echo json_encode($row, JSON_THROW_ON_ERROR)."\n";
}

function applicationMatrixDiagnostic(string $expected, PDOException $error): bool
{
    $info = $error->errorInfo;
    if ($expected === 'PRIVILEGE_DENIAL') {
        return ($info[0] ?? '') === '42000' && (int) ($info[1] ?? 0) === 1142;
    }

    return ($info[0] ?? '') === ($expected === 'LOCK_DENIAL' ? '45005' : '45004')
        && (int) ($info[1] ?? 0) === ($expected === 'LOCK_DENIAL' ? 60042 : 60041)
        && ($info[2] ?? '') === ($expected === 'LOCK_DENIAL' ? 'D3A1 matrix lock-only mutation denied' : 'D3A1 application matrix: checks must be enabled');
}

function runApplicationMatrixProof(): void
{
    $manifest = applicationMatrixManifest();
    applicationMatrixValidate($manifest);
    $owner = applicationMatrixConnect('OWNER');
    $runtime = applicationMatrixConnect('RUNTIME');
    $seeded = false;
    $locked = false;
    $unresolved = [];
    $completed = [];
    try {
        applicationMatrixContext($runtime, 'CLEANUP', '_session', 'SET', 'initial_restore');
        restoreConstraintChecks($runtime);
        applicationMatrixContext($owner, 'BASELINE_VERIFY', '_session', 'SELECT', 'safe_owner_checks');
        $owner->matrixChecks(constraintChecks($owner));
        demand(constraintChecks($owner) === [1, 1], 'Matrix owner must have safe session checks');
        applicationMatrixContext($runtime, 'BASELINE_VERIFY', '_session', 'SELECT', 'autocommit');
        demand((int) $owner->query(applicationMatrixFixedSql()['autocommit'][0])->fetchColumn() === 1 && (int) $runtime->query(applicationMatrixFixedSql()['autocommit'][0])->fetchColumn() === 1, 'Matrix monitor must use autocommit');
        applicationMatrixContext($owner, 'BASELINE_VERIFY', '_d3a1_matrix_manifest', 'SELECT', 'installation_digest');
        demand($owner->query(applicationMatrixFixedSql()['manifest'][0])->fetchColumn() === hash_file('sha256', __DIR__.'/d3a1-application-matrix.json'), 'Partial/stale matrix installation');
        applicationMatrixVerifyGrants($runtime, $manifest);
        applicationMatrixContext($owner, 'BASELINE_VERIFY', '_triggers', 'SELECT', 'guard_metadata');
        $observedTriggers = $owner->query(applicationMatrixFixedSql()['triggers'][0])->fetchAll(PDO::FETCH_ASSOC);
        $expectedTriggers = [];
        foreach ($manifest['tables'] as $name => $table) {
            foreach ($table['classifications'] as $operation => $classification) {
                if (in_array($classification, ['GUARDED_WRITE', 'LOCK_ONLY_UPDATE'], true)) {
                    $expectedTriggers['mx_'.substr(hash('sha256', $name.$operation), 0, 20)] = [$name, $operation];
                }
            }
        }
        demand(count($observedTriggers) === count($expectedTriggers), 'Missing/extra matrix guard');
        foreach ($observedTriggers as $trigger) {
            demand(($expectedTriggers[$trigger['TRIGGER_NAME']] ?? null) === [$trigger['EVENT_OBJECT_TABLE'], $trigger['EVENT_MANIPULATION']] && $trigger['ACTION_TIMING'] === 'BEFORE' && $trigger['DEFINER'] === 'd3a1_matrix_owner@%', 'Matrix trigger ownership/shape changed');
        }
        applicationMatrixContext($owner, 'BASELINE_VERIFY', '_coordination', 'SELECT', 'get_lock');
        demand((int) $owner->query(applicationMatrixFixedSql()['get_lock'][0])->fetchColumn() === 1, 'Another matrix proof is active');
        $locked = true;
        $id = (int) $runtime->query(applicationMatrixFixedSql()['connection_id'][0])->fetchColumn();
        foreach ($manifest['tables'] as $name => $table) {
            applicationMatrixContext($owner, 'BASELINE_VERIFY', $name, 'SELECT', 'empty_table');
            demand((int) $owner->query(applicationMatrixReadSql($name, $table, 'count')[0])->fetchColumn() === 0, 'Matrix tables must be empty; never erase existing data');
        }
        $seeded = true;
        applicationMatrixContext($owner, 'ENABLED_VALID_WRITE', '_seed', 'INSERT', 'begin_seed');
        $owner->beginTransaction();
        foreach ($manifest['tables'] as $name => $table) {
            foreach ($table['seed'] as $seedIndex => $seed) {
                applicationMatrixContext($owner, 'ENABLED_VALID_WRITE', $name, 'INSERT', 'seed_'.$seedIndex);
                applicationMatrixExecute($owner, applicationMatrixSql($name, $table, 'INSERT', $seed));
            }
        }
        $owner->commit();
        $baseline = applicationMatrixSnapshot($owner, $manifest);
        // Fresh restricted identity has no trigger/DDL rights. Probe denial;
        // an unexpected success blocks rather than silently repairing the schema.
        applicationMatrixContext($runtime, 'PRIVILEGE_DENIAL', 'accounts', 'ALTER', 'alter_denial');
        rejected(fn () => $runtime->exec(applicationMatrixFixedSql()['alter_denial'][0]), [1142]);
        applicationMatrixContext($runtime, 'PRIVILEGE_DENIAL', 'accounts', 'DROP TRIGGER', 'drop_trigger_denial');
        rejected(fn () => $runtime->exec(applicationMatrixFixedSql()['drop_trigger_denial'][0]), [1142]);
        foreach ($manifest['tables'] as $name => $table) {
            foreach ($table['classifications'] as $operation => $classification) {
                $path = $name.'.'.$operation;
                $denied = in_array($classification, ['REMOVE_RUNTIME_PRIVILEGE', 'TRUSTED_ROUTINE_ONLY_LATER'], true);
                foreach ([[0, 1], [1, 0], [0, 0]] as [$fk, $unique]) {
                    try {
                        applicationMatrixContext($runtime, 'BASELINE_VERIFY', $name, 'SET', 'set_constraint_state');
                        $runtime->exec(applicationMatrixFixedSql()['fk'.$fk][0]);
                        $runtime->exec(applicationMatrixFixedSql()['unique'.$unique][0]);
                        demand(constraintChecks($runtime) === [$fk, $unique], 'Requested matrix flags were not set');
                        if ($table['lock_read']) {
                            applicationMatrixContext($runtime, 'LOCK_ONLY_READ', $name, 'FOR UPDATE', 'locking_read');
                            $runtime->beginTransaction();
                            $runtime->query(applicationMatrixReadSql($name, $table, 'lock')[0])->fetchAll();
                            $runtime->rollBack();
                            applicationMatrixRow(['table' => $name, 'operation' => 'FOR UPDATE', 'runtime_needed' => true, 'classification' => $classification, 'attack_tested' => 'locking_read', 'checks_state' => [$fk, $unique], 'expected_protection' => 'LOCK_SUCCEEDS', 'observed_result' => 'SUCCESS']);
                        }
                        foreach (applicationMatrixAttacks($table, $operation) as $attack => $values) {
                            applicationMatrixContext($runtime, $denied ? 'PRIVILEGE_DENIAL' : 'DISABLED_GUARD_WRITE', $name, $operation, $attack);
                            $runtime->beginTransaction();
                            $expected = $denied ? 'PRIVILEGE_DENIAL' : 'MATRIX_GUARD';
                            $observed = 'WRITE_SUCCEEDED';
                            $ok = false;
                            try {
                                applicationMatrixExecute($runtime, applicationMatrixSql($name, $table, $operation, $values));
                            } catch (PDOException $error) {
                                $observed = ($error->errorInfo[0] ?? '').'/'.($error->errorInfo[1] ?? 'unknown');
                                $ok = applicationMatrixDiagnostic($expected, $error);
                            }
                            $runtime->exec('COMMIT');
                            $unchanged = applicationMatrixSnapshot($owner, $manifest) === $baseline;
                            applicationMatrixRow(['table' => $name, 'operation' => $operation, 'runtime_needed' => in_array($classification, ['GUARDED_WRITE', 'LOCK_ONLY_UPDATE'], true), 'mutation_needed' => in_array($operation, $table['needed'], true), 'classification' => $classification, 'attack_tested' => $attack, 'checks_state' => [$fk, $unique], 'expected_protection' => $expected, 'observed_result' => $observed, 'diagnostic_matches' => $ok, 'baseline_unchanged_after_commit' => $unchanged]);
                            if (! $ok || ! $unchanged) {
                                $unresolved[$path] = true;
                            }
                            demand($unchanged, 'Matrix attack changed committed baseline: '.$path);
                        }
                        foreach (applicationMatrixStatementVariants($name, $table, $operation) as $variant => $query) {
                            $granted = applicationMatrixGranted($table);
                            $needsExtra = $variant === 'replace' ? 'DELETE' : ($variant === 'on_duplicate_update' ? 'UPDATE' : null);
                            $expected = $denied || $needsExtra !== null && ! in_array($needsExtra, $granted, true) ? 'PRIVILEGE_DENIAL' : 'MATRIX_GUARD';
                            applicationMatrixContext($runtime, $expected === 'PRIVILEGE_DENIAL' ? 'PRIVILEGE_DENIAL' : 'DISABLED_GUARD_WRITE', $name, $operation, $variant);
                            $runtime->beginTransaction();
                            $ok = false;
                            $observed = 'WRITE_SUCCEEDED';
                            try {
                                applicationMatrixExecute($runtime, $query);
                            } catch (PDOException $error) {
                                $observed = ($error->errorInfo[0] ?? '').'/'.($error->errorInfo[1] ?? 'unknown');
                                $ok = applicationMatrixDiagnostic($expected, $error);
                            }
                            $runtime->exec('COMMIT');
                            $unchanged = applicationMatrixSnapshot($owner, $manifest) === $baseline;
                            applicationMatrixRow(['table' => $name, 'operation' => $operation, 'runtime_needed' => in_array($classification, ['GUARDED_WRITE', 'LOCK_ONLY_UPDATE'], true), 'mutation_needed' => in_array($operation, $table['needed'], true), 'classification' => $classification, 'attack_tested' => $variant, 'checks_state' => [$fk, $unique], 'expected_protection' => $expected, 'observed_result' => $observed, 'diagnostic_matches' => $ok, 'baseline_unchanged_after_commit' => $unchanged]);
                            if (! $ok || ! $unchanged) {
                                $unresolved[$path] = true;
                            }
                            demand($unchanged, 'Compound matrix statement changed baseline: '.$path);
                        }
                    } finally {
                        applicationMatrixContext($runtime, 'CLEANUP', $name, 'ROLLBACK', 'restore_after_disabled_state');
                        try {
                            $runtime->exec(applicationMatrixFixedSql()['rollback'][0]);
                        } finally {
                            restoreConstraintChecks($runtime);
                        }
                    }
                    applicationMatrixContext($runtime, 'BASELINE_VERIFY', $name, 'SELECT', 'same_connection');
                    demand((int) $runtime->query(applicationMatrixFixedSql()['connection_id'][0])->fetchColumn() === $id, 'Matrix runtime connection changed');
                    applicationMatrixContext($runtime, 'ENABLED_VALID_WRITE', $name, $operation, 'begin_enabled_control');
                    $runtime->beginTransaction();
                    try {
                        foreach (applicationMatrixEnabledStatements($manifest, $name, $table, $operation) as $variant => $control) {
                            applicationMatrixContext($runtime, $control['phase'], $name, $operation, $variant, $control['expected_errnos'] ?? []);
                            if ($control['phase'] === 'LOCK_ONLY_READ') {
                                $runtime->query($control['query'][0])->fetchAll();
                            } elseif (isset($control['expected_errnos'])) {
                                $error = rejected(fn () => applicationMatrixExecute($runtime, $control['query']), $control['expected_errnos']);
                                if ($control['phase'] === 'LOCK_ONLY_MUTATION') {
                                    demand(applicationMatrixDiagnostic('LOCK_DENIAL', $error), 'Wrong enabled lock-only rejection');
                                } elseif ($control['phase'] === 'PRIVILEGE_DENIAL') {
                                    demand(applicationMatrixDiagnostic('PRIVILEGE_DENIAL', $error), 'Wrong enabled privilege rejection');
                                }
                                if (in_array($variant, ['enabled_invalid_reference_update', 'enabled_referenced_parent_mutation'], true)) {
                                    applicationMatrixRow(['table' => $name, 'operation' => $operation, 'runtime_needed' => true, 'mutation_needed' => true, 'classification' => $classification, 'attack_tested' => $variant, 'checks_state' => [1, 1], 'expected_protection' => 'NATIVE_FK', 'observed_result' => $error->errorInfo[0].'/'.$error->errorInfo[1]]);
                                }
                            } else {
                                demand(applicationMatrixExecute($runtime, $control['query']) === 1, 'Valid matrix write/reuse failed: '.$path);
                            }
                        }
                    } finally {
                        applicationMatrixContext($runtime, 'CLEANUP', $name, 'ROLLBACK', 'rollback_enabled_control');
                        $runtime->exec(applicationMatrixFixedSql()['rollback'][0]);
                    }
                    demand(applicationMatrixSnapshot($owner, $manifest) === $baseline, 'Enabled reuse changed baseline');
                    applicationMatrixRow(['table' => $name, 'operation' => $operation, 'runtime_needed' => in_array($classification, ['GUARDED_WRITE', 'LOCK_ONLY_UPDATE'], true), 'mutation_needed' => in_array($operation, $table['needed'], true), 'classification' => $classification, 'attack_tested' => 'same_connection_restoration_control', 'checks_state' => [1, 1], 'expected_protection' => $classification === 'GUARDED_WRITE' ? 'VALID_WRITE_AND_NATIVE_CONSTRAINTS' : ($classification === 'LOCK_ONLY_UPDATE' ? 'LOCK_SUCCEEDS_MUTATION_DENIED' : 'PRIVILEGE_DENIAL'), 'observed_result' => 'SUCCESS', 'connection_id' => $id]);
                }
                $completed[$path] = true;
            }
        }
    } finally {
        $cleanupFailed = false;
        if ($runtime !== null) {
            applicationMatrixContext($runtime, 'CLEANUP', '_session', 'ROLLBACK', 'final_runtime_restore');
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
            applicationMatrixContext($owner, 'CLEANUP', '_seed', 'ROLLBACK', 'rollback_seed');
            $owner->exec(applicationMatrixFixedSql()['rollback'][0]);
            if ($seeded) {
                foreach (array_reverse(array_keys($manifest['tables'])) as $name) {
                    applicationMatrixContext($owner, 'CLEANUP', $name, 'DELETE', 'remove_probe_rows');
                    $owner->exec(applicationMatrixReadSql($name, $manifest['tables'][$name], 'cleanup')[0]);
                    applicationMatrixContext($owner, 'CLEANUP', $name, 'SELECT', 'verify_empty_after_cleanup');
                    demand((int) $owner->query(applicationMatrixReadSql($name, $manifest['tables'][$name], 'count')[0])->fetchColumn() === 0, 'Matrix cleanup left probe rows');
                }
            }
        } catch (Throwable $error) {
            $cleanupFailed = true;
            throw $error;
        } finally {
            if ($locked) {
                applicationMatrixContext($owner, 'CLEANUP', '_coordination', 'SELECT', 'release_lock');
                $owner->query(applicationMatrixFixedSql()['release_lock'][0]);
            }
            $totals = applicationMatrixTotals($manifest);
            foreach ($manifest['tables'] as $name => $table) {
                foreach ($table['classifications'] as $operation => $classification) {
                    if (! isset($completed[$name.'.'.$operation])) {
                        $unresolved[$name.'.'.$operation] = true;
                    }
                }
            }
            $totals['unresolved_paths'] = count($unresolved);
            $totals['cleanup_failed'] = $cleanupFailed;
            applicationMatrixRow(['summary' => $totals, 'verdict' => $totals['unresolved_paths'] > 0 || $cleanupFailed ? 'BLOCKED' : 'TEST_MODEL_PASS', 'scope' => 'projected relations and proposed restricted profile; production closure NOT PROVEN']);
        }
        demand(! $cleanupFailed, 'Matrix rollback/restoration failed; runtime discarded');
    }
    demand($unresolved === [], 'Matrix has unresolved paths');
}
