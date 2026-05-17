-- Session 4: Analytics dashboard
-- Add indices that accelerate funnel analytics queries.
-- Uses INFORMATION_SCHEMA guards to stay idempotent across MySQL 5.7 / 8.x.
-- NOTE: each statement on its own line so the migrate.php splitter
-- (`preg_split('/;\\s*\\n/', $sql)`) treats them as separate executions.

-- submissions: (created_at, kind) composite for time-series
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'submissions' AND index_name = 'idx_sub_created_day');
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE submissions ADD INDEX idx_sub_created_day (created_at, kind)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- contacts: (first_source, first_seen_at) for funnel attribution
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'contacts' AND index_name = 'idx_contacts_source_seen');
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE contacts ADD INDEX idx_contacts_source_seen (first_source, first_seen_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
