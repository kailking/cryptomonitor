-- Durable market-source health for the five discovery providers.

CREATE TABLE `spot_listing_market_checkpoints` (
  `platform_id` SMALLINT UNSIGNED NOT NULL,
  `last_attempt_at_ms` BIGINT UNSIGNED NOT NULL,
  `last_success_at_ms` BIGINT UNSIGNED NULL,
  `last_failure_at_ms` BIGINT UNSIGNED NULL,
  `consecutive_failures` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_item_count` INT UNSIGNED NULL,
  `poll_interval_ms` INT UNSIGNED NOT NULL,
  `baseline_pending` TINYINT(1) NOT NULL DEFAULT 1,
  `last_error` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`platform_id`),
  CONSTRAINT `spot_listing_market_checkpoints_platform_check`
    CHECK (`platform_id` IN (2, 3, 4, 5, 8)),
  CONSTRAINT `spot_listing_market_checkpoints_interval_check`
    CHECK (`poll_interval_ms` BETWEEN 2000 AND 300000),
  CONSTRAINT `spot_listing_market_checkpoints_baseline_check`
    CHECK (`baseline_pending` IN (0, 1)),
  CONSTRAINT `spot_listing_market_checkpoints_time_check`
    CHECK (
      (`last_success_at_ms` IS NULL
        OR `last_success_at_ms` <= `last_attempt_at_ms`)
      AND (`last_failure_at_ms` IS NULL
        OR `last_failure_at_ms` <= `last_attempt_at_ms`)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
