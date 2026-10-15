-- TEST ONLY. Apply after install to a freshly created d3a1_matrix_runtime@%.
-- No global/schema mutation grant, no TRIGGER/DDL/EXECUTE/GRANT OPTION.
GRANT SELECT, UPDATE ON phase3_d3a1_matrix.`users` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON phase3_d3a1_matrix.`accounts` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT, DELETE ON phase3_d3a1_matrix.`categories` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON phase3_d3a1_matrix.`budgets` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON phase3_d3a1_matrix.`chart_of_accounts` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON phase3_d3a1_matrix.`journal_entries` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT, DELETE ON phase3_d3a1_matrix.`journal_lines` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT ON phase3_d3a1_matrix.`transactions` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON phase3_d3a1_matrix.`accounting_periods` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, UPDATE ON phase3_d3a1_matrix.`operational_accounting_integrations` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT ON phase3_d3a1_matrix.`operational_accounting_account_scope` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT ON phase3_d3a1_matrix.`operational_account_mappings` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT ON phase3_d3a1_matrix.`operational_accounting_category_scope` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT ON phase3_d3a1_matrix.`operational_category_mappings` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON phase3_d3a1_matrix.`opening_balance_batches` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT, DELETE ON phase3_d3a1_matrix.`opening_balance_lines` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT, DELETE ON phase3_d3a1_matrix.`journal_line_allocations` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT, UPDATE ON phase3_d3a1_matrix.`cash_flow_journal_completions` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT, UPDATE ON phase3_d3a1_matrix.`fiscal_year_closes` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT ON phase3_d3a1_matrix.`operational_transaction_journals` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT ON phase3_d3a1_matrix.`operational_reconciliation_reviews` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT ON phase3_d3a1_matrix.`operational_reconciliation_charts` TO 'd3a1_matrix_runtime'@'%';
-- No runtime rights on operational_reconciliation_accounts (unwired maintenance only).
-- No runtime rights on operational_reconciliation_categories (unwired maintenance only).
GRANT SELECT ON phase3_d3a1_matrix.`operational_reconciliation_journals` TO 'd3a1_matrix_runtime'@'%';
-- No runtime rights on operational_reconciliation_attributions (unwired maintenance only).
GRANT SELECT, INSERT, UPDATE, DELETE ON phase3_d3a1_matrix.`sessions` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON phase3_d3a1_matrix.`cache` TO 'd3a1_matrix_runtime'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON phase3_d3a1_matrix.`jobs` TO 'd3a1_matrix_runtime'@'%';
GRANT INSERT ON phase3_d3a1_matrix.`failed_jobs` TO 'd3a1_matrix_runtime'@'%';
