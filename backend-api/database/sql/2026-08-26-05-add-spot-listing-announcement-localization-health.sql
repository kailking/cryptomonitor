-- Optional Chinese-localization source health.

CREATE TABLE `spot_listing_announcement_localization_checkpoints` (
  `platform_id` SMALLINT UNSIGNED NOT NULL,
  `feed_key` VARCHAR(64) NOT NULL,
  `last_attempt_at_ms` BIGINT UNSIGNED NOT NULL,
  `last_success_at_ms` BIGINT UNSIGNED NULL,
  `last_failure_at_ms` BIGINT UNSIGNED NULL,
  `consecutive_failures` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`platform_id`, `feed_key`),
  CONSTRAINT `spot_listing_announcement_localization_health_platform_check`
    CHECK (`platform_id` IN (2, 3, 4, 5, 8)),
  CONSTRAINT `spot_listing_announcement_localization_health_feed_check`
    CHECK (CHAR_LENGTH(TRIM(`feed_key`)) > 0),
  CONSTRAINT `spot_listing_announcement_localization_health_failure_check`
    CHECK (
      (`consecutive_failures` = 0 AND `last_error` IS NULL)
      OR (`consecutive_failures` > 0
        AND `last_failure_at_ms` IS NOT NULL
        AND `last_error` IS NOT NULL)
    ),
  CONSTRAINT `spot_listing_announcement_localization_health_time_check`
    CHECK (
      (`last_success_at_ms` IS NULL
        OR `last_success_at_ms` <= `last_attempt_at_ms`)
      AND (`last_failure_at_ms` IS NULL
        OR `last_failure_at_ms` <= `last_attempt_at_ms`)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
