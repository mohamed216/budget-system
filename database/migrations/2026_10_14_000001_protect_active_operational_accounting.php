<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->requireFoundation();
        $existing = $this->inspect();
        if ($existing !== []) {
            throw new RuntimeException('ACTIVE protection objects already exist or installation is partial; inspect migration history before retrying.');
        }
        $this->assertNoActiveOwners();
        foreach ($this->definitions() as $name => [$table, $event, $body]) {
            // Creator-as-definer: only the controlled deployment identity installs guards.
            DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW {$body}");
        }
    }

    public function down(): void
    {
        $this->requireFoundation();
        $existing = $this->inspect();
        $this->assertNoActiveOwners();
        // Preflight ALL objects/data before MySQL's first implicitly committing DROP.
        foreach (array_keys($existing) as $name) {
            DB::unprepared("DROP TRIGGER `{$name}`");
        }
    }

    private function assertNoActiveOwners(): void
    {
        if (DB::table('operational_accounting_integrations')->whereRaw("CAST(state AS BINARY) = 'active'")->exists()) {
            throw new RuntimeException('ACTIVE owners exist; refusing installation or removal of closed ACTIVE protections without explicit investigation.');
        }
    }

    private function requireFoundation(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('ACTIVE protection DDL requires no open application transaction.');
        }
        $columns = [
            'operational_accounting_integrations' => ['user_id' => 'bigint unsigned', 'state' => 'varchar(16)',
                'activated_at' => 'datetime(6)', 'accounting_currency' => 'char(3)',
                'historical_transaction_strategy' => 'varchar(32)', 'transaction_high_water_id' => 'bigint unsigned',
                'scope_reviewed_at' => 'timestamp', 'reconciliation_reference' => 'varchar(255)', 'reconciled_at' => 'datetime(6)'],
            'accounts' => ['id' => 'bigint unsigned', 'user_id' => 'bigint unsigned', 'balance' => 'decimal(15,2)', 'currency' => 'varchar(255)'],
            'categories' => ['id' => 'bigint unsigned', 'user_id' => 'bigint unsigned', 'type' => 'varchar(255)'],
            'chart_of_accounts' => ['id' => 'bigint unsigned', 'user_id' => 'bigint unsigned', 'type' => 'varchar(16)', 'cash_role' => 'varchar(16)'],
            'journal_entries' => ['id' => 'bigint unsigned', 'user_id' => 'bigint unsigned', 'status' => 'varchar(16)'],
            'journal_lines' => ['id' => 'bigint unsigned', 'journal_entry_id' => 'bigint unsigned', 'chart_account_id' => 'bigint unsigned'],
            'operational_reconciliation_reviews' => ['id' => 'bigint unsigned', 'user_id' => 'bigint unsigned', 'reference' => 'varchar(255)', 'completed_at' => 'datetime(6)'],
            'operational_reconciliation_charts' => ['review_id' => 'bigint unsigned', 'user_id' => 'bigint unsigned', 'chart_account_id' => 'bigint unsigned'],
        ];
        foreach (['account', 'category'] as $kind) {
            $columns['operational_accounting_'.$kind.'_scope'] = [$kind.'_id' => 'bigint unsigned', 'user_id' => 'bigint unsigned', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
            $columns['operational_'.$kind.'_mappings'] = [$kind.'_id' => 'bigint unsigned', 'user_id' => 'bigint unsigned', 'chart_account_id' => 'bigint unsigned'];
        }
        $columns['operational_category_mappings'] += ['cash_flow_activity' => 'varchar(16)', 'reviewed_by' => 'bigint unsigned', 'reviewed_at' => 'datetime(6)'];
        foreach ($columns as $table => $fields) {
            $metadata = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->first();
            if ($metadata === null || $metadata->ENGINE !== 'InnoDB') {
                throw new RuntimeException("Missing InnoDB ACTIVE protection foundation {$table}.");
            }
            $actual = collect(Schema::getColumns($table))->keyBy('name');
            foreach ($fields as $field => $type) {
                if (! isset($actual[$field]) || strtolower($actual[$field]['type']) !== $type) {
                    throw new RuntimeException("Incompatible ACTIVE protection column {$table}.{$field}.");
                }
            }
        }
        $keys = [
            'operational_accounting_integrations' => ['user_id'],
            'accounts' => ['id', 'user_id'], 'categories' => ['id', 'user_id'],
            'chart_of_accounts' => ['id', 'user_id'], 'journal_entries' => ['id', 'user_id'],
            'operational_reconciliation_reviews' => ['user_id', 'reference'],
        ];
        foreach (['account', 'category'] as $kind) {
            $keys['operational_accounting_'.$kind.'_scope'] = [$kind.'_id', 'user_id'];
            $keys['operational_'.$kind.'_mappings'] = [$kind.'_id', 'user_id'];
            if (! collect(Schema::getIndexes('operational_'.$kind.'_mappings'))->contains(
                fn ($index) => array_slice($index['columns'], 0, 2) === ['chart_account_id', 'user_id']
            )) {
                throw new RuntimeException('Missing indexed ACTIVE mapping lookup for '.$kind.'.');
            }
        }
        foreach ($keys as $table => $key) {
            if (! collect(Schema::getIndexes($table))->contains(fn ($index) => $index['unique'] && $index['columns'] === $key)) {
                throw new RuntimeException("Missing owner-safe ACTIVE protection key on {$table}.");
            }
        }
    }

    private function inspect(): array
    {
        $definitions = $this->definitions();
        $foundation = [];
        foreach (['2026_10_06_000004_protect_posted_journals.php', '2026_10_09_000005_protect_cash_flow_review_rows.php',
            '2026_10_10_000006_protect_operational_accounting_links.php', '2026_10_10_000015_protect_operational_reconciliation_evidence.php',
            '2026_10_10_000016_bind_operational_reconciliation_reviews.php'] as $file) {
            $migration = require database_path('migrations/'.$file);
            foreach ((new ReflectionMethod($migration, 'definitions'))->invoke($migration) as $name => $definition) {
                $foundation[$name] = count($definition) === 2
                    ? ['operational_accounting_integrations', ...$definition] : $definition;
            }
        }
        $tables = array_unique(array_column($definitions, 0));
        $triggers = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where(fn ($query) => $query->whereIn('EVENT_OBJECT_TABLE', $tables)
                ->orWhereIn('TRIGGER_NAME', array_keys($definitions + $foundation)))->get()->keyBy('TRIGGER_NAME');
        foreach ($foundation as $name => [$table, $event, $body]) {
            $trigger = $triggers->get($name);
            if ($trigger === null || $trigger->EVENT_OBJECT_TABLE !== $table || $trigger->ACTION_TIMING !== 'BEFORE'
                || $trigger->EVENT_MANIPULATION !== $event || trim($trigger->ACTION_STATEMENT) !== trim($body)) {
                throw new RuntimeException("Missing or incompatible foundation trigger {$name}; refusing ACTIVE protection DDL.");
            }
        }
        $existing = [];
        foreach ($triggers as $name => $trigger) {
            $expected = ($definitions + $foundation)[$name] ?? null;
            if ($expected === null || $trigger->EVENT_OBJECT_TABLE !== $expected[0] || $trigger->ACTION_TIMING !== 'BEFORE'
                || $trigger->EVENT_MANIPULATION !== $expected[1] || trim($trigger->ACTION_STATEMENT) !== trim($expected[2])) {
                throw new RuntimeException("Unexpected ACTIVE protection trigger {$name}; refusing to adopt or remove it.");
            }
            if (isset($definitions[$name])) {
                $existing[$name] = true;
            }
        }

        return $existing;
    }

    private function changed(array $fields, array $binary = []): string
    {
        return implode(' OR ', array_map(fn ($field) => in_array($field, $binary, true)
            ? "NOT (CAST(NEW.{$field} AS BINARY) <=> CAST(OLD.{$field} AS BINARY))"
            : "NOT (NEW.{$field} <=> OLD.{$field})", $fields));
    }

    private function lookup(string $sql): string
    {
        // A missing SELECT INTO must not terminate an enclosing cursor loop.
        return "BEGIN\n    DECLARE CONTINUE HANDLER FOR NOT FOUND BEGIN END;\n    {$sql}\nEND;";
    }

    private function gate(string $owner): string
    {
        return "SET gate_state = NULL;\nSET gate_reference = NULL;\n".$this->lookup("SELECT state, reconciliation_reference INTO gate_state, gate_reference FROM operational_accounting_integrations WHERE user_id = {$owner} FOR SHARE;");
    }

    private function scope(string $kind, string $id, string $owner): string
    {
        return "SET selected_id = NULL;\n".$this->lookup("SELECT {$kind}_id INTO selected_id FROM operational_accounting_{$kind}_scope WHERE {$kind}_id = {$id} AND user_id = {$owner} FOR SHARE;");
    }

    private function deny(string $message): string
    {
        return "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';";
    }

    private function body(string $sql): string
    {
        // Current locking reads defeat stale repeatable-read snapshots. MySQL trigger
        // prelocking does not reliably preserve NOWAIT: raw SQL may wait or receive
        // native 1205/1213 errors. Supported actions must prelock in normative order.
        return "BEGIN\nDECLARE gate_state VARCHAR(16) DEFAULT NULL;\nDECLARE gate_reference VARCHAR(255) DEFAULT NULL;\nDECLARE selected_id BIGINT UNSIGNED DEFAULT NULL;\n{$sql}\nEND";
    }

    private function participant(string $kind, string $row, string $message): string
    {
        return $this->gate($row.'.user_id')."\nIF CAST(gate_state AS BINARY) = 'active' THEN\n"
            .$this->scope($kind, $row.'.'.$kind.'_id', $row.'.user_id')
            ."\nIF selected_id IS NOT NULL THEN\n".$this->deny($message)."\nEND IF;\nEND IF;";
    }

    private function mappedChart(string $kind, string $chart, string $owner, string $message, bool $ownedSource = false): string
    {
        $source = $ownedSource
            ? "\nIF selected_id IS NOT NULL THEN\nSET selected_id = NULL;\n".$this->lookup("SELECT id INTO selected_id FROM accounts WHERE id = source_id AND user_id = {$owner} FOR SHARE;")."\nEND IF;"
            : '';

        return "BEGIN\nDECLARE finished BOOLEAN DEFAULT FALSE;\nDECLARE source_id BIGINT UNSIGNED;\nDECLARE targets CURSOR FOR SELECT {$kind}_id FROM operational_{$kind}_mappings WHERE chart_account_id = {$chart} AND user_id = {$owner} ORDER BY {$kind}_id FOR SHARE;\nDECLARE CONTINUE HANDLER FOR NOT FOUND SET finished = TRUE;\nOPEN targets;\nscan_targets: LOOP\nFETCH targets INTO source_id;\nIF finished THEN LEAVE scan_targets; END IF;\n"
            .$this->scope($kind, 'source_id', $owner).$source."\nIF selected_id IS NOT NULL THEN\n".$this->deny($message)
            ."\nEND IF;\nEND LOOP;\nCLOSE targets;\nEND;";
    }

    private function definitions(): array
    {
        $definitions = [];
        $identity = ['user_id', 'state', 'activated_at', 'accounting_currency', 'historical_transaction_strategy',
            'transaction_high_water_id', 'scope_reviewed_at', 'reconciliation_reference', 'reconciled_at'];
        $definitions['phase3_active_oai_bu'] = ['operational_accounting_integrations', 'UPDATE', $this->body(
            "IF OLD.activated_at IS NOT NULL AND NOT (NEW.activated_at <=> OLD.activated_at) THEN\n".$this->deny('Activation time is assigned once and cannot change')."\nEND IF;\n"
            ."IF CAST(OLD.state AS BINARY) = 'active' AND (".$this->changed($identity, ['state', 'accounting_currency', 'historical_transaction_strategy', 'reconciliation_reference']).") THEN\n"
            .$this->deny('ACTIVE cutover identity cannot change')."\nEND IF;")];
        $definitions['phase3_active_oai_bd'] = ['operational_accounting_integrations', 'DELETE', $this->body(
            "IF CAST(OLD.state AS BINARY) = 'active' THEN\n".$this->deny('ACTIVE integration cannot be deleted')."\nEND IF;")];
        foreach (['account' => 'oaas', 'category' => 'oacs'] as $kind => $prefix) {
            $table = 'operational_accounting_'.$kind.'_scope';
            foreach (['INSERT' => ['NEW'], 'UPDATE' => ['OLD', 'NEW'], 'DELETE' => ['OLD']] as $event => $rows) {
                $sql = '';
                foreach ($rows as $row) {
                    $sql .= $this->gate($row.'.user_id')."\nIF CAST(gate_state AS BINARY) = 'active' THEN\n".$this->deny('ACTIVE '.$kind.' scope cannot change')."\nEND IF;\n";
                }
                if ($event === 'UPDATE') {
                    $sql = 'IF '.$this->changed([$kind.'_id', 'user_id', 'created_at', 'updated_at'])." THEN\n{$sql}END IF;";
                }
                $definitions['phase3_active_'.$prefix.'_b'.strtolower($event[0])] = [$table, $event, $this->body($sql)];
            }
        }
        foreach (['account' => 'oam', 'category' => 'ocm'] as $kind => $prefix) {
            $fields = [$kind.'_id', 'user_id', 'chart_account_id'];
            $binary = [];
            if ($kind === 'category') {
                $fields = [...$fields, 'cash_flow_activity', 'reviewed_by', 'reviewed_at'];
                $binary = ['cash_flow_activity'];
            }
            foreach (['INSERT' => ['NEW'], 'UPDATE' => ['OLD', 'NEW'], 'DELETE' => ['OLD']] as $event => $rows) {
                $sql = '';
                foreach ($rows as $row) {
                    $sql .= $this->participant($kind, $row, 'ACTIVE '.$kind.' mapping cannot change')."\n";
                }
                if ($event === 'UPDATE') {
                    $sql = 'IF '.$this->changed($fields, $binary)." THEN\n{$sql}END IF;";
                }
                $definitions['phase3_active_'.$prefix.'_b'.strtolower($event[0])] = ['operational_'.$kind.'_mappings', $event, $this->body($sql)];
            }
        }
        foreach (['account' => ['id', 'user_id', 'currency', 'balance'], 'category' => ['id', 'user_id', 'type']] as $kind => $fields) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $sql = $this->gate('OLD.user_id')."\nIF CAST(gate_state AS BINARY) = 'active' THEN\n"
                    .$this->scope($kind, 'OLD.id', 'OLD.user_id')."\nIF selected_id IS NOT NULL THEN\n"
                    .$this->deny('ACTIVE participating '.$kind.' accounting facts cannot change')."\nEND IF;\nEND IF;";
                if ($event === 'UPDATE') {
                    $sql = 'IF '.$this->changed($fields, ['currency', 'type'])." THEN\n{$sql}\nEND IF;";
                }
                $definitions['phase3_active_'.$kind.'_b'.strtolower($event[0])] = [$kind === 'category' ? 'categories' : 'accounts', $event, $this->body($sql)];
            }
        }
        $chart = $this->gate('OLD.user_id')."\nIF CAST(gate_state AS BINARY) = 'active' THEN\n"
            .$this->mappedChart('account', 'OLD.id', 'OLD.user_id', 'ACTIVE chart accounting semantics cannot change')."\n"
            .$this->mappedChart('category', 'OLD.id', 'OLD.user_id', 'ACTIVE chart accounting semantics cannot change')."\n"
            ."BEGIN\nDECLARE finished BOOLEAN DEFAULT FALSE;\nDECLARE candidate_review BIGINT UNSIGNED;\nDECLARE reviews CURSOR FOR SELECT review_id FROM operational_reconciliation_charts WHERE chart_account_id = OLD.id AND user_id = OLD.user_id ORDER BY review_id FOR SHARE;\nDECLARE CONTINUE HANDLER FOR NOT FOUND SET finished = TRUE;\nOPEN reviews;\nscan_reviews: LOOP\nFETCH reviews INTO candidate_review;\nIF finished THEN LEAVE scan_reviews; END IF;\nSET selected_id = NULL;\n"
            .$this->lookup('SELECT id INTO selected_id FROM operational_reconciliation_reviews WHERE id = candidate_review AND user_id = OLD.user_id AND CAST(reference AS BINARY) = CAST(gate_reference AS BINARY) AND completed_at IS NOT NULL FOR SHARE;')
            ."\nIF selected_id IS NOT NULL THEN\n".$this->deny('ACTIVE reviewed chart accounting semantics cannot change')
            ."\nEND IF;\nEND LOOP;\nCLOSE reviews;\nEND;\nEND IF;";
        $definitions['phase3_active_chart_bu'] = ['chart_of_accounts', 'UPDATE', $this->body('IF '.$this->changed(['id', 'user_id', 'type', 'cash_role'], ['type', 'cash_role'])." THEN\n{$chart}\nEND IF;")];
        $definitions['phase3_active_chart_bd'] = ['chart_of_accounts', 'DELETE', $this->body($chart)];
        $publication = $this->gate('NEW.user_id')."\nIF CAST(gate_state AS BINARY) = 'active' THEN\n"
            ."BEGIN\nDECLARE finished BOOLEAN DEFAULT FALSE;\nDECLARE target_chart BIGINT UNSIGNED;\nDECLARE chart_type VARCHAR(16);\nDECLARE sources CURSOR FOR SELECT chart_account_id FROM journal_lines WHERE journal_entry_id = OLD.id ORDER BY id FOR SHARE;\nDECLARE CONTINUE HANDLER FOR NOT FOUND SET finished = TRUE;\nOPEN sources;\nscan_lines: LOOP\nFETCH sources INTO target_chart;\nIF finished THEN LEAVE scan_lines; END IF;\nSET chart_type = NULL;\n"
            .$this->lookup('SELECT type INTO chart_type FROM chart_of_accounts WHERE id = target_chart AND user_id = NEW.user_id FOR SHARE;')
            ."\nIF CAST(chart_type AS BINARY) = 'asset' THEN\n"
            .$this->mappedChart('account', 'target_chart', 'NEW.user_id', 'ACTIVE mapped operational asset publication is disabled', true)
            ."\nEND IF;\nEND LOOP;\nCLOSE sources;\nEND;\nEND IF;";
        $definitions['phase3_active_je_publish_bu'] = ['journal_entries', 'UPDATE', $this->body(
            "IF CAST(OLD.status AS BINARY) = 'draft' AND CAST(NEW.status AS BINARY) = 'posted' THEN\n{$publication}\nEND IF;")];

        return $definitions;
    }
};
