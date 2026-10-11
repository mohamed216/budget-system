<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['operational_account_mappings', 'operational_category_mappings',
            'operational_transaction_journals', 'journal_entries', 'transactions',
            'accounts', 'journal_lines', 'opening_balance_batches', 'fiscal_year_closes'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Missing {$table}; refusing to install Phase 3 guards.");
            }
        }
        $definitions = $this->definitions();
        $existing = $this->inspect($definitions);
        if (count($existing) !== count($definitions)
            && DB::table('operational_transaction_journals')->exists()) {
            throw new RuntimeException('Unprotected operational links exist; inspect them before installing missing guards.');
        }
        foreach ($definitions as $name => [$table, $event, $body]) {
            if (! isset($existing[$name])) {
                DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW {$body}");
            }
        }
    }

    public function down(): void
    {
        $definitions = $this->definitions();
        $existing = $this->inspect($definitions);
        foreach (['operational_transaction_journals', 'operational_account_mappings',
            'operational_category_mappings'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to remove Phase 3 guards while {$table} contains rows.");
            }
        }
        foreach (array_keys($definitions) as $name) {
            if (isset($existing[$name])) {
                DB::unprepared("DROP TRIGGER `{$name}`");
            }
        }
    }

    private function inspect(array $definitions): array
    {
        $existing = [];
        foreach ($definitions as $name => [$table, $event, $body]) {
            $trigger = DB::selectOne('SELECT EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ?', [DB::connection()->getDatabaseName(), $name]);
            if ($trigger === null) {
                continue;
            }
            if ($trigger->EVENT_OBJECT_TABLE !== $table || $trigger->ACTION_TIMING !== 'BEFORE'
                || $trigger->EVENT_MANIPULATION !== $event || trim($trigger->ACTION_STATEMENT) !== trim($body)) {
                throw new RuntimeException("Unexpected trigger {$name}; refusing to change Phase 3 protections.");
            }
            $existing[$name] = true;
        }

        return $existing;
    }

    private function definitions(): array
    {
        return [
            'phase3_otj_validate_bi' => ['operational_transaction_journals', 'INSERT', <<<'SQL'
BEGIN
    DECLARE transaction_account_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE transaction_category_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE transaction_amount DECIMAL(15,2) DEFAULT NULL;
    DECLARE transaction_type VARCHAR(16) DEFAULT NULL;
    DECLARE transaction_date DATE DEFAULT NULL;
    DECLARE account_currency CHAR(3) DEFAULT NULL;
    DECLARE account_chart_account_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE category_chart_account_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE journal_status VARCHAR(16) DEFAULT NULL;
    DECLARE original_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE journal_date DATE DEFAULT NULL;
    DECLARE journal_currency CHAR(3) DEFAULT NULL;
    DECLARE special_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE line_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE owner_line_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE one_sided_line_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE account_debit_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE account_credit_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE category_debit_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE category_credit_count BIGINT UNSIGNED DEFAULT 0;
    SELECT account_id, category_id, amount, type, date
        INTO transaction_account_id, transaction_category_id, transaction_amount, transaction_type, transaction_date
        FROM transactions WHERE id = NEW.transaction_id AND user_id = NEW.user_id FOR SHARE NOWAIT;
    IF transaction_account_id IS NULL OR transaction_category_id IS NULL OR transaction_amount IS NULL
        OR transaction_type IS NULL OR transaction_date IS NULL
        OR transaction_account_id <> NEW.account_id OR transaction_category_id <> NEW.category_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operational link does not match its transaction';
    END IF;
    SELECT currency INTO account_currency
        FROM accounts WHERE id = transaction_account_id AND user_id = NEW.user_id FOR SHARE NOWAIT;
    SELECT chart_account_id INTO account_chart_account_id
        FROM operational_account_mappings WHERE account_id = transaction_account_id AND user_id = NEW.user_id FOR SHARE NOWAIT;
    SELECT chart_account_id INTO category_chart_account_id
        FROM operational_category_mappings WHERE category_id = transaction_category_id AND user_id = NEW.user_id FOR SHARE NOWAIT;
    IF account_currency IS NULL OR account_chart_account_id IS NULL OR category_chart_account_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operational link requires owned account and category mappings';
    END IF;
    SELECT status, reversal_of_id, entry_date, currency INTO journal_status, original_id, journal_date, journal_currency
        FROM journal_entries WHERE id = NEW.journal_entry_id AND user_id = NEW.user_id FOR SHARE NOWAIT;
    IF journal_status IS NULL OR journal_date IS NULL OR journal_currency IS NULL
        OR CAST(journal_status AS BINARY) <> 'posted' OR original_id IS NOT NULL
        OR journal_date <> transaction_date OR CAST(journal_currency AS BINARY) <> CAST(account_currency AS BINARY) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operational link requires an original posted journal';
    END IF;
    SELECT id INTO special_id FROM opening_balance_batches
        WHERE journal_entry_id = NEW.journal_entry_id AND user_id = NEW.user_id LIMIT 1 FOR SHARE NOWAIT;
    IF special_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Opening journal cannot be an operational transaction journal';
    END IF;
    SELECT id INTO special_id FROM fiscal_year_closes
        WHERE journal_entry_id = NEW.journal_entry_id AND user_id = NEW.user_id LIMIT 1 FOR SHARE NOWAIT;
    IF special_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fiscal close cannot be an operational transaction journal';
    END IF;
    SELECT COUNT(*),
        COALESCE(SUM(CASE WHEN user_id = NEW.user_id THEN 1 ELSE 0 END), 0),
        COALESCE(SUM(CASE WHEN user_id = NEW.user_id AND ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)) THEN 1 ELSE 0 END), 0),
        COALESCE(SUM(CASE WHEN user_id = NEW.user_id AND chart_account_id = account_chart_account_id AND debit = transaction_amount AND credit = 0 THEN 1 ELSE 0 END), 0),
        COALESCE(SUM(CASE WHEN user_id = NEW.user_id AND chart_account_id = account_chart_account_id AND debit = 0 AND credit = transaction_amount THEN 1 ELSE 0 END), 0),
        COALESCE(SUM(CASE WHEN user_id = NEW.user_id AND chart_account_id = category_chart_account_id AND debit = transaction_amount AND credit = 0 THEN 1 ELSE 0 END), 0),
        COALESCE(SUM(CASE WHEN user_id = NEW.user_id AND chart_account_id = category_chart_account_id AND debit = 0 AND credit = transaction_amount THEN 1 ELSE 0 END), 0)
        INTO line_count, owner_line_count, one_sided_line_count, account_debit_count, account_credit_count, category_debit_count, category_credit_count
        FROM journal_lines WHERE journal_entry_id = NEW.journal_entry_id;
    IF line_count <> 2 OR owner_line_count <> 2 OR one_sided_line_count <> 2
        OR (CAST(transaction_type AS BINARY) = 'income' AND (account_debit_count <> 1 OR category_credit_count <> 1))
        OR (CAST(transaction_type AS BINARY) = 'expense' AND (category_debit_count <> 1 OR account_credit_count <> 1))
        OR CAST(transaction_type AS BINARY) NOT IN ('income', 'expense') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operational link journal does not match its transaction';
    END IF;
END
SQL],
            'phase3_otj_immutable_bu' => ['operational_transaction_journals', 'UPDATE', <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operational journal link cannot be changed';
END
SQL],
            'phase3_otj_immutable_bd' => ['operational_transaction_journals', 'DELETE', <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operational journal link cannot be deleted';
END
SQL],
            'phase3_transaction_facts_immutable_bu' => ['transactions', 'UPDATE', <<<'SQL'
BEGIN
    DECLARE linked_transaction_id BIGINT UNSIGNED DEFAULT NULL;
    SELECT transaction_id INTO linked_transaction_id
        FROM operational_transaction_journals
        WHERE transaction_id = OLD.id
        LIMIT 1 FOR SHARE NOWAIT;
    IF linked_transaction_id IS NOT NULL
        AND (NOT (NEW.user_id <=> OLD.user_id)
            OR NOT (NEW.account_id <=> OLD.account_id)
            OR NOT (NEW.category_id <=> OLD.category_id)
            OR NOT (NEW.amount <=> OLD.amount)
            OR NOT (NEW.type <=> OLD.type)
            OR NOT (NEW.date <=> OLD.date)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Linked transaction accounting facts cannot be changed';
    END IF;
END
SQL],
            'phase3_oam_freeze_bu' => ['operational_account_mappings', 'UPDATE', <<<'SQL'
BEGIN
    DECLARE linked_transaction_id BIGINT UNSIGNED DEFAULT NULL;
    IF NEW.account_id <> OLD.account_id OR NEW.user_id <> OLD.user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operational account mapping identity cannot change';
    END IF;
    IF NEW.chart_account_id <> OLD.chart_account_id THEN
        SELECT link.transaction_id INTO linked_transaction_id
            FROM operational_transaction_journals AS link
            JOIN journal_entries AS journal ON journal.id = link.journal_entry_id AND journal.user_id = link.user_id
            WHERE link.account_id = OLD.account_id AND link.user_id = OLD.user_id
                AND CAST(journal.status AS BINARY) = 'posted'
            LIMIT 1 FOR SHARE NOWAIT;
        IF linked_transaction_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Linked operational account mapping target is frozen';
        END IF;
    END IF;
END
SQL],
            'phase3_oam_freeze_bd' => ['operational_account_mappings', 'DELETE', <<<'SQL'
BEGIN
    DECLARE linked_transaction_id BIGINT UNSIGNED DEFAULT NULL;
    SELECT link.transaction_id INTO linked_transaction_id
        FROM operational_transaction_journals AS link
        JOIN journal_entries AS journal ON journal.id = link.journal_entry_id AND journal.user_id = link.user_id
        WHERE link.account_id = OLD.account_id AND link.user_id = OLD.user_id
            AND CAST(journal.status AS BINARY) = 'posted'
        LIMIT 1 FOR SHARE NOWAIT;
    IF linked_transaction_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Linked operational account mapping cannot be deleted';
    END IF;
END
SQL],
            'phase3_ocm_freeze_bu' => ['operational_category_mappings', 'UPDATE', <<<'SQL'
BEGIN
    DECLARE linked_transaction_id BIGINT UNSIGNED DEFAULT NULL;
    IF NEW.category_id <> OLD.category_id OR NEW.user_id <> OLD.user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operational category mapping identity cannot change';
    END IF;
    IF NEW.chart_account_id <> OLD.chart_account_id
        OR NOT (NEW.cash_flow_activity <=> OLD.cash_flow_activity) THEN
        SELECT link.transaction_id INTO linked_transaction_id
            FROM operational_transaction_journals AS link
            JOIN journal_entries AS journal ON journal.id = link.journal_entry_id AND journal.user_id = link.user_id
            WHERE link.category_id = OLD.category_id AND link.user_id = OLD.user_id
                AND CAST(journal.status AS BINARY) = 'posted'
            LIMIT 1 FOR SHARE NOWAIT;
        IF linked_transaction_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Linked operational category mapping target or activity is frozen';
        END IF;
    END IF;
END
SQL],
            'phase3_ocm_freeze_bd' => ['operational_category_mappings', 'DELETE', <<<'SQL'
BEGIN
    DECLARE linked_transaction_id BIGINT UNSIGNED DEFAULT NULL;
    SELECT link.transaction_id INTO linked_transaction_id
        FROM operational_transaction_journals AS link
        JOIN journal_entries AS journal ON journal.id = link.journal_entry_id AND journal.user_id = link.user_id
        WHERE link.category_id = OLD.category_id AND link.user_id = OLD.user_id
            AND CAST(journal.status AS BINARY) = 'posted'
        LIMIT 1 FOR SHARE NOWAIT;
    IF linked_transaction_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Linked operational category mapping cannot be deleted';
    END IF;
END
SQL],
        ];
    }
};
