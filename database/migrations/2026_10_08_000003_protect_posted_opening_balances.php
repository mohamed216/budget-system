<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        if ((Schema::hasTable('opening_balance_batches') && DB::table('opening_balance_batches')->exists())
            || (Schema::hasTable('opening_balance_lines') && DB::table('opening_balance_lines')->exists())) {
            throw new RuntimeException('Refusing to remove opening-balance protections while opening-balance rows exist.');
        }
        $definitions = $this->definitions();
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
                throw new RuntimeException("Unexpected trigger {$name}; refusing to change opening-balance protections.");
            }
            $existing[$name] = true;
        }

        return $existing;
    }

    private function definitions(): array
    {
        return [
            'accounting_obb_draft_bi' => ['opening_balance_batches', 'INSERT', <<<'SQL'
BEGIN
    IF CAST(NEW.status AS BINARY) = 'posted'
        AND NEW.journal_entry_id IS NOT NULL AND NEW.posted_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Opening balance batches must be inserted as drafts';
    END IF;
END
SQL],
            'accounting_obb_immutable_bu' => ['opening_balance_batches', 'UPDATE', <<<'SQL'
BEGIN
    IF CAST(OLD.status AS BINARY) = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted opening balance batch cannot be updated';
    END IF;
END
SQL],
            'accounting_obb_immutable_bd' => ['opening_balance_batches', 'DELETE', <<<'SQL'
BEGIN
    IF CAST(OLD.status AS BINARY) = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted opening balance batch cannot be deleted';
    END IF;
END
SQL],
            'accounting_obl_immutable_bi' => ['opening_balance_lines', 'INSERT', <<<'SQL'
BEGIN
    DECLARE parent_status VARCHAR(16) DEFAULT NULL;
    DECLARE EXIT HANDLER FOR 3572
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Opening balance batch is busy; retry the line mutation';
    SELECT status INTO parent_status FROM opening_balance_batches
        WHERE id = NEW.batch_id FOR SHARE NOWAIT;
    IF parent_status IS NOT NULL AND CAST(parent_status AS BINARY) = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot insert line into posted or missing opening balance batch';
    END IF;
END
SQL],
            'accounting_obl_immutable_bu' => ['opening_balance_lines', 'UPDATE', <<<'SQL'
BEGIN
    DECLARE parent_status VARCHAR(16) DEFAULT NULL;
    DECLARE EXIT HANDLER FOR 3572
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Opening balance batch is busy; retry the line mutation';
    SELECT status INTO parent_status FROM opening_balance_batches
        WHERE id = LEAST(OLD.batch_id, NEW.batch_id) FOR SHARE NOWAIT;
    IF parent_status IS NOT NULL AND CAST(parent_status AS BINARY) = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot update line of posted or missing opening balance batch';
    END IF;
    IF OLD.batch_id <> NEW.batch_id THEN
        SET parent_status = NULL;
        SELECT status INTO parent_status FROM opening_balance_batches
            WHERE id = GREATEST(OLD.batch_id, NEW.batch_id) FOR SHARE NOWAIT;
        IF parent_status IS NOT NULL AND CAST(parent_status AS BINARY) = 'posted' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot move line to or from posted or missing opening balance batch';
        END IF;
    END IF;
END
SQL],
            'accounting_obl_immutable_bd' => ['opening_balance_lines', 'DELETE', <<<'SQL'
BEGIN
    DECLARE parent_status VARCHAR(16) DEFAULT NULL;
    DECLARE EXIT HANDLER FOR 3572
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Opening balance batch is busy; retry the line mutation';
    SELECT status INTO parent_status FROM opening_balance_batches
        WHERE id = OLD.batch_id FOR SHARE NOWAIT;
    IF parent_status IS NOT NULL AND CAST(parent_status AS BINARY) = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete line of posted or missing opening balance batch';
    END IF;
END
SQL],
        ];
    }
};
