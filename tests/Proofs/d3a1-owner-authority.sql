-- TEST ONLY: empty phase3_d3a1_authority on budget-phase23-mysql-test.
-- No DELIMITER needed; PHP sends each OA statement intact. No fixture secrets.
-- OA TABLE users
CREATE TABLE `users` (`id` BIGINT UNSIGNED PRIMARY KEY, `name` VARCHAR(255) NOT NULL, `email` VARCHAR(255) NOT NULL UNIQUE, `email_verified_at` TIMESTAMP NULL, `password` VARCHAR(255) NOT NULL, `remember_token` VARCHAR(100) NULL, `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL) ENGINE=InnoDB
-- OA TABLE sessions
CREATE TABLE `sessions` (`id` VARCHAR(255) PRIMARY KEY, `user_id` BIGINT UNSIGNED NULL, `ip_address` VARCHAR(45) NULL, `user_agent` TEXT NULL, `payload` LONGTEXT NOT NULL, `last_activity` INT NOT NULL, INDEX (`last_activity`)) ENGINE=InnoDB
-- OA TABLE cache
CREATE TABLE `cache` (`key` VARCHAR(255) PRIMARY KEY, `value` MEDIUMTEXT NOT NULL, `expiration` INT NOT NULL) ENGINE=InnoDB
-- OA TABLE jobs
CREATE TABLE `jobs` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `queue` VARCHAR(255) NOT NULL, `payload` LONGTEXT NOT NULL, `attempts` TINYINT UNSIGNED NOT NULL, `reserved_at` INT UNSIGNED NULL, `available_at` INT UNSIGNED NOT NULL, `created_at` INT UNSIGNED NOT NULL, INDEX (`queue`)) ENGINE=InnoDB
-- OA TABLE failed_jobs
CREATE TABLE `failed_jobs` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `uuid` VARCHAR(255) NOT NULL UNIQUE, `connection` TEXT NOT NULL, `queue` TEXT NOT NULL, `payload` LONGTEXT NOT NULL, `exception` LONGTEXT NOT NULL, `failed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB
-- OA TABLE proof_accounts
CREATE TABLE `proof_accounts` (`id` BIGINT UNSIGNED PRIMARY KEY, `owner_id` BIGINT UNSIGNED NOT NULL, `currency` CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `revision` BIGINT UNSIGNED NOT NULL, UNIQUE (`id`, `owner_id`), FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`)) ENGINE=InnoDB
-- OA TABLE proof_intents
CREATE TABLE `proof_intents` (`id` BIGINT UNSIGNED PRIMARY KEY, `owner_id` BIGINT UNSIGNED NOT NULL, `account_id` BIGINT UNSIGNED NOT NULL, `amount` DECIMAL(18,2) NOT NULL, `direction` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `accounting_date` DATE NOT NULL, `currency` CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `revision` BIGINT UNSIGNED NOT NULL, `effect_slot` BIGINT UNSIGNED NOT NULL UNIQUE, `result_slot` BIGINT UNSIGNED NOT NULL UNIQUE, CHECK (`amount` > 0), CHECK (`direction` IN ('income','expense')), FOREIGN KEY (`account_id`, `owner_id`) REFERENCES `proof_accounts` (`id`, `owner_id`)) ENGINE=InnoDB
-- OA TABLE proof_commands
CREATE TABLE `proof_commands` (`command_uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, `owner_id` BIGINT UNSIGNED NOT NULL, `intent_digest` BINARY(32) NOT NULL) ENGINE=InnoDB
-- OA TABLE proof_effects
CREATE TABLE `proof_effects` (`command_uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, `effect_slot` BIGINT UNSIGNED NOT NULL UNIQUE, `owner_id` BIGINT UNSIGNED NOT NULL, `amount` DECIMAL(18,2) NOT NULL, FOREIGN KEY (`command_uuid`) REFERENCES `proof_commands` (`command_uuid`)) ENGINE=InnoDB
-- OA TABLE proof_results
CREATE TABLE `proof_results` (`command_uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, `result_uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, `result_slot` BIGINT UNSIGNED NOT NULL UNIQUE, FOREIGN KEY (`command_uuid`) REFERENCES `proof_effects` (`command_uuid`)) ENGINE=InnoDB
-- OA TABLE proof_keys
CREATE TABLE `proof_keys` (`key_id` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, `secret` VARBINARY(64) NOT NULL, `status` VARCHAR(16) NOT NULL, `sign_until_us` BIGINT NOT NULL, `verify_until_us` BIGINT NOT NULL, CHECK (`status` IN ('active','verify_only','revoked'))) ENGINE=InnoDB
-- OA TABLE proof_caller_writes
CREATE TABLE `proof_caller_writes` (`id` BIGINT PRIMARY KEY) ENGINE=InnoDB
-- OA TABLE proof_manifest
CREATE TABLE `proof_manifest` (`id` INT PRIMARY KEY, `version` VARCHAR(64) NOT NULL) ENGINE=InnoDB
-- OA ROUTINE oa_hmac
CREATE DEFINER='d3a1_oa_verify'@'%' PROCEDURE `oa_hmac`(IN secret LONGBLOB, IN payload LONGBLOB, OUT tag VARBINARY(32)) SQL SECURITY DEFINER
BEGIN
    DECLARE padded VARBINARY(64);
    IF OCTET_LENGTH(secret) > 64 THEN SET secret = UNHEX(SHA2(secret, 256)); END IF;
    SET padded = CONCAT(secret, REPEAT(CHAR(0 USING binary), 64 - OCTET_LENGTH(secret)));
    SET tag = UNHEX(SHA2(CONCAT(padded ^ UNHEX(REPEAT('5c',64)), UNHEX(SHA2(CONCAT(padded ^ UNHEX(REPEAT('36',64)), payload),256))),256));
END
-- OA ROUTINE oa_clock
CREATE DEFINER='d3a1_oa_verify'@'%' PROCEDURE `oa_clock`(OUT clock_us BIGINT) SQL SECURITY DEFINER
BEGIN
    DECLARE old_zone VARCHAR(64) DEFAULT @@session.time_zone;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN SET SESSION time_zone = old_zone; RESIGNAL; END;
    SET SESSION time_zone = '+00:00';
    SET clock_us = TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', SYSDATE(6));
    SET SESSION time_zone = old_zone;
END
-- OA ROUTINE oa_require_transaction
CREATE DEFINER='d3a1_oa_verify'@'%' PROCEDURE `oa_require_transaction`() SQL SECURITY DEFINER
BEGIN
    DECLARE anchor INT;
    DECLARE active_state VARCHAR(16) DEFAULT NULL;
    DECLARE auto_state VARCHAR(3) DEFAULT NULL;
    DECLARE end_event BIGINT UNSIGNED DEFAULT NULL;
    SELECT `id` INTO anchor FROM `proof_manifest` WHERE `id`=1;
    SELECT e.STATE, e.AUTOCOMMIT, e.END_EVENT_ID INTO active_state, auto_state, end_event
      FROM performance_schema.events_transactions_current e
      JOIN performance_schema.threads t ON t.THREAD_ID=e.THREAD_ID WHERE t.PROCESSLIST_ID=CONNECTION_ID();
    IF @@session.autocommit <> 1 OR active_state IS NULL OR active_state <> 'ACTIVE' OR auto_state <> 'NO' OR end_event IS NOT NULL
       OR @@session.foreign_key_checks <> 1 OR @@session.unique_checks <> 1 THEN
        SIGNAL SQLSTATE '45013' SET MYSQL_ERRNO=60054, MESSAGE_TEXT='OA prerequisite unavailable';
    END IF;
END
-- OA ROUTINE oa_lock_owner
CREATE DEFINER='d3a1_oa_lock'@'%' PROCEDURE `oa_lock_owner`(IN owner_id BIGINT UNSIGNED) SQL SECURITY DEFINER
BEGIN
    DECLARE found_id BIGINT UNSIGNED DEFAULT NULL;
    CALL `oa_require_transaction`();
    SELECT `id` INTO found_id FROM `users` WHERE `id`=owner_id FOR UPDATE;
    IF found_id IS NULL THEN SIGNAL SQLSTATE '45010' SET MYSQL_ERRNO=60051, MESSAGE_TEXT='OA authorization rejected'; END IF;
    SELECT found_id AS owner_id;
END
-- OA ROUTINE oa_finalize
CREATE DEFINER='d3a1_oa_verify'@'%' PROCEDURE `oa_finalize`(
    IN p_protocol_version VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_audience VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_operation VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_key_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_command_uuid VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_owner_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_account_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_intent_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_amount VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_direction VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_accounting_date VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_currency VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_account_revision VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_intent_revision VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_issued_at VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_expires_at VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_target_connection_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_tag VARBINARY(64)
) SQL SECURITY DEFINER
BEGIN
    DECLARE savepoint_set BOOLEAN DEFAULT FALSE;
    DECLARE original_errno INT;
    DECLARE missing_savepoint BOOLEAN DEFAULT FALSE;
    DECLARE secret_bytes VARBINARY(64) DEFAULT NULL;
    DECLARE key_state VARCHAR(16);
    DECLARE sign_until BIGINT;
    DECLARE verify_until BIGINT;
    DECLARE now_us BIGINT;
    DECLARE expected VARBINARY(32);
    DECLARE canonical LONGBLOB;
    DECLARE difference INT DEFAULT 0;
    DECLARE byte_index INT DEFAULT 1;
    DECLARE locked_owner BIGINT UNSIGNED DEFAULT NULL;
    DECLARE fact_owner BIGINT UNSIGNED DEFAULT NULL;
    DECLARE fact_account BIGINT UNSIGNED;
    DECLARE account_owner BIGINT UNSIGNED DEFAULT NULL;
    DECLARE account_currency CHAR(3);
    DECLARE account_revision BIGINT UNSIGNED;
    DECLARE fact_amount DECIMAL(18,2);
    DECLARE fact_direction VARCHAR(8);
    DECLARE fact_date DATE;
    DECLARE fact_currency CHAR(3);
    DECLARE fact_revision BIGINT UNSIGNED;
    DECLARE effect_slot BIGINT UNSIGNED;
    DECLARE result_slot BIGINT UNSIGNED;
    DECLARE result_uuid CHAR(36);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET STACKED DIAGNOSTICS CONDITION 1 original_errno = MYSQL_ERRNO;
        IF savepoint_set AND original_errno <> 1213 THEN
            BEGIN
                DECLARE CONTINUE HANDLER FOR 1305 SET missing_savepoint=TRUE;
                ROLLBACK TO SAVEPOINT oa_call;
                RELEASE SAVEPOINT oa_call;
            END;
        END IF;
        RESIGNAL;
    END;
    CALL `oa_require_transaction`();
    SAVEPOINT oa_call;
    SET savepoint_set=TRUE;
    IF p_protocol_version IS NULL OR p_audience IS NULL OR p_operation IS NULL OR p_key_id IS NULL OR p_command_uuid IS NULL OR p_owner_id IS NULL OR p_account_id IS NULL OR p_intent_id IS NULL OR p_amount IS NULL OR p_direction IS NULL OR p_accounting_date IS NULL OR p_currency IS NULL OR p_account_revision IS NULL OR p_intent_revision IS NULL OR p_issued_at IS NULL OR p_expires_at IS NULL OR p_target_connection_id IS NULL OR p_tag IS NULL THEN
        SIGNAL SQLSTATE '45010' SET MYSQL_ERRNO=60051, MESSAGE_TEXT='OA authorization rejected';
    END IF;
    IF LOCATE(CHAR(10),CONCAT(p_protocol_version,p_audience,p_operation,p_key_id,p_command_uuid,p_owner_id,p_account_id,p_intent_id,p_amount,p_direction,p_accounting_date,p_currency,p_account_revision,p_intent_revision,p_issued_at,p_expires_at,p_target_connection_id))>0 OR LOCATE(CHAR(13),CONCAT(p_protocol_version,p_audience,p_operation,p_key_id,p_command_uuid,p_owner_id,p_account_id,p_intent_id,p_amount,p_direction,p_accounting_date,p_currency,p_account_revision,p_intent_revision,p_issued_at,p_expires_at,p_target_connection_id))>0 THEN
        SIGNAL SQLSTATE '45010' SET MYSQL_ERRNO=60051, MESSAGE_TEXT='OA authorization rejected';
    END IF;
    IF BINARY p_protocol_version <> BINARY 'd3a1-oa/1' OR BINARY p_audience <> BINARY 'phase3_d3a1_authority' OR BINARY p_operation <> BINARY 'apply-neutral-intent' OR NOT REGEXP_LIKE(p_key_id, '^[A-Za-z0-9_-]{1,32}$', 'c') OR NOT REGEXP_LIKE(p_command_uuid, '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'c') OR NOT REGEXP_LIKE(p_owner_id, '^(0|[1-9][0-9]{0,18})$', 'c') OR (LENGTH(p_owner_id)=19 AND BINARY p_owner_id > BINARY '9223372036854775807') OR NOT REGEXP_LIKE(p_account_id, '^(0|[1-9][0-9]{0,18})$', 'c') OR (LENGTH(p_account_id)=19 AND BINARY p_account_id > BINARY '9223372036854775807') OR NOT REGEXP_LIKE(p_intent_id, '^(0|[1-9][0-9]{0,18})$', 'c') OR (LENGTH(p_intent_id)=19 AND BINARY p_intent_id > BINARY '9223372036854775807') OR NOT REGEXP_LIKE(p_account_revision, '^(0|[1-9][0-9]{0,18})$', 'c') OR (LENGTH(p_account_revision)=19 AND BINARY p_account_revision > BINARY '9223372036854775807') OR NOT REGEXP_LIKE(p_intent_revision, '^(0|[1-9][0-9]{0,18})$', 'c') OR (LENGTH(p_intent_revision)=19 AND BINARY p_intent_revision > BINARY '9223372036854775807') OR NOT REGEXP_LIKE(p_issued_at, '^(0|[1-9][0-9]{0,18})$', 'c') OR (LENGTH(p_issued_at)=19 AND BINARY p_issued_at > BINARY '9223372036854775807') OR NOT REGEXP_LIKE(p_expires_at, '^(0|[1-9][0-9]{0,18})$', 'c') OR (LENGTH(p_expires_at)=19 AND BINARY p_expires_at > BINARY '9223372036854775807') OR NOT REGEXP_LIKE(p_target_connection_id, '^(0|[1-9][0-9]{0,18})$', 'c') OR (LENGTH(p_target_connection_id)=19 AND BINARY p_target_connection_id > BINARY '9223372036854775807') OR NOT REGEXP_LIKE(p_amount, '^(0|[1-9][0-9]{0,15})[.][0-9]{2}$', 'c') OR CAST(p_amount AS DECIMAL(18,2)) <= 0 OR p_direction NOT IN ('income','expense') OR NOT REGEXP_LIKE(p_currency, '^[A-Z]{3}$', 'c') OR NOT REGEXP_LIKE(p_accounting_date, '^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$', 'c') THEN
        SIGNAL SQLSTATE '45010' SET MYSQL_ERRNO=60051, MESSAGE_TEXT='OA authorization rejected';
    END IF;
    IF SUBSTRING(p_accounting_date,6,2) NOT BETWEEN '01' AND '12'
       OR SUBSTRING(p_accounting_date,9,2) < '01'
       OR CAST(SUBSTRING(p_accounting_date,9,2) AS UNSIGNED) > DAY(LAST_DAY(CONCAT(SUBSTRING(p_accounting_date,1,7),'-01')))
       OR CAST(p_target_connection_id AS UNSIGNED) <> CONNECTION_ID() THEN
        SIGNAL SQLSTATE '45010' SET MYSQL_ERRNO=60051, MESSAGE_TEXT='OA authorization rejected';
    END IF;
    SELECT `secret`, `status`, `sign_until_us`, `verify_until_us` INTO secret_bytes,key_state,sign_until,verify_until
      FROM `proof_keys` WHERE `key_id`=p_key_id FOR SHARE;
    IF secret_bytes IS NULL OR OCTET_LENGTH(secret_bytes)<>32 OR key_state NOT IN ('active','verify_only') THEN
        SIGNAL SQLSTATE '45010' SET MYSQL_ERRNO=60051, MESSAGE_TEXT='OA authorization rejected';
    END IF;
    CALL `oa_clock`(now_us);
    IF CAST(p_issued_at AS UNSIGNED)>now_us OR CAST(p_expires_at AS UNSIGNED)<=now_us
       OR CAST(p_expires_at AS UNSIGNED)<=CAST(p_issued_at AS UNSIGNED)
       OR CAST(p_expires_at AS UNSIGNED)-CAST(p_issued_at AS UNSIGNED)>60000000
       OR CAST(p_issued_at AS UNSIGNED)>sign_until OR now_us>=verify_until THEN
        SIGNAL SQLSTATE '45011' SET MYSQL_ERRNO=60052, MESSAGE_TEXT='OA freshness rejected';
    END IF;
    SET canonical=CONCAT(OCTET_LENGTH(p_protocol_version), _binary':', p_protocol_version, OCTET_LENGTH(p_audience), _binary':', p_audience, OCTET_LENGTH(p_operation), _binary':', p_operation, OCTET_LENGTH(p_key_id), _binary':', p_key_id, OCTET_LENGTH(p_command_uuid), _binary':', p_command_uuid, OCTET_LENGTH(p_owner_id), _binary':', p_owner_id, OCTET_LENGTH(p_account_id), _binary':', p_account_id, OCTET_LENGTH(p_intent_id), _binary':', p_intent_id, OCTET_LENGTH(p_amount), _binary':', p_amount, OCTET_LENGTH(p_direction), _binary':', p_direction, OCTET_LENGTH(p_accounting_date), _binary':', p_accounting_date, OCTET_LENGTH(p_currency), _binary':', p_currency, OCTET_LENGTH(p_account_revision), _binary':', p_account_revision, OCTET_LENGTH(p_intent_revision), _binary':', p_intent_revision, OCTET_LENGTH(p_issued_at), _binary':', p_issued_at, OCTET_LENGTH(p_expires_at), _binary':', p_expires_at, OCTET_LENGTH(p_target_connection_id), _binary':', p_target_connection_id);
    CALL `oa_hmac`(secret_bytes,canonical,expected);
    IF OCTET_LENGTH(p_tag)<>32 OR expected IS NULL THEN
        SIGNAL SQLSTATE '45010' SET MYSQL_ERRNO=60051, MESSAGE_TEXT='OA authorization rejected';
    END IF;
    WHILE byte_index<=32 DO
        SET difference=difference | (ORD(SUBSTRING(expected,byte_index,1)) ^ ORD(SUBSTRING(p_tag,byte_index,1)));
        SET byte_index=byte_index+1;
    END WHILE;
    IF difference<>0 THEN SIGNAL SQLSTATE '45010' SET MYSQL_ERRNO=60051, MESSAGE_TEXT='OA authorization rejected'; END IF;
    BEGIN
        DECLARE EXIT HANDLER FOR 1062 SIGNAL SQLSTATE '45012' SET MYSQL_ERRNO=60053, MESSAGE_TEXT='OA replay rejected';
        INSERT INTO `proof_commands` VALUES(p_command_uuid,CAST(p_owner_id AS UNSIGNED),UNHEX(SHA2(CONCAT(OCTET_LENGTH(p_protocol_version), _binary':', p_protocol_version, OCTET_LENGTH(p_audience), _binary':', p_audience, OCTET_LENGTH(p_operation), _binary':', p_operation, OCTET_LENGTH(p_command_uuid), _binary':', p_command_uuid, OCTET_LENGTH(p_owner_id), _binary':', p_owner_id, OCTET_LENGTH(p_account_id), _binary':', p_account_id, OCTET_LENGTH(p_intent_id), _binary':', p_intent_id, OCTET_LENGTH(p_amount), _binary':', p_amount, OCTET_LENGTH(p_direction), _binary':', p_direction, OCTET_LENGTH(p_accounting_date), _binary':', p_accounting_date, OCTET_LENGTH(p_currency), _binary':', p_currency, OCTET_LENGTH(p_account_revision), _binary':', p_account_revision, OCTET_LENGTH(p_intent_revision), _binary':', p_intent_revision),256)));
    END;
    SELECT u.`id` INTO locked_owner FROM `users` u WHERE u.`id`=CAST(p_owner_id AS UNSIGNED) FOR UPDATE;
    IF locked_owner IS NULL THEN SIGNAL SQLSTATE '45010' SET MYSQL_ERRNO=60051, MESSAGE_TEXT='OA authorization rejected'; END IF;
    SELECT `owner_id`,`currency`,`revision` INTO account_owner,account_currency,account_revision FROM `proof_accounts` WHERE `id`=CAST(p_account_id AS UNSIGNED) FOR UPDATE;
    SELECT i.`owner_id`,i.`account_id`,i.`amount`,i.`direction`,i.`accounting_date`,i.`currency`,i.`revision`,i.`effect_slot`,i.`result_slot`
      INTO fact_owner,fact_account,fact_amount,fact_direction,fact_date,fact_currency,fact_revision,effect_slot,result_slot
      FROM `proof_intents` i WHERE i.`id`=CAST(p_intent_id AS UNSIGNED) FOR UPDATE;
    IF account_owner IS NULL OR fact_owner IS NULL OR account_owner<>CAST(p_owner_id AS UNSIGNED) OR fact_owner<>CAST(p_owner_id AS UNSIGNED)
       OR fact_account<>CAST(p_account_id AS UNSIGNED) OR fact_amount<>CAST(p_amount AS DECIMAL(18,2))
       OR BINARY fact_direction<>BINARY p_direction OR fact_date<>CAST(p_accounting_date AS DATE)
       OR BINARY fact_currency<>BINARY p_currency OR BINARY account_currency<>BINARY p_currency
       OR fact_revision<>CAST(p_intent_revision AS UNSIGNED) OR account_revision<>CAST(p_account_revision AS UNSIGNED) THEN
        SIGNAL SQLSTATE '45010' SET MYSQL_ERRNO=60051, MESSAGE_TEXT='OA authorization rejected';
    END IF;
    -- Reconstruct again from locked facts, never a caller-provided blob.
    SET p_owner_id=CAST(fact_owner AS CHAR CHARACTER SET ascii);
    SET p_account_id=CAST(fact_account AS CHAR CHARACTER SET ascii);
    SET p_amount=CAST(fact_amount AS CHAR CHARACTER SET ascii);
    SET p_direction=fact_direction;
    SET p_accounting_date=DATE_FORMAT(fact_date,'%Y-%m-%d');
    SET p_currency=fact_currency;
    SET p_account_revision=CAST(account_revision AS CHAR CHARACTER SET ascii);
    SET p_intent_revision=CAST(fact_revision AS CHAR CHARACTER SET ascii);
    SET canonical=CONCAT(OCTET_LENGTH(p_protocol_version), _binary':', p_protocol_version, OCTET_LENGTH(p_audience), _binary':', p_audience, OCTET_LENGTH(p_operation), _binary':', p_operation, OCTET_LENGTH(p_key_id), _binary':', p_key_id, OCTET_LENGTH(p_command_uuid), _binary':', p_command_uuid, OCTET_LENGTH(p_owner_id), _binary':', p_owner_id, OCTET_LENGTH(p_account_id), _binary':', p_account_id, OCTET_LENGTH(p_intent_id), _binary':', p_intent_id, OCTET_LENGTH(p_amount), _binary':', p_amount, OCTET_LENGTH(p_direction), _binary':', p_direction, OCTET_LENGTH(p_accounting_date), _binary':', p_accounting_date, OCTET_LENGTH(p_currency), _binary':', p_currency, OCTET_LENGTH(p_account_revision), _binary':', p_account_revision, OCTET_LENGTH(p_intent_revision), _binary':', p_intent_revision, OCTET_LENGTH(p_issued_at), _binary':', p_issued_at, OCTET_LENGTH(p_expires_at), _binary':', p_expires_at, OCTET_LENGTH(p_target_connection_id), _binary':', p_target_connection_id);
    CALL `oa_hmac`(secret_bytes,canonical,expected);
    IF NOT (BINARY expected <=> BINARY p_tag) THEN SIGNAL SQLSTATE '45010' SET MYSQL_ERRNO=60051, MESSAGE_TEXT='OA authorization rejected'; END IF;
    CALL `oa_clock`(now_us);
    IF CAST(p_expires_at AS UNSIGNED)<=now_us OR now_us>=verify_until THEN SIGNAL SQLSTATE '45011' SET MYSQL_ERRNO=60052, MESSAGE_TEXT='OA freshness rejected'; END IF;
    INSERT INTO `proof_effects` VALUES(p_command_uuid,effect_slot,CAST(p_owner_id AS UNSIGNED),fact_amount);
    SET result_uuid=UUID();
    INSERT INTO `proof_results` VALUES(p_command_uuid,result_uuid,result_slot);
    RELEASE SAVEPOINT oa_call;
    SET savepoint_set=FALSE;
    SELECT result_uuid AS result_uuid;
END
-- OA TRIGGER oa_intent_guard
CREATE DEFINER='d3a1_oa_verify'@'%' TRIGGER `oa_intent_guard` BEFORE UPDATE ON `proof_intents` FOR EACH ROW
BEGIN
    IF @@session.foreign_key_checks <> 1 OR @@session.unique_checks <> 1 THEN
        SIGNAL SQLSTATE '45013' SET MYSQL_ERRNO=60054, MESSAGE_TEXT='OA prerequisite unavailable';
    END IF;
END
