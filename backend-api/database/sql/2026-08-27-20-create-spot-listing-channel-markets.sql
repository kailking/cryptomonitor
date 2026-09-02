-- Isolated early-market ledger. These rows are deliberately separate from the
-- ordinary CEX spot snapshot so an Alpha/Pilot feed can never mark normal spot
-- pairs missing (or vice versa).
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

CREATE TABLE `spot_listing_channel_checkpoints` (
  `platform_id` SMALLINT UNSIGNED NOT NULL,
  `listing_channel` VARCHAR(64) NOT NULL,
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
  PRIMARY KEY (`platform_id`, `listing_channel`),
  CONSTRAINT `spot_listing_channel_checkpoints_platform_check`
    CHECK (`platform_id` IN (2, 3, 4, 5, 8)),
  CONSTRAINT `spot_listing_channel_checkpoints_interval_check`
    CHECK (`poll_interval_ms` BETWEEN 10000 AND 3600000),
  CONSTRAINT `spot_listing_channel_checkpoints_baseline_check`
    CHECK (`baseline_pending` IN (0, 1)),
  CONSTRAINT `spot_listing_channel_checkpoints_time_check`
    CHECK (
      (`last_success_at_ms` IS NULL
        OR `last_success_at_ms` <= `last_attempt_at_ms`)
      AND (`last_failure_at_ms` IS NULL
        OR `last_failure_at_ms` <= `last_attempt_at_ms`)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `spot_listing_channel_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` SMALLINT UNSIGNED NOT NULL,
  `product_scope` VARCHAR(32) NOT NULL,
  `listing_channel` VARCHAR(64) NOT NULL,
  -- Official identities are byte-sensitive; Gate currently contains IDs
  -- differing only by letter case. A case-insensitive unique key loses rows.
  `provider_item_id` VARCHAR(191) CHARACTER SET utf8mb4
    COLLATE utf8mb4_bin NOT NULL,
  `display_base` VARCHAR(64) NOT NULL,
  `display_name` VARCHAR(191) NOT NULL,
  `quote_currency` VARCHAR(16) NULL,
  `exchange_symbol` VARCHAR(96) NULL,
  `chain_id` VARCHAR(64) NULL,
  `contract_address` VARCHAR(191) NULL,
  `exchange_status` VARCHAR(32) NOT NULL,
  `listing_start_at_ms` BIGINT UNSIGNED NULL,
  `first_seen_at_ms` BIGINT UNSIGNED NOT NULL,
  `last_seen_at_ms` BIGINT UNSIGNED NOT NULL,
  `source_url` VARCHAR(2048) NOT NULL,
  `source_hash` CHAR(64) NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL,
  `is_present` TINYINT(1) NOT NULL DEFAULT 1,
  `is_baseline` TINYINT(1) NOT NULL DEFAULT 1,
  `metadata_json` JSON NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spot_listing_channel_items_identity_unique`
    (`platform_id`, `listing_channel`, `provider_item_id`),
  KEY `spot_listing_channel_items_start_index`
    (`listing_start_at_ms`, `platform_id`, `listing_channel`, `id`),
  KEY `spot_listing_channel_items_seen_index`
    (`first_seen_at_ms`, `is_baseline`, `platform_id`, `id`),
  KEY `spot_listing_channel_items_current_index`
    (`platform_id`, `listing_channel`, `is_present`),
  CONSTRAINT `spot_listing_channel_items_platform_check`
    CHECK (`platform_id` IN (2, 3, 4, 5, 8)),
  CONSTRAINT `spot_listing_channel_items_scope_check`
    CHECK (`product_scope` IN (
      'cex_special_orderbook',
      'managed_onchain',
      'pre_market_spot',
      'pre_market_otc',
      'pre_market_futures',
      'launchpad',
      'tokenized_security'
    )),
  CONSTRAINT `spot_listing_channel_items_status_check`
    CHECK (`exchange_status` IN ('unknown', 'pre_open', 'trading', 'disabled')),
  CONSTRAINT `spot_listing_channel_items_flags_check`
    CHECK (`is_present` IN (0, 1) AND `is_baseline` IN (0, 1)),
  CONSTRAINT `spot_listing_channel_items_revision_check`
    CHECK (`revision` > 0),
  CONSTRAINT `spot_listing_channel_items_identity_check`
    CHECK (
      CHAR_LENGTH(TRIM(`listing_channel`)) > 0
      AND CHAR_LENGTH(TRIM(`provider_item_id`)) > 0
      AND CHAR_LENGTH(TRIM(`display_base`)) > 0
      AND CHAR_LENGTH(TRIM(`source_url`)) > 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `spot_listing_channel_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel_item_id` BIGINT UNSIGNED NOT NULL,
  `platform_id` SMALLINT UNSIGNED NOT NULL,
  `listing_channel` VARCHAR(64) NOT NULL,
  `provider_item_id` VARCHAR(191) CHARACTER SET utf8mb4
    COLLATE utf8mb4_bin NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(32) NOT NULL,
  `severity` VARCHAR(16) NOT NULL,
  `is_alert` TINYINT(1) NOT NULL DEFAULT 0,
  `event_at_ms` BIGINT UNSIGNED NOT NULL,
  `idempotency_key` CHAR(64) NOT NULL,
  `payload_json` JSON NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spot_listing_channel_events_idempotency_unique`
    (`idempotency_key`),
  KEY `spot_listing_channel_events_item_time_index`
    (`channel_item_id`, `event_at_ms`, `id`),
  KEY `spot_listing_channel_events_channel_time_index`
    (`platform_id`, `listing_channel`, `event_at_ms`, `id`),
  KEY `spot_listing_channel_events_retention_time_index`
    (`event_at_ms`, `id`),
  CONSTRAINT `spot_listing_channel_events_item_fk`
    FOREIGN KEY (`channel_item_id`) REFERENCES `spot_listing_channel_items` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `spot_listing_channel_events_platform_check`
    CHECK (`platform_id` IN (2, 3, 4, 5, 8)),
  CONSTRAINT `spot_listing_channel_events_type_check`
    CHECK (`event_type` IN (
      'discovered',
      'trading_enabled',
      'trading_disabled',
      'metadata_changed'
    )),
  CONSTRAINT `spot_listing_channel_events_severity_check`
    CHECK (`severity` IN ('info', 'warning', 'critical')),
  CONSTRAINT `spot_listing_channel_events_alert_check`
    CHECK (`is_alert` IN (0, 1)),
  CONSTRAINT `spot_listing_channel_events_revision_check`
    CHECK (`revision` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
