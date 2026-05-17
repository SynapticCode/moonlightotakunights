-- Session 2: Submissions + Donations
-- Native CRM intake for sponsors, investors, DJs, idols, vendors
-- and donation tracking from Stripe Payment Links.

-- ============================================================
-- submissions
-- One polymorphic table for ALL application/inquiry forms.
-- kind = sponsor | investor | dj | idol | vendor
-- Kind-specific fields live in `details` JSON.
-- Each submission also upserts the email into `contacts`
-- (with first_source = "<kind>_apply") via the API endpoint.
-- ============================================================
CREATE TABLE IF NOT EXISTS submissions (
    id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    kind            ENUM('sponsor','investor','dj','idol','vendor')
                                      NOT NULL,
    contact_id      INT UNSIGNED      DEFAULT NULL,
    full_name       VARCHAR(255)      NOT NULL,
    email           VARCHAR(255)      NOT NULL,
    phone           VARCHAR(32)       DEFAULT NULL,
    instagram       VARCHAR(64)       DEFAULT NULL,
    org_name        VARCHAR(255)      DEFAULT NULL,    -- brand / stage name / company
    website         VARCHAR(500)      DEFAULT NULL,
    pitch           TEXT              DEFAULT NULL,    -- free-form main field
    details         JSON              DEFAULT NULL,    -- kind-specific fields
    -- Lifecycle
    status          ENUM('new','reviewing','contacted','accepted','declined','spam')
                                      NOT NULL DEFAULT 'new',
    owner_notes     TEXT              DEFAULT NULL,
    contacted_at    DATETIME          DEFAULT NULL,
    decided_at      DATETIME          DEFAULT NULL,
    -- Origin
    source_page     VARCHAR(120)      DEFAULT NULL,    -- e.g. /djs, /sponsors/apply
    referrer        VARCHAR(500)      DEFAULT NULL,
    ip_hash         CHAR(64)          DEFAULT NULL,
    user_agent      VARCHAR(500)      DEFAULT NULL,
    created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sub_kind_status (kind, status),
    KEY idx_sub_created (created_at),
    KEY idx_sub_email (email),
    KEY idx_sub_contact (contact_id),
    CONSTRAINT fk_sub_contact FOREIGN KEY (contact_id)
        REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- donations
-- One row per Stripe Payment Link checkout. Webhook writes
-- `pending` on checkout.session.completed, then flips to
-- `succeeded` on payment_intent.succeeded.
-- Donations are anonymous-eligible (donor_email is the
-- Stripe-collected receipt email; donor_name is optional).
-- ============================================================
CREATE TABLE IF NOT EXISTS donations (
    id                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    contact_id            INT UNSIGNED    DEFAULT NULL,
    stripe_session_id     VARCHAR(120)    DEFAULT NULL,
    stripe_payment_intent VARCHAR(120)    DEFAULT NULL,
    amount_cents          INT UNSIGNED    NOT NULL,
    currency              CHAR(3)         NOT NULL DEFAULT 'USD',
    donor_name            VARCHAR(255)    DEFAULT NULL,
    donor_email           VARCHAR(255)    DEFAULT NULL,
    donor_message         TEXT            DEFAULT NULL,
    is_anonymous          TINYINT(1)      NOT NULL DEFAULT 0,
    status                ENUM('pending','succeeded','refunded','failed')
                                          NOT NULL DEFAULT 'pending',
    refunded_at           DATETIME        DEFAULT NULL,
    metadata              JSON            DEFAULT NULL,
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_don_intent (stripe_payment_intent),
    UNIQUE KEY uq_don_session (stripe_session_id),
    KEY idx_don_status (status),
    KEY idx_don_email (donor_email),
    KEY idx_don_created (created_at),
    CONSTRAINT fk_don_contact FOREIGN KEY (contact_id)
        REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
