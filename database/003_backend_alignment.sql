-- =============================================================
-- 003_backend_alignment.sql
-- Aligns the schema with docs/api-contract.md and docs/BACKEND_BRIEF.md.
-- Run AFTER 001 and 002, once. Not re-runnable (a second run fails on duplicate columns).
-- Tested on MySQL 8.0 (also the engine Aiven uses). XAMPP's MariaDB 10.4+ should work: it uses
-- only portable syntax (DROP CONSTRAINT, drop-and-recreate of generated columns).
--
-- What changes
--   transcript_requests : 10-value status ENUM, destination + delivery + copies, sent_at,
--                         expires_at (payment deadline), rebuilt open-request rule, new CHECKs
--   payments            : gateway 'simulated', receipt_no, refunded_at
--   new tables          : idempotency_keys, login_attempts, password_reset_tokens
-- =============================================================

USE transcript_system;

-- -------------------------------------------------------------
-- 1. transcript_requests
-- -------------------------------------------------------------

-- Drop what depends on the status values, so the ENUM can change safely
-- (MySQL and MariaDB differ on in-place changes to generated columns).
ALTER TABLE transcript_requests DROP INDEX uq_one_open_request;
ALTER TABLE transcript_requests DROP COLUMN open_student_id;
ALTER TABLE transcript_requests DROP CONSTRAINT chk_req_ready;

-- Canonical status list from the API contract.
ALTER TABLE transcript_requests
    MODIFY COLUMN status ENUM(
        'pending_payment','payment_processing','paid','processing','ready',
        'sent','failed','cancelled','rejected','expired'
    ) NOT NULL DEFAULT 'pending_payment';

-- Destination, delivery and copies. Defaults make every existing row a valid personal copy.
ALTER TABLE transcript_requests
    ADD COLUMN destination_type    ENUM('personal','institution') NOT NULL DEFAULT 'personal',
    ADD COLUMN institution_name    VARCHAR(150) NULL,
    ADD COLUMN destination_address VARCHAR(255) NULL,
    ADD COLUMN destination_email   VARCHAR(120) NULL,
    ADD COLUMN delivery_method     ENUM('download','email','courier') NOT NULL DEFAULT 'download',
    ADD COLUMN copies              TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN sent_at             DATETIME NULL,
    -- Payment deadline for an unpaid request (e.g. created_at + 24h). Backend expires lazily.
    ADD COLUMN expires_at          DATETIME NULL;

-- One open request per student. "Open" = anything not yet finished or closed.
ALTER TABLE transcript_requests
    ADD COLUMN open_student_id INT UNSIGNED
        GENERATED ALWAYS AS (
            IF(status IN ('pending_payment','payment_processing','failed','paid','processing'),
               student_id, NULL)
        ) VIRTUAL;
ALTER TABLE transcript_requests ADD UNIQUE KEY uq_one_open_request (open_student_id);

ALTER TABLE transcript_requests
    ADD CONSTRAINT chk_req_copies CHECK (copies BETWEEN 1 AND 3),
    -- personal = download, no institution fields; institution = email (needs email) or courier (needs address)
    ADD CONSTRAINT chk_req_destination CHECK (
        (destination_type = 'personal'
            AND delivery_method = 'download'
            AND institution_name IS NULL AND destination_address IS NULL AND destination_email IS NULL)
        OR
        (destination_type = 'institution'
            AND institution_name IS NOT NULL
            AND ((delivery_method = 'email'   AND destination_email   IS NOT NULL)
              OR (delivery_method = 'courier' AND destination_address IS NOT NULL)))
    ),
    -- Issued transcript record must be complete once ready or sent.
    ADD CONSTRAINT chk_req_issued CHECK (
        status NOT IN ('ready','sent') OR (
            transcript_no IS NOT NULL AND total_units IS NOT NULL
            AND cgpa IS NOT NULL AND ready_at IS NOT NULL
        )
    ),
    -- ready = personal copy; sent = institution copy that was dispatched.
    ADD CONSTRAINT chk_req_ready_personal CHECK (status <> 'ready' OR destination_type = 'personal'),
    ADD CONSTRAINT chk_req_sent CHECK (
        status <> 'sent' OR (destination_type = 'institution' AND sent_at IS NOT NULL)
    ),
    ADD CONSTRAINT chk_req_rejected CHECK (status <> 'rejected' OR rejection_reason IS NOT NULL);

-- -------------------------------------------------------------
-- 2. payments
-- -------------------------------------------------------------
-- 'simulated' is the demo mode; backend must refuse it when PAYMENT_MODE is not simulated.
ALTER TABLE payments
    MODIFY COLUMN gateway ENUM('paystack','simulated') NOT NULL DEFAULT 'simulated',
    ADD COLUMN receipt_no  VARCHAR(20) NULL,             -- e.g. RCPT-2026-1001, set when verified
    ADD COLUMN refunded_at DATETIME NULL,                -- set if a paid request is later refunded
    ADD UNIQUE KEY uq_receipt_no (receipt_no);

-- -------------------------------------------------------------
-- 3. idempotency_keys  (create.php and pay.php)
-- -------------------------------------------------------------
-- Same key + same request_hash  -> return the stored response.
-- Same key + different hash     -> 409 idempotency_conflict.
-- response_* stay NULL while the first request is still running.
-- 'endpoint' should include the resource, e.g. 'transcripts/pay:5'.
CREATE TABLE idempotency_keys (
    id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    student_id       INT UNSIGNED      NOT NULL,
    endpoint         VARCHAR(100)      NOT NULL,
    idem_key         VARCHAR(100)      NOT NULL,           -- 'key' is a reserved word, so not named key
    request_hash     CHAR(64)          NOT NULL,           -- sha256 of the request body
    response_status  SMALLINT UNSIGNED NULL,
    response_body    JSON              NULL,
    created_at       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idem (student_id, endpoint, idem_key),
    KEY idx_idem_created (created_at),
    CONSTRAINT fk_idem_student
        FOREIGN KEY (student_id) REFERENCES students (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- 4. login_attempts  (login and password-reset throttling)
-- -------------------------------------------------------------
-- Suggested rule: 5 failed logins per 15 minutes per matric number + IP.
-- Delete rows older than a day whenever convenient.
CREATE TABLE login_attempts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind          ENUM('login','reset') NOT NULL DEFAULT 'login',
    identifier    VARCHAR(120)    NOT NULL,                -- uppercase matric number, or email for reset
    ip_address    VARCHAR(45)     NOT NULL,
    success       TINYINT(1)      NOT NULL DEFAULT 0,
    attempted_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attempts_identifier (kind, identifier, attempted_at),
    KEY idx_attempts_ip (kind, ip_address, attempted_at)
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- 5. password_reset_tokens
-- -------------------------------------------------------------
-- Store only the SHA-256 hash of the token, never the token itself.
-- Single use: backend runs  UPDATE ... SET used_at = NOW()
--             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
-- and checks that exactly one row changed.
CREATE TABLE password_reset_tokens (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id   INT UNSIGNED NOT NULL,
    token_hash   CHAR(64)     NOT NULL,
    expires_at   DATETIME     NOT NULL,
    used_at      DATETIME     NULL,
    ip_address   VARCHAR(45)  NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reset_token_hash (token_hash),
    KEY idx_reset_student (student_id, expires_at),
    CONSTRAINT fk_reset_student
        FOREIGN KEY (student_id) REFERENCES students (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;