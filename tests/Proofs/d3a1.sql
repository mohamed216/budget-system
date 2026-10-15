-- TEST ONLY. Base --install requires an EMPTY phase3_d3a1_proof schema on the
-- named isolated MySQL 8.4.11 container. The separate guard installation requires
-- its two dedicated tables/four triggers to be absent, not an empty schema.
-- Use the test owner or isolated-admin guard provisioning. Never use the app DB.
-- The standalone runner uses the statement markers, not mysql DELIMITER syntax.
-- D3A1 STATEMENT
CREATE TABLE caller_writes (id INT PRIMARY KEY) ENGINE=InnoDB
-- D3A1 STATEMENT
CREATE TABLE protected_writes (
    id INT PRIMARY KEY,
    value INT NOT NULL,
    CONSTRAINT positive_value CHECK (value > 0)
) ENGINE=InnoDB
-- D3A1 STATEMENT
CREATE TABLE parent_rows (id INT PRIMARY KEY) ENGINE=InnoDB
-- D3A1 STATEMENT
INSERT INTO parent_rows VALUES (1)
-- D3A1 STATEMENT
CREATE TABLE child_rows (
    id INT PRIMARY KEY, parent_id INT NOT NULL,
    FOREIGN KEY (parent_id) REFERENCES parent_rows(id)
) ENGINE=InnoDB
-- D3A1 STATEMENT
CREATE TABLE lock_rows (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB
-- D3A1 STATEMENT
INSERT INTO lock_rows VALUES (1, 1), (2, 1)
-- D3A1 STATEMENT
CREATE TABLE authority_key (id INT PRIMARY KEY, secret VARBINARY(64) NOT NULL) ENGINE=InnoDB
-- D3A1 STATEMENT
CREATE PROCEDURE proof_hmac(IN secret LONGBLOB, IN payload LONGBLOB)
SQL SECURITY DEFINER
BEGIN
    DECLARE padded VARBINARY(64);
    IF OCTET_LENGTH(secret) > 64 THEN
        SET secret = UNHEX(SHA2(secret, 256));
    END IF;
    SET padded = CONCAT(secret, REPEAT(CHAR(0 USING binary), 64 - OCTET_LENGTH(secret)));
    -- RFC 2104 HMAC with SHA-256, binary XOR and binary inner digest.
    SELECT UNHEX(SHA2(CONCAT(
        padded ^ UNHEX(REPEAT('5c', 64)),
        UNHEX(SHA2(CONCAT(padded ^ UNHEX(REPEAT('36', 64)), payload), 256))
    ), 256)) AS tag;
END
-- D3A1 STATEMENT
CREATE PROCEDURE proof_verify(IN intended_connection BIGINT UNSIGNED, IN payload LONGBLOB, IN tag VARBINARY(32))
SQL SECURITY DEFINER
BEGIN
    DECLARE secret VARBINARY(64);
    DECLARE padded VARBINARY(64);
    DECLARE expected VARBINARY(32);
    DECLARE different INT DEFAULT 0;
    DECLARE position_index INT DEFAULT 1;
    SELECT k.secret INTO secret FROM authority_key k WHERE id = 1;
    SET padded = CONCAT(secret, REPEAT(CHAR(0 USING binary), 64 - OCTET_LENGTH(secret)));
    SET expected = UNHEX(SHA2(CONCAT(
        padded ^ UNHEX(REPEAT('5c', 64)),
        UNHEX(SHA2(CONCAT(padded ^ UNHEX(REPEAT('36', 64)),
            _binary'd3a1-proof-connection:', CAST(intended_connection AS CHAR CHARACTER SET ascii),
            CHAR(0 USING binary), payload), 256))
    ), 256));
    IF expected IS NULL OR tag IS NULL OR OCTET_LENGTH(tag) <> 32 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Proof invalid MAC';
    END IF;
    -- Fixed iteration count; SQL timing behavior still needs independent review.
    WHILE position_index <= 32 DO
        SET different = different | (ORD(SUBSTRING(expected, position_index, 1)) ^ ORD(SUBSTRING(tag, position_index, 1)));
        SET position_index = position_index + 1;
    END WHILE;
    IF different <> 0 OR intended_connection <> CONNECTION_ID() THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Proof invalid authority';
    END IF;
    -- Opaque byte verification ONLY: not a posting or owner authorization routine.
    -- Future code must reconstruct the payload from typed arguments, including
    -- intended_connection, rather than accept unrelated payload/ID arguments.
END
-- D3A1 STATEMENT
CREATE PROCEDURE proof_failure(IN failure_kind VARCHAR(16), IN base_id INT)
SQL SECURITY DEFINER
BEGIN
    DECLARE original_errno INT;
    DECLARE missing_savepoint BOOLEAN DEFAULT FALSE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET STACKED DIAGNOSTICS CONDITION 1 original_errno = MYSQL_ERRNO;
        IF original_errno <> 1213 THEN
            BEGIN
                -- Full transaction abort can already have deleted the savepoint.
                -- Swallow ONLY that condition during cleanup, never the original.
                DECLARE CONTINUE HANDLER FOR 1305 SET missing_savepoint = TRUE;
                ROLLBACK TO SAVEPOINT d3a1_routine;
                RELEASE SAVEPOINT d3a1_routine;
            END;
        END IF;
        RESIGNAL;
    END;
    SAVEPOINT d3a1_routine;
    IF failure_kind = 'before' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Proof before writes';
    END IF;
    INSERT INTO protected_writes VALUES (base_id, 1);
    IF failure_kind = 'first' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Proof after first write';
    END IF;
    INSERT INTO protected_writes VALUES (base_id + 1, 1);
    CASE failure_kind
        WHEN 'second' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Proof after second write';
        WHEN 'duplicate' THEN INSERT INTO protected_writes VALUES (base_id, 1);
        WHEN 'fk' THEN INSERT INTO child_rows VALUES (base_id, -1);
        WHEN 'check' THEN INSERT INTO protected_writes VALUES (base_id + 2, 0);
        WHEN 'timeout' THEN UPDATE lock_rows SET value = value + 1 WHERE id = 2;
        ELSE SET missing_savepoint = FALSE;
    END CASE;
    RELEASE SAVEPOINT d3a1_routine;
END
-- D3A1 STATEMENT
CREATE PROCEDURE proof_transaction()
SQL SECURITY DEFINER
BEGIN
    DECLARE anchor INT;
    DECLARE observed_state VARCHAR(16) DEFAULT NULL;
    DECLARE observed_autocommit VARCHAR(3) DEFAULT NULL;
    DECLARE observed_end BIGINT UNSIGNED DEFAULT NULL;
    -- Force lazy transaction instrumentation to materialize using an InnoDB read.
    -- This read is NOT permission authority and does not create an explicit BEGIN.
    SELECT id INTO anchor FROM parent_rows WHERE id = 1;
    SELECT e.STATE, e.AUTOCOMMIT, e.END_EVENT_ID
        INTO observed_state, observed_autocommit, observed_end
        FROM performance_schema.events_transactions_current e
        INNER JOIN performance_schema.threads t ON t.THREAD_ID = e.THREAD_ID
        WHERE t.PROCESSLIST_ID = CONNECTION_ID();
    IF @@session.autocommit <> 1 OR observed_state IS NULL OR observed_state <> 'ACTIVE'
        OR observed_autocommit <> 'NO' OR observed_end IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Proof requires explicit caller transaction';
    END IF;
    SELECT observed_state AS state, observed_autocommit AS autocommit;
END

-- The guard fixture is installed separately with --install-constraint-guard.
-- These neutral tables deliberately leave the normal proof fixtures untouched.
-- Explicit test-owner DEFINER also permits isolated-admin provisioning without
-- changing server binary-log settings. Runtime gets DML only, never TRIGGER.
-- D3A1 GUARD STATEMENT
CREATE TABLE d3a1_guard_parents (
    id INT PRIMARY KEY,
    value INT NOT NULL,
    CONSTRAINT d3a1_guard_parent_positive CHECK (value > 0)
) ENGINE=InnoDB
-- D3A1 GUARD STATEMENT
CREATE TABLE d3a1_guard_children (
    id INT PRIMARY KEY,
    parent_id INT NOT NULL,
    value INT NOT NULL,
    CONSTRAINT d3a1_guard_child_parent FOREIGN KEY (parent_id) REFERENCES d3a1_guard_parents(id),
    CONSTRAINT d3a1_guard_child_positive CHECK (value > 0)
) ENGINE=InnoDB
-- D3A1 GUARD STATEMENT
CREATE DEFINER = 'd3a1_proof_owner'@'%' TRIGGER d3a1_guard_child_insert BEFORE INSERT ON d3a1_guard_children
FOR EACH ROW
BEGIN
    IF @@session.foreign_key_checks <> 1 OR @@session.unique_checks <> 1 THEN
        SIGNAL SQLSTATE '45003' SET MYSQL_ERRNO = 60031,
            MESSAGE_TEXT = 'D3A1 constraint-state guard: checks must be enabled';
    END IF;
END
-- D3A1 GUARD STATEMENT
CREATE DEFINER = 'd3a1_proof_owner'@'%' TRIGGER d3a1_guard_child_update BEFORE UPDATE ON d3a1_guard_children
FOR EACH ROW
BEGIN
    IF @@session.foreign_key_checks <> 1 OR @@session.unique_checks <> 1 THEN
        SIGNAL SQLSTATE '45003' SET MYSQL_ERRNO = 60031,
            MESSAGE_TEXT = 'D3A1 constraint-state guard: checks must be enabled';
    END IF;
END
-- D3A1 GUARD STATEMENT
CREATE DEFINER = 'd3a1_proof_owner'@'%' TRIGGER d3a1_guard_parent_update BEFORE UPDATE ON d3a1_guard_parents
FOR EACH ROW
BEGIN
    IF @@session.foreign_key_checks <> 1 OR @@session.unique_checks <> 1 THEN
        SIGNAL SQLSTATE '45003' SET MYSQL_ERRNO = 60031,
            MESSAGE_TEXT = 'D3A1 constraint-state guard: checks must be enabled';
    END IF;
END
-- D3A1 GUARD STATEMENT
CREATE DEFINER = 'd3a1_proof_owner'@'%' TRIGGER d3a1_guard_parent_delete BEFORE DELETE ON d3a1_guard_parents
FOR EACH ROW
BEGIN
    IF @@session.foreign_key_checks <> 1 OR @@session.unique_checks <> 1 THEN
        SIGNAL SQLSTATE '45003' SET MYSQL_ERRNO = 60031,
            MESSAGE_TEXT = 'D3A1 constraint-state guard: checks must be enabled';
    END IF;
END
