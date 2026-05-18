-- ============================================================
-- Session 7 migration — outbox approval queue
--
-- Every automated email triggered by an external event (form
-- submission, webhook, cron) queues a draft here instead of
-- sending directly. The operator reviews and approves in
-- dashboard/outbox.php before it goes out via SES.
--
-- Operator-initiated emails (OTP, manual broadcasts, test sends)
-- continue to use ses_send() directly and never touch outbox.
--
-- All DDL is idempotent.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ------------------------------------------------------------
-- outbox — pending email drafts awaiting operator review
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS outbox (
    id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    -- What kind of email this is (so the dashboard can group/filter)
    kind            VARCHAR(60)       NOT NULL,
        -- e.g. submission_ack, submission_ops, cosplay_ack,
        -- guild_signup_confirm, donation_ops, etc.
    -- Source funnel (optional — for per-funnel rules + analytics)
    funnel          VARCHAR(40)       DEFAULT NULL,
        -- sponsor, investor, dj, idol, vendor, cosplay, guild, donation
    -- The actual draft
    to_email        VARCHAR(255)      NOT NULL,
    to_name         VARCHAR(160)      DEFAULT NULL,
    subject         VARCHAR(255)      NOT NULL,
    html_body       MEDIUMTEXT        NOT NULL,
    reply_to        VARCHAR(255)      DEFAULT NULL,
    from_email      VARCHAR(255)      DEFAULT NULL,
    from_name       VARCHAR(160)      DEFAULT NULL,
    -- Lifecycle
    status          ENUM('pending','approved','sent','rejected','failed')
                                      NOT NULL DEFAULT 'pending',
    -- Optional context (submission id, webhook id, etc.) for traceability
    source_table    VARCHAR(60)       DEFAULT NULL,
    source_id       BIGINT UNSIGNED   DEFAULT NULL,
    -- Action audit
    created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at     DATETIME          DEFAULT NULL,
    reviewed_by     VARCHAR(160)      DEFAULT NULL,
    sent_at         DATETIME          DEFAULT NULL,
    ses_log_id      BIGINT UNSIGNED   DEFAULT NULL,
    error_summary   VARCHAR(500)      DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_outbox_status_created (status, created_at),
    KEY idx_outbox_kind (kind),
    KEY idx_outbox_funnel (funnel),
    KEY idx_outbox_source (source_table, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- outbox_actions — append-only audit log of every state change
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS outbox_actions (
    id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    outbox_id       BIGINT UNSIGNED   NOT NULL,
    action          ENUM('queued','viewed','edited','approved','rejected','sent','failed','retried')
                                      NOT NULL,
    actor           VARCHAR(160)      DEFAULT NULL,  -- operator email or 'system'
    note            VARCHAR(500)      DEFAULT NULL,
    created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_oba_outbox (outbox_id, created_at),
    CONSTRAINT fk_oba_outbox FOREIGN KEY (outbox_id) REFERENCES outbox(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
