<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $definitions = $this->definitions();
        $existing = $this->inspect($definitions);
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
        if (DB::table('journal_line_allocations')->exists() || DB::table('cash_flow_journal_completions')->exists()) {
            throw new RuntimeException('Refusing to remove cash-flow review protections while review rows exist.');
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
                throw new RuntimeException("Unexpected trigger {$name}; refusing to change cash-flow protections.");
            }
            $existing[$name] = true;
        }

        return $existing;
    }

    private function definitions(): array
    {
        return [
            'cash_flow_allocation_bi' => ['journal_line_allocations', 'INSERT', <<<'SQL'
BEGIN
    DECLARE parent_status VARCHAR(16) DEFAULT NULL;
    DECLARE completion_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE EXIT HANDLER FOR 3572
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cash-flow journal is busy; retry the allocation mutation';
    SELECT status INTO parent_status FROM journal_entries
        WHERE id = NEW.journal_entry_id AND user_id = NEW.user_id FOR UPDATE NOWAIT;
    SELECT id INTO completion_id FROM cash_flow_journal_completions
        WHERE journal_entry_id = NEW.journal_entry_id AND user_id = NEW.user_id FOR SHARE NOWAIT;
    IF completion_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed cash-flow allocations cannot be changed';
    END IF;
END
SQL],
            'cash_flow_allocation_bu' => ['journal_line_allocations', 'UPDATE', <<<'SQL'
BEGIN
    DECLARE old_status VARCHAR(16) DEFAULT NULL;
    DECLARE new_status VARCHAR(16) DEFAULT NULL;
    DECLARE EXIT HANDLER FOR 3572
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cash-flow journal is busy; retry the allocation mutation';
    SELECT status INTO old_status FROM journal_entries
        WHERE id = OLD.journal_entry_id AND user_id = OLD.user_id FOR UPDATE NOWAIT;
    IF old_status IS NULL OR CAST(old_status AS BINARY) = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted or sealed cash-flow allocations cannot be changed';
    END IF;
    IF OLD.journal_entry_id <> NEW.journal_entry_id OR OLD.user_id <> NEW.user_id THEN
        SELECT status INTO new_status FROM journal_entries
            WHERE id = NEW.journal_entry_id AND user_id = NEW.user_id FOR UPDATE NOWAIT;
        IF new_status IS NULL OR CAST(new_status AS BINARY) = 'posted' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Allocation cannot move to a posted or sealed journal';
        END IF;
    END IF;
END
SQL],
            'cash_flow_allocation_bd' => ['journal_line_allocations', 'DELETE', <<<'SQL'
BEGIN
    DECLARE parent_status VARCHAR(16) DEFAULT NULL;
    DECLARE EXIT HANDLER FOR 3572
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cash-flow journal is busy; retry the allocation mutation';
    SELECT status INTO parent_status FROM journal_entries
        WHERE id = OLD.journal_entry_id AND user_id = OLD.user_id FOR UPDATE NOWAIT;
    IF parent_status IS NULL OR CAST(parent_status AS BINARY) = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted or sealed cash-flow allocations cannot be deleted';
    END IF;
END
SQL],
            'cash_flow_completion_bi' => ['cash_flow_journal_completions', 'INSERT', <<<'SQL'
BEGIN
    DECLARE parent_status VARCHAR(16) DEFAULT NULL;
    DECLARE EXIT HANDLER FOR 3572
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cash-flow journal is busy; retry completion';
    SELECT status INTO parent_status FROM journal_entries
        WHERE id = NEW.journal_entry_id AND user_id = NEW.user_id FOR UPDATE NOWAIT;
    IF parent_status IS NOT NULL AND CAST(parent_status AS BINARY) <> 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a posted journal can have a cash-flow completion';
    END IF;
END
SQL],
            'cash_flow_completion_bu' => ['cash_flow_journal_completions', 'UPDATE', <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cash-flow completion cannot be changed';
END
SQL],
            'cash_flow_completion_bd' => ['cash_flow_journal_completions', 'DELETE', <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cash-flow completion cannot be deleted';
END
SQL],
        ];
    }
};
