-- Canonical announcement-poll health is independent from the cursor so the
-- first fetch/parse/save failure is observable before a cursor row exists.

SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

CREATE TABLE `spot_listing_announcement_poll_checkpoints` (
  `platform_id` SMALLINT UNSIGNED NOT NULL,
  `feed_key` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `last_attempt_at_ms` BIGINT UNSIGNED NOT NULL,
  `last_success_at_ms` BIGINT UNSIGNED NULL,
  `last_failure_at_ms` BIGINT UNSIGNED NULL,
  `consecutive_failures` INT UNSIGNED NOT NULL DEFAULT 0,
  `poll_interval_ms` INT UNSIGNED NOT NULL,
  `last_error` VARCHAR(500) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
    ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`platform_id`, `feed_key`),
  CONSTRAINT `spot_listing_announcement_poll_health_platform_check`
    CHECK (`platform_id` IN (2, 3, 4, 5, 8)),
  CONSTRAINT `spot_listing_announcement_poll_health_feed_check`
    CHECK (CHAR_LENGTH(TRIM(`feed_key`)) > 0),
  CONSTRAINT `spot_listing_announcement_poll_health_failure_check`
    CHECK (
      (`consecutive_failures` = 0 AND `last_error` IS NULL)
      OR (`consecutive_failures` > 0
        AND `last_failure_at_ms` = `last_attempt_at_ms`
        AND CHAR_LENGTH(TRIM(`last_error`)) > 0)
    ),
  CONSTRAINT `spot_listing_announcement_poll_health_time_check`
    CHECK (
      `last_attempt_at_ms` > 0
      AND (`last_success_at_ms` IS NULL
        OR `last_success_at_ms` <= `last_attempt_at_ms`)
      AND (`last_failure_at_ms` IS NULL
        OR `last_failure_at_ms` <= `last_attempt_at_ms`)
    ),
  CONSTRAINT `spot_listing_announcement_poll_health_interval_check`
    CHECK (`poll_interval_ms` BETWEEN 5000 AND 900000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
