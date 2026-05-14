-- ============================================================
-- Session 1 migration — pipeline hardening
--   * sender_addresses     (multi-from dropdown)
--   * event_attendees      (scanned-ticket attribution, seed for CDP)
--   * audit_log            (dashboard ops trail)
--   * rate_limit_log       (login + API throttling)
--   * import_jobs          (record every CSV pull, idempotent rerun)
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ------------------------------------------------------------
-- sender_addresses
-- Verified "From" identities you can pick in the Compose UI.
-- Populated manually after each identity is verified in SES.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sender_addresses (
    id              SMALLINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    email           VARCHAR(255)       NOT NULL,
    display_name    VARCHAR(255)       NOT NULL,
    purpose         VARCHAR(64)        NOT NULL,        -- e.g. 'general','talent','events','community'
    reply_to        VARCHAR(255)       DEFAULT NULL,
    is_default      TINYINT(1)         NOT NULL DEFAULT 0,
    is_active       TINYINT(1)         NOT NULL DEFAULT 1,
    ses_verified_at DATETIME           DEFAULT NULL,
    sort_order      SMALLINT           NOT NULL DEFAULT 100,
    created_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sender_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sender_addresses (email, display_name, purpose, reply_to, is_default, sort_order, ses_verified_at)
VALUES
    ('info@moonlightotakunights.com',    'Moonlight Otaku Nights',           'general',   'info@moonlightotakunights.com',    1, 10, NOW()),
    ('hello@moonlightotakunights.com',   'Moonlight Otaku Nights',           'community', 'hello@moonlightotakunights.com',   0, 20, NULL),
    ('events@moonlightotakunights.com',  'Moonlight Otaku Nights · Events',  'events',    'events@moonlightotakunights.com',  0, 30, NULL),
    ('talent@moonlightotakunights.com',  'Moonlight Otaku Nights · Talent',  'talent',    'talent@moonlightotakunights.com',  0, 40, NULL)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);


-- ------------------------------------------------------------
-- event_attendees
-- One row per ticket that actually scanned in at the door.
-- Reconciles Posh export rows (status + scan_status) into the
-- Guild contact graph. Foundation for Session 2's persons DB.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS event_attendees (
    id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    event_id            INT UNSIGNED      NOT NULL,
    contact_id          INT UNSIGNED      DEFAULT NULL,
    email               VARCHAR(255)      DEFAULT NULL,
    name                VARCHAR(255)      DEFAULT NULL,
    phone               VARCHAR(32)       DEFAULT NULL,
    instagram           VARCHAR(64)       DEFAULT NULL,
    -- Order metadata
    order_external_id   VARCHAR(120)      DEFAULT NULL,    -- Posh order id
    ticket_tier         VARCHAR(120)      DEFAULT NULL,
    ticket_price        DECIMAL(10,2)     DEFAULT NULL,
    purchase_amount     DECIMAL(10,2)     DEFAULT NULL,
    purchase_currency   CHAR(3)           DEFAULT 'USD',
    purchase_status     VARCHAR(64)       DEFAULT NULL,    -- completed / refunded / pending
    purchased_at        DATETIME          DEFAULT NULL,
    -- Scan / attendance
    scanned             TINYINT(1)        NOT NULL DEFAULT 0,
    scanned_at          DATETIME          DEFAULT NULL,
    -- Demographics from Posh export (city / gender / promo)
    city                VARCHAR(120)      DEFAULT NULL,
    state_region        VARCHAR(120)      DEFAULT NULL,
    country             VARCHAR(80)       DEFAULT NULL,
    gender              VARCHAR(32)       DEFAULT NULL,
    promo_code          VARCHAR(64)       DEFAULT NULL,
    source_platform     VARCHAR(32)       NOT NULL DEFAULT 'posh',  -- posh / eventbrite / door / comp
    -- Free-form
    raw_payload         JSON              DEFAULT NULL,
    notes               TEXT              DEFAULT NULL,
    created_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_order (event_id, order_external_id),
    KEY idx_ea_event (event_id),
    KEY idx_ea_contact (contact_id),
    KEY idx_ea_email (email),
    KEY idx_ea_scanned (scanned),
    CONSTRAINT fk_ea_event   FOREIGN KEY (event_id)   REFERENCES events(id)   ON DELETE CASCADE,
    CONSTRAINT fk_ea_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- audit_log
-- Every meaningful operator action in the dashboard.
-- Read in /diag.php and the future Operations module.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_email      VARCHAR(255)      DEFAULT NULL,
    action          VARCHAR(64)       NOT NULL,        -- e.g. 'contact.create','import.csv','broadcast.send'
    object_type     VARCHAR(64)       DEFAULT NULL,    -- e.g. 'contact','broadcast','event'
    object_id       VARCHAR(64)       DEFAULT NULL,
    summary         VARCHAR(500)      DEFAULT NULL,
    details         JSON              DEFAULT NULL,
    ip_hash         CHAR(64)          DEFAULT NULL,
    user_agent      VARCHAR(500)      DEFAULT NULL,
    created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_email),
    KEY idx_audit_action (action),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- rate_limit_log
-- Sliding-window counter for login attempts and API endpoints.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limit_log (
    id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    scope           VARCHAR(64)       NOT NULL,        -- e.g. 'otp_request','api.import'
    key_hash        CHAR(64)          NOT NULL,        -- SHA-256(email or ip)
    created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rl_scope_key (scope, key_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- import_jobs
-- Records each CSV import for reproducibility + AI-readable digest.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_jobs (
    id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    user_email      VARCHAR(255)      DEFAULT NULL,
    source          VARCHAR(64)       NOT NULL,        -- 'posh','brevo','cosplay','generic'
    event_id        INT UNSIGNED      DEFAULT NULL,
    filename        VARCHAR(255)      DEFAULT NULL,
    rows_total      INT UNSIGNED      NOT NULL DEFAULT 0,
    rows_created    INT UNSIGNED      NOT NULL DEFAULT 0,
    rows_updated    INT UNSIGNED      NOT NULL DEFAULT 0,
    rows_skipped    INT UNSIGNED      NOT NULL DEFAULT 0,
    rows_attendees  INT UNSIGNED      NOT NULL DEFAULT 0,
    status          ENUM('running','complete','failed') NOT NULL DEFAULT 'running',
    errors          JSON              DEFAULT NULL,
    detected_schema JSON              DEFAULT NULL,    -- which columns mapped to what
    started_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at     DATETIME          DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_ij_source (source),
    KEY idx_ij_event (event_id),
    KEY idx_ij_started (started_at),
    CONSTRAINT fk_ij_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
