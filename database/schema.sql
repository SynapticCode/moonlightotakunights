-- ============================================================
-- Moonlight Otaku Nights — Phase 1 Dashboard Schema
-- Target: MySQL 8.0+ (Hostinger u833453975_mon_dashboard)
-- Charset: utf8mb4 / utf8mb4_unicode_ci  (full emoji + JP support)
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ------------------------------------------------------------
-- events
-- Master list of events (past + upcoming). Drives the cosplay
-- contest gate, broadcast tagging, and the public archive.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
    id              INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(120)       NOT NULL,
    name            VARCHAR(255)       NOT NULL,
    venue           VARCHAR(255)       DEFAULT NULL,
    event_date      DATE               DEFAULT NULL,
    status          ENUM('upcoming','live','past','cancelled')
                                       NOT NULL DEFAULT 'upcoming',
    cosplay_contest_active TINYINT(1)  NOT NULL DEFAULT 0,
    cosplay_contest_close  DATETIME    DEFAULT NULL,
    ticket_url      VARCHAR(500)       DEFAULT NULL,
    page_path       VARCHAR(255)       DEFAULT NULL,
    notes           TEXT               DEFAULT NULL,
    created_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_events_slug (slug),
    KEY idx_events_status (status),
    KEY idx_events_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: known events (idempotent)
INSERT INTO events (slug, name, venue, event_date, status, page_path)
VALUES
    ('hatsune-miku-after-party', 'Hatsune Miku Unofficial After Party', 'QXT''s Nightclub', '2026-05-07', 'past', '/hatsune-miku-after-party/'),
    ('elven-grove',              'Elven Grove',                         NULL,                NULL,         'past',  '/past-events/')
ON DUPLICATE KEY UPDATE name = VALUES(name);


-- ------------------------------------------------------------
-- contacts
-- The Guild's master contact table. One row per unique email.
-- Sources contribute multiple rows in contact_sources to track
-- where/when this person joined.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contacts (
    id                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    email             VARCHAR(255)      NOT NULL,
    name              VARCHAR(255)      DEFAULT NULL,
    phone             VARCHAR(32)       DEFAULT NULL,
    instagram         VARCHAR(64)       DEFAULT NULL,
    -- Lifecycle
    status            ENUM('pending','verified','unsubscribed','bounced','complained','suppressed')
                                        NOT NULL DEFAULT 'pending',
    verified_at       DATETIME          DEFAULT NULL,
    unsubscribed_at   DATETIME          DEFAULT NULL,
    bounced_at        DATETIME          DEFAULT NULL,
    -- Origin / first touch
    first_source      VARCHAR(64)       DEFAULT NULL,   -- e.g. 'guild_homepage'
    first_seen_at     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    -- Engagement counters (cheap denormalised stats)
    total_emails_sent INT UNSIGNED      NOT NULL DEFAULT 0,
    total_opens       INT UNSIGNED      NOT NULL DEFAULT 0,
    total_clicks      INT UNSIGNED      NOT NULL DEFAULT 0,
    last_event_attended INT UNSIGNED    DEFAULT NULL,
    -- Free-form tags as CSV (kept simple for Phase 1; can split later)
    tags              VARCHAR(500)      DEFAULT NULL,
    notes             TEXT              DEFAULT NULL,
    -- Soft delete
    deleted_at        DATETIME          DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contacts_email (email),
    KEY idx_contacts_status (status),
    KEY idx_contacts_first_source (first_source),
    KEY idx_contacts_first_seen (first_seen_at),
    KEY idx_contacts_last_event (last_event_attended),
    CONSTRAINT fk_contacts_last_event FOREIGN KEY (last_event_attended)
        REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- contact_sources
-- Every signup, import, or touchpoint that brought a contact in.
-- One contact can have many sources (joined via guild form,
-- imported from Brevo, attended Miku Vol 2, etc.).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contact_sources (
    id              BIGINT UNSIGNED    NOT NULL AUTO_INCREMENT,
    contact_id      INT UNSIGNED       NOT NULL,
    source          VARCHAR(64)        NOT NULL,
    -- Examples: 'guild_homepage', 'guild_miku_page', 'cosplay_signup',
    -- 'import_formspree', 'import_brevo', 'import_posh', 'import_eventbrite',
    -- 'manual_dashboard'
    source_detail   VARCHAR(255)       DEFAULT NULL,
    event_id        INT UNSIGNED       DEFAULT NULL,
    metadata        JSON               DEFAULT NULL,
    user_agent      VARCHAR(500)       DEFAULT NULL,
    ip_hash         CHAR(64)           DEFAULT NULL,    -- SHA-256 of IP
    created_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cs_contact (contact_id),
    KEY idx_cs_source (source),
    KEY idx_cs_created (created_at),
    CONSTRAINT fk_cs_contact FOREIGN KEY (contact_id)
        REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_cs_event FOREIGN KEY (event_id)
        REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- verification_tokens
-- Double opt-in for the Guild + OTP login for the dashboard.
-- Single table, polymorphic on `purpose`. Tokens are SHA-256
-- hashes of the random string — only the hash is stored.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS verification_tokens (
    id              BIGINT UNSIGNED    NOT NULL AUTO_INCREMENT,
    purpose         ENUM('guild_verify','dashboard_otp','cosplay_confirm','unsubscribe')
                                       NOT NULL,
    contact_id      INT UNSIGNED       DEFAULT NULL,
    email           VARCHAR(255)       NOT NULL,
    token_hash      CHAR(64)           NOT NULL,        -- SHA-256 hex
    expires_at      DATETIME           NOT NULL,
    consumed_at     DATETIME           DEFAULT NULL,
    redirect_to     VARCHAR(500)       DEFAULT NULL,
    ip_hash         CHAR(64)           DEFAULT NULL,
    created_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token_hash (token_hash),
    KEY idx_vt_email_purpose (email, purpose),
    KEY idx_vt_expires (expires_at),
    CONSTRAINT fk_vt_contact FOREIGN KEY (contact_id)
        REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- cosplay_signups
-- Costume contest entries. Tied to an event. Read by dashboard.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cosplay_signups (
    id                  INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    event_id            INT UNSIGNED   NOT NULL,
    contact_id          INT UNSIGNED   DEFAULT NULL,
    full_name           VARCHAR(255)   NOT NULL,
    alias               VARCHAR(255)   DEFAULT NULL,
    email               VARCHAR(255)   NOT NULL,
    phone               VARCHAR(32)    DEFAULT NULL,
    instagram           VARCHAR(64)    DEFAULT NULL,
    cosplay_character   VARCHAR(255)   DEFAULT NULL,
    character_series    VARCHAR(255)   DEFAULT NULL,
    walk_on_track       VARCHAR(500)   DEFAULT NULL,
    category_preference VARCHAR(64)    DEFAULT NULL,
    ticket_status       VARCHAR(64)    DEFAULT NULL,
    promo_code_info     VARCHAR(255)   DEFAULT NULL,
    notes               TEXT           DEFAULT NULL,
    contact_consent     TINYINT(1)     NOT NULL DEFAULT 0,
    status              ENUM('pending','confirmed','declined','noshow','winner')
                                       NOT NULL DEFAULT 'pending',
    ip_hash             CHAR(64)       DEFAULT NULL,
    created_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cosplay_event (event_id),
    KEY idx_cosplay_email (email),
    KEY idx_cosplay_status (status),
    CONSTRAINT fk_cosplay_event FOREIGN KEY (event_id)
        REFERENCES events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cosplay_contact FOREIGN KEY (contact_id)
        REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- email_log
-- Every outbound transactional + broadcast email send.
-- Driven by SES; ses_message_id lets us reconcile with bounces.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_log (
    id              BIGINT UNSIGNED    NOT NULL AUTO_INCREMENT,
    contact_id      INT UNSIGNED       DEFAULT NULL,
    broadcast_id    INT UNSIGNED       DEFAULT NULL,
    to_email        VARCHAR(255)       NOT NULL,
    from_email      VARCHAR(255)       NOT NULL,
    subject         VARCHAR(500)       NOT NULL,
    template        VARCHAR(64)        DEFAULT NULL,
    kind            ENUM('transactional','broadcast','test')
                                       NOT NULL DEFAULT 'transactional',
    ses_message_id  VARCHAR(255)       DEFAULT NULL,
    status          ENUM('queued','sent','delivered','bounced','complained','failed')
                                       NOT NULL DEFAULT 'queued',
    error_message   TEXT               DEFAULT NULL,
    opened_at       DATETIME           DEFAULT NULL,
    clicked_at      DATETIME           DEFAULT NULL,
    created_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_el_contact (contact_id),
    KEY idx_el_broadcast (broadcast_id),
    KEY idx_el_status (status),
    KEY idx_el_ses (ses_message_id),
    KEY idx_el_created (created_at),
    CONSTRAINT fk_el_contact FOREIGN KEY (contact_id)
        REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- broadcast_log
-- Each broadcast = one composed message blasted to a segment.
-- email_log rows reference this via broadcast_id.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS broadcast_log (
    id              INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    subject         VARCHAR(500)       NOT NULL,
    template        VARCHAR(64)        NOT NULL DEFAULT 'broadcast_base',
    body_html       MEDIUMTEXT         NOT NULL,
    body_text       MEDIUMTEXT         DEFAULT NULL,
    segment_filter  JSON               DEFAULT NULL,    -- e.g. {status:'verified', tags:['miku-vol-2']}
    recipient_count INT UNSIGNED       NOT NULL DEFAULT 0,
    sent_count      INT UNSIGNED       NOT NULL DEFAULT 0,
    delivered_count INT UNSIGNED       NOT NULL DEFAULT 0,
    bounced_count   INT UNSIGNED       NOT NULL DEFAULT 0,
    opened_count    INT UNSIGNED       NOT NULL DEFAULT 0,
    clicked_count   INT UNSIGNED       NOT NULL DEFAULT 0,
    status          ENUM('draft','scheduled','sending','sent','failed','cancelled')
                                       NOT NULL DEFAULT 'draft',
    scheduled_for   DATETIME           DEFAULT NULL,
    sent_at         DATETIME           DEFAULT NULL,
    created_by      VARCHAR(255)       DEFAULT NULL,
    created_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bl_status (status),
    KEY idx_bl_scheduled (scheduled_for)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- site_health_log
-- Periodic health checks: tracking pixels firing, key pages 200,
-- DNS records present, SES domain still verified, etc.
-- Dashboard reads the latest row per check_name for the green/red
-- status grid.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_health_log (
    id              BIGINT UNSIGNED    NOT NULL AUTO_INCREMENT,
    check_name      VARCHAR(64)        NOT NULL,
    -- e.g. 'homepage_200', 'meta_pixel_loaded', 'ga4_loaded',
    -- 'gtm_loaded', 'ses_domain_verified', 'mx_records', 'dkim'
    target          VARCHAR(255)       DEFAULT NULL,
    status          ENUM('ok','warn','fail','unknown') NOT NULL DEFAULT 'unknown',
    response_ms     INT UNSIGNED       DEFAULT NULL,
    detail          TEXT               DEFAULT NULL,
    checked_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_shl_check (check_name, checked_at),
    KEY idx_shl_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- dashboard_users
-- Operators of the dashboard. Google OAuth-first, OTP fallback.
-- The single-operator phase: just anikuranj@gmail.com — table is
-- here so we can add Atila and others later without migrations.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dashboard_users (
    id              INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    email           VARCHAR(255)       NOT NULL,
    name            VARCHAR(255)       DEFAULT NULL,
    role            ENUM('owner','admin','editor','viewer')
                                       NOT NULL DEFAULT 'admin',
    google_sub      VARCHAR(64)        DEFAULT NULL,    -- Google OAuth subject
    last_login_at   DATETIME           DEFAULT NULL,
    is_active       TINYINT(1)         NOT NULL DEFAULT 1,
    created_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_du_email (email),
    UNIQUE KEY uq_du_google_sub (google_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the owner account
INSERT INTO dashboard_users (email, name, role)
VALUES ('anikuranj@gmail.com', 'Moonlight Otaku Nights', 'owner')
ON DUPLICATE KEY UPDATE role = 'owner';


-- ------------------------------------------------------------
-- dashboard_sessions
-- Server-side sessions for the dashboard. Cookie holds session_id;
-- session_token_hash is the secret half checked on each request.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dashboard_sessions (
    id                  CHAR(36)       NOT NULL,        -- UUID
    user_id             INT UNSIGNED   NOT NULL,
    session_token_hash  CHAR(64)       NOT NULL,
    user_agent          VARCHAR(500)   DEFAULT NULL,
    ip_hash             CHAR(64)       DEFAULT NULL,
    created_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
    expires_at          DATETIME       NOT NULL,
    revoked_at          DATETIME       DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_ds_user (user_id),
    KEY idx_ds_expires (expires_at),
    CONSTRAINT fk_ds_user FOREIGN KEY (user_id)
        REFERENCES dashboard_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- gads_conversion_queue
-- Holds server-side Google Ads conversions until a worker drains
-- them via the Google Ads connector (uploadClickConversions /
-- uploadCallConversions / customer match).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gads_conversion_queue (
    id                BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    event_name        VARCHAR(64)       NOT NULL,
    event_id          VARCHAR(128)      NOT NULL,
    event_time        DATETIME          NOT NULL,
    email_hash        CHAR(64)          DEFAULT NULL,
    phone_hash        CHAR(64)          DEFAULT NULL,
    value             DECIMAL(10,2)     DEFAULT NULL,
    currency          CHAR(3)           DEFAULT 'USD',
    conversion_id     VARCHAR(64)       DEFAULT NULL,
    conversion_label  VARCHAR(64)       DEFAULT NULL,
    payload           JSON              DEFAULT NULL,
    status            ENUM('pending','sent','failed','skipped')
                                        NOT NULL DEFAULT 'pending',
    last_error        VARCHAR(500)      DEFAULT NULL,
    attempts          TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    sent_at           DATETIME          DEFAULT NULL,
    created_at        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_id (event_id),
    KEY idx_gads_status (status),
    KEY idx_gads_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- tracking_log
-- Audit trail of every server-side tracking call (so we can prove
-- Meta CAPI / GA4 MP fired and inspect failures in the dashboard).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tracking_log (
    id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    event_name      VARCHAR(64)       NOT NULL,
    event_id        VARCHAR(128)      NOT NULL,
    contact_id      INT UNSIGNED      DEFAULT NULL,
    meta_ok         TINYINT(1)        DEFAULT NULL,
    meta_http       SMALLINT          DEFAULT NULL,
    ga4_ok          TINYINT(1)        DEFAULT NULL,
    ga4_http        SMALLINT          DEFAULT NULL,
    gads_ok         TINYINT(1)        DEFAULT NULL,
    custom_data     JSON              DEFAULT NULL,
    error_summary   VARCHAR(500)      DEFAULT NULL,
    created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tl_event (event_name),
    KEY idx_tl_eventid (event_id),
    KEY idx_tl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
