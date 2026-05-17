-- ============================================================
-- Session 5 migration — ticketing webhooks + email attribution
--
-- All ALTERs guarded against schema drift via INFORMATION_SCHEMA
-- so this is idempotent on re-runs.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ------------------------------------------------------------
-- event_attendees: distinguish source (posh / eventbrite / door / comp / webhook)
-- and add an index on source_platform for the dashboard tickets view.
-- ------------------------------------------------------------
SET @has_src_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_attendees'
      AND INDEX_NAME = 'idx_ea_source_created'
);
SET @sql := IF(@has_src_idx = 0,
    'ALTER TABLE event_attendees ADD KEY idx_ea_source_created (source_platform, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- ticketing_webhook_log — append-only audit of incoming webhooks
-- so we can replay or debug delivery from Posh / Eventbrite.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticketing_webhook_log (
    id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    provider        ENUM('posh','eventbrite') NOT NULL,
    event_type      VARCHAR(80)       DEFAULT NULL,   -- e.g. order.purchased
    external_id     VARCHAR(120)      DEFAULT NULL,   -- order id / hook id
    signature_ok    TINYINT(1)        NOT NULL DEFAULT 0,
    http_status     SMALLINT          NOT NULL DEFAULT 200,
    body_hash       CHAR(64)          DEFAULT NULL,   -- sha256 of raw body for dedupe
    payload         JSON              DEFAULT NULL,
    error_summary   VARCHAR(500)      DEFAULT NULL,
    created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_provider_body (provider, body_hash),
    KEY idx_twl_provider_created (provider, created_at),
    KEY idx_twl_external (provider, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- contacts: capture first-touch UTM so we can show
-- "Email → Signup" attribution on the analytics page.
-- ------------------------------------------------------------
SET @has_utm_source := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'utm_source'
);
SET @sql := IF(@has_utm_source = 0,
    'ALTER TABLE contacts
        ADD COLUMN utm_source   VARCHAR(80)  DEFAULT NULL AFTER first_source,
        ADD COLUMN utm_medium   VARCHAR(80)  DEFAULT NULL AFTER utm_source,
        ADD COLUMN utm_campaign VARCHAR(120) DEFAULT NULL AFTER utm_medium,
        ADD COLUMN utm_content  VARCHAR(120) DEFAULT NULL AFTER utm_campaign,
        ADD KEY idx_contacts_utm (utm_source, utm_campaign)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ------------------------------------------------------------
-- broadcast_log: add click tracking so the analytics page can
-- pivot Email → Signup by the broadcast that drove the visit.
-- (Existing table from session 1.5; column is additive.)
-- ------------------------------------------------------------
SET @has_bl := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'broadcast_log'
);
SET @has_bl_clicks := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'broadcast_log' AND COLUMN_NAME = 'utm_campaign'
);
SET @sql := IF(@has_bl = 1 AND @has_bl_clicks = 0,
    'ALTER TABLE broadcast_log
        ADD COLUMN utm_campaign VARCHAR(120) DEFAULT NULL,
        ADD KEY idx_bl_utm_campaign (utm_campaign)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
