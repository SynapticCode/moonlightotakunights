-- Session 1.5: UGC submissions
-- Public photo submissions from event attendees, moderated in dashboard,
-- rendered on the event recap "Your Night, Your Frame" wall after approval.

CREATE TABLE IF NOT EXISTS ugc_submissions (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_slug        VARCHAR(80)  NOT NULL,
    display_name      VARCHAR(120) NULL,
    instagram_handle  VARCHAR(60)  NULL,
    email             VARCHAR(190) NULL,
    caption           VARCHAR(500) NULL,
    s3_key            VARCHAR(255) NOT NULL,
    mime              VARCHAR(50)  NOT NULL,
    width             INT UNSIGNED NULL,
    height            INT UNSIGNED NULL,
    bytes             INT UNSIGNED NOT NULL,
    status            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    consent_repost    TINYINT(1)   NOT NULL DEFAULT 0,
    consent_age       TINYINT(1)   NOT NULL DEFAULT 0,
    ip_hash           CHAR(64)     NULL,
    user_agent        VARCHAR(255) NULL,
    submitted_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    moderated_at      DATETIME     NULL,
    moderated_by      VARCHAR(190) NULL,
    reject_reason     VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_status_submitted (status, submitted_at DESC),
    KEY idx_event_status     (event_slug, status),
    KEY idx_ig_handle        (instagram_handle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
