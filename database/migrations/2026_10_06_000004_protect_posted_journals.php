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
        // Preflight the whole set before dropping anything; MySQL trigger DDL auto-commits.
        $existing = $this->inspect($definitions);
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
                throw new RuntimeException("Unexpected trigger {$name}; refusing to create or drop accounting protections.");
            }
            $existing[$name] = true;
        }

        return $existing;
    }

    private function definitions(): array
    {
        return [
            'accounting_je_draft_bi' => ['journal_entries', 'INSERT', <<<'SQL'
BEGIN
    IF CAST(NEW.status AS BINARY) = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal headers must be inserted as drafts';
    END IF;
END
SQL],
            'accounting_je_immutable_bu' => ['journal_entries', 'UPDATE', <<<'SQL'
BEGIN
    IF CAST(OLD.status AS BINARY) = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal header cannot be updated';
    END IF;
END
SQL],
            'accounting_je_immutable_bd' => ['journal_entries', 'DELETE', <<<'SQL'
BEGIN
    IF CAST(OLD.status AS BINARY) = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal header cannot be deleted';
    END IF;
END
SQL],
            'accounting_jl_immutable_bi' => ['journal_lines', 'INSERT', <<<'SQL'
BEGIN
    DECLARE parent_status VARCHAR(16) DEFAULT NULL;
    DECLARE EXIT HANDLER FOR 3572
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal header is busy; retry the line mutation';
    SELECT status INTO parent_status FROM journal_entries
        WHERE id = NEW.journal_entry_id FOR SHARE NOWAIT;
    IF parent_status IS NULL OR CAST(parent_status AS BINARY) = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot insert line into posted or missing journal';
    END IF;
END
SQL],
            'accounting_jl_immutable_bu' => ['journal_lines', 'UPDATE', <<<'SQL'
BEGIN
    DECLARE parent_status VARCHAR(16) DEFAULT NULL;
    DECLARE EXIT HANDLER FOR 3572
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal header is busy; retry the line mutation';
    SELECT status INTO parent_status FROM journal_entries
        WHERE id = LEAST(OLD.journal_entry_id, NEW.journal_entry_id) FOR SHARE NOWAIT;
    IF parent_status IS NULL OR CAST(parent_status AS BINARY) = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot update line of posted or missing journal';
    END IF;
    IF OLD.journal_entry_id <> NEW.journal_entry_id THEN
        SET parent_status = NULL;
        SELECT status INTO parent_status FROM journal_entries
            WHERE id = GREATEST(OLD.journal_entry_id, NEW.journal_entry_id) FOR SHARE NOWAIT;
        IF parent_status IS NULL OR CAST(parent_status AS BINARY) = 'posted' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot move line to or from posted or missing journal';
        END IF;
    END IF;
END
SQL],
            'accounting_jl_immutable_bd' => ['journal_lines', 'DELETE', <<<'SQL'
BEGIN
    DECLARE parent_status VARCHAR(16) DEFAULT NULL;
    DECLARE EXIT HANDLER FOR 3572
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal header is busy; retry the line mutation';
    SELECT status INTO parent_status FROM journal_entries
        WHERE id = OLD.journal_entry_id FOR SHARE NOWAIT;
    IF parent_status IS NULL OR CAST(parent_status AS BINARY) = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete line of posted or missing journal';
    END IF;
END
SQL],
        ];
    }
};
