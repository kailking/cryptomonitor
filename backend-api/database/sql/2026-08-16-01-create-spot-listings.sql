-- Durable discovery state for ordinary USDT spot pairs on the five supported
-- exchanges. This schema is read-only from the Laravel application.

CREATE TABLE `spot_listing_market_states` (
  `platform_id` SMALLINT UNSIGNED NOT NULL,
  `symbol` VARCHAR(64) NOT NULL,
  `exchange_symbol` VARCHAR(64) NOT NULL,
  `base_currency` VARCHAR(32) NOT NULL,
  `quote_currency` VARCHAR(16) NOT NULL,
  `exchange_status` VARCHAR(32) NOT NULL,
  `trading_start_at_ms` BIGINT UNSIGNED NULL,
  `observed_at_ms` BIGINT UNSIGNED NOT NULL,
  `source_hash` CHAR(64) NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `is_present` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`platform_id`, `symbol`),
  KEY `spot_listing_market_states_current_index` (`platform_id`, `is_present`),
  CONSTRAINT `spot_listing_market_states_platform_check`
    CHECK (`platform_id` IN (2, 3, 4, 5, 8)),
  CONSTRAINT `spot_listing_market_states_quote_check`
    CHECK (`quote_currency` = 'USDT'),
  CONSTRAINT `spot_listing_market_states_status_check`
    CHECK (`exchange_status` IN ('unknown', 'pre_open', 'trading', 'disabled')),
  CONSTRAINT `spot_listing_market_states_present_check`
    CHECK (`is_present` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `spot_listing_instruments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` SMALLINT UNSIGNED NOT NULL,
  `symbol` VARCHAR(64) NOT NULL,
  `exchange_symbol` VARCHAR(64) NOT NULL,
  `base_currency` VARCHAR(32) NOT NULL,
  `quote_currency` VARCHAR(16) NOT NULL,
  `exchange_status` VARCHAR(32) NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL,
  `first_seen_at_ms` BIGINT UNSIGNED NOT NULL,
  `trading_start_at_ms` BIGINT UNSIGNED NULL,
  `last_seen_at_ms` BIGINT UNSIGNED NOT NULL,
  `source_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spot_listing_instruments_platform_symbol_unique`
    (`platform_id`, `symbol`),
  KEY `spot_listing_instruments_status_seen_index`
    (`exchange_status`, `last_seen_at_ms`),
  KEY `spot_listing_instruments_planned_start_index`
    (`trading_start_at_ms`, `platform_id`, `id`),
  KEY `spot_listing_instruments_first_seen_index`
    (`first_seen_at_ms`, `platform_id`, `id`),
  CONSTRAINT `spot_listing_instruments_platform_check`
    CHECK (`platform_id` IN (2, 3, 4, 5, 8)),
  CONSTRAINT `spot_listing_instruments_quote_check`
    CHECK (`quote_currency` = 'USDT'),
  CONSTRAINT `spot_listing_instruments_status_check`
    CHECK (`exchange_status` IN ('unknown', 'pre_open', 'trading', 'disabled')),
  CONSTRAINT `spot_listing_instruments_revision_check`
    CHECK (`revision` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `spot_listing_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instrument_id` BIGINT UNSIGNED NOT NULL,
  `platform_id` SMALLINT UNSIGNED NOT NULL,
  `symbol` VARCHAR(64) NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(32) NOT NULL,
  `severity` VARCHAR(16) NOT NULL,
  `is_alert` TINYINT(1) NOT NULL DEFAULT 0,
  `source` VARCHAR(32) NOT NULL,
  `event_at_ms` BIGINT UNSIGNED NOT NULL,
  `idempotency_key` CHAR(64) NOT NULL,
  `payload_json` JSON NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spot_listing_events_idempotency_unique` (`idempotency_key`),
  KEY `spot_listing_events_instrument_time_index`
    (`instrument_id`, `event_at_ms`, `id`),
  KEY `spot_listing_events_platform_time_index` (`platform_id`, `event_at_ms`),
  KEY `spot_listing_events_retention_time_index` (`event_at_ms`, `id`),
  CONSTRAINT `spot_listing_events_instrument_fk`
    FOREIGN KEY (`instrument_id`) REFERENCES `spot_listing_instruments` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `spot_listing_events_platform_check`
    CHECK (`platform_id` IN (2, 3, 4, 5, 8)),
  CONSTRAINT `spot_listing_events_alert_check`
    CHECK (`is_alert` IN (0, 1)),
  CONSTRAINT `spot_listing_events_severity_check`
    CHECK (`severity` IN ('info', 'warning', 'critical')),
  CONSTRAINT `spot_listing_events_type_check`
    CHECK (`event_type` IN (
      'discovered',
      'trading_enabled',
      'trading_disabled',
      'metadata_changed'
    ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
