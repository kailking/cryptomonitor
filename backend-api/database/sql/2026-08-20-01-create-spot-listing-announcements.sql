-- Official announcement ledger and exact pair-to-market correlation.
-- Apply after 2026-08-16-01-create-spot-listings.sql.

CREATE TABLE `spot_listing_announcement_checkpoints` (
  `platform_id` SMALLINT UNSIGNED NOT NULL,
  `feed_key` VARCHAR(64) NOT NULL,
  `baseline_started_at_ms` BIGINT UNSIGNED NOT NULL,
  `baseline_completed_at_ms` BIGINT UNSIGNED NOT NULL,
  `high_watermark_published_at_ms` BIGINT UNSIGNED NULL,
  `high_watermark_external_id` VARCHAR(191) NULL,
  `last_success_at_ms` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`platform_id`, `feed_key`),
  CONSTRAINT `spot_listing_announcement_checkpoints_platform_check`
    CHECK (`platform_id` IN (2, 3, 4, 5, 8)),
  CONSTRAINT `spot_listing_announcement_checkpoints_revision_check`
    CHECK (`revision` > 0),
  CONSTRAINT `spot_listing_announcement_checkpoints_baseline_check`
    CHECK (
      `baseline_completed_at_ms` >= `baseline_started_at_ms`
      AND `last_success_at_ms` >= `baseline_completed_at_ms`
    ),
  CONSTRAINT `spot_listing_announcement_checkpoints_watermark_check`
    CHECK (
      (`high_watermark_published_at_ms` IS NULL
        AND `high_watermark_external_id` IS NULL)
      OR (`high_watermark_published_at_ms` IS NOT NULL
        AND `high_watermark_external_id` IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `spot_listing_announcement_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` SMALLINT UNSIGNED NOT NULL,
  `feed_key` VARCHAR(64) NOT NULL,
  `external_id` VARCHAR(191) NOT NULL,
  `event_type` VARCHAR(32) NOT NULL DEFAULT 'announcement_detected',
  `title` VARCHAR(500) NOT NULL,
  `description` TEXT NULL,
  `source_url` VARCHAR(2048) NOT NULL,
  `announcement_kind` VARCHAR(64) NOT NULL,
  `published_at_ms` BIGINT UNSIGNED NOT NULL,
  `detected_at_ms` BIGINT UNSIGNED NOT NULL,
  `candidate_base` VARCHAR(32) NULL,
  `candidate_quote` VARCHAR(16) NULL,
  `candidate_symbol` VARCHAR(64) NULL,
  `announced_trading_start_at_ms` BIGINT UNSIGNED NULL,
  `parse_confidence` TINYINT UNSIGNED NOT NULL,
  `severity` VARCHAR(16) NOT NULL DEFAULT 'warning',
  `is_alert` TINYINT(1) NOT NULL DEFAULT 0,
  `content_hash` CHAR(64) NOT NULL,
  `idempotency_key` CHAR(64) NOT NULL,
  `payload_json` JSON NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spot_listing_announcement_events_idempotency_unique`
    (`idempotency_key`),
  UNIQUE KEY `spot_listing_announcement_events_external_unique`
    (`platform_id`, `feed_key`, `external_id`),
  KEY `spot_listing_announcement_events_feed_time_index`
    (`platform_id`, `feed_key`, `published_at_ms`, `id`),
  KEY `spot_listing_announcement_events_time_index`
    (`published_at_ms`, `id`),
  KEY `spot_listing_announcement_events_detected_index`
    (`detected_at_ms`, `id`),
  KEY `spot_listing_announcement_events_planned_start_index`
    (`announced_trading_start_at_ms`, `id`),
  KEY `spot_listing_announcement_events_candidate_index`
    (`platform_id`, `candidate_symbol`, `id`),
  CONSTRAINT `spot_listing_announcement_events_platform_check`
    CHECK (`platform_id` IN (2, 3, 4, 5, 8)),
  CONSTRAINT `spot_listing_announcement_events_identity_check`
    CHECK (
      CHAR_LENGTH(TRIM(`external_id`)) > 0
      AND CHAR_LENGTH(TRIM(`title`)) > 0
      AND CHAR_LENGTH(TRIM(`source_url`)) > 0
    ),
  CONSTRAINT `spot_listing_announcement_events_type_check`
    CHECK (`event_type` = 'announcement_detected'),
  CONSTRAINT `spot_listing_announcement_events_kind_check`
    CHECK (`announcement_kind` IN (
      'spot_usdt_explicit',
      'listing_candidate',
      'ambiguous'
    )),
  CONSTRAINT `spot_listing_announcement_events_candidate_shape_check`
    CHECK (
      (`candidate_base` IS NULL
        AND `candidate_quote` IS NULL
        AND `candidate_symbol` IS NULL)
      OR (`candidate_base` IS NOT NULL
        AND `candidate_quote` = 'USDT'
        AND `candidate_symbol` = CONCAT(`candidate_base`, `candidate_quote`))
    ),
  CONSTRAINT `spot_listing_announcement_events_confidence_check`
    CHECK (`parse_confidence` <= 100),
  CONSTRAINT `spot_listing_announcement_events_severity_check`
    CHECK (`severity` IN ('info', 'warning', 'critical')),
  CONSTRAINT `spot_listing_announcement_events_alert_check`
    CHECK (`is_alert` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `spot_listing_announcement_links` (
  `announcement_event_id` BIGINT UNSIGNED NOT NULL,
  `platform_id` SMALLINT UNSIGNED NOT NULL,
  `symbol` VARCHAR(64) NOT NULL,
  `exchange_symbol` VARCHAR(64) NOT NULL,
  `instrument_id` BIGINT UNSIGNED NULL,
  `match_method` VARCHAR(32) NOT NULL,
  `confidence` TINYINT UNSIGNED NOT NULL,
  `symbols_confirmed_at_ms` BIGINT UNSIGNED NOT NULL,
  `linked_at_ms` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`announcement_event_id`, `platform_id`, `symbol`),
  KEY `spot_listing_announcement_links_instrument_index` (`instrument_id`),
  KEY `spot_listing_announcement_links_symbol_index`
    (`platform_id`, `symbol`, `announcement_event_id`),
  CONSTRAINT `spot_listing_announcement_links_event_fk`
    FOREIGN KEY (`announcement_event_id`)
    REFERENCES `spot_listing_announcement_events` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `spot_listing_announcement_links_instrument_fk`
    FOREIGN KEY (`instrument_id`)
    REFERENCES `spot_listing_instruments` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `spot_listing_announcement_links_platform_check`
    CHECK (`platform_id` IN (2, 3, 4, 5, 8)),
  CONSTRAINT `spot_listing_announcement_links_match_method_check`
    CHECK (`match_method` = 'exact_symbol'),
  CONSTRAINT `spot_listing_announcement_links_confidence_check`
    CHECK (`confidence` <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
