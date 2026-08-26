-- Stage 20: one row per ordinary USDT spot pair derived from an official
-- announcement. Historical parent rows remain readable through the legacy
-- singular projection, so no unbounded data copy is performed here.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

CREATE TABLE `spot_listing_announcement_candidates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `announcement_event_id` BIGINT UNSIGNED NOT NULL,
  `ordinal` SMALLINT UNSIGNED NOT NULL,
  `announcement_kind` VARCHAR(64) NOT NULL,
  `candidate_base` VARCHAR(32) NOT NULL,
  `candidate_quote` VARCHAR(16) NOT NULL,
  `candidate_symbol` VARCHAR(64) NOT NULL,
  `announced_trading_start_at_ms` BIGINT UNSIGNED NULL,
  `parse_confidence` TINYINT UNSIGNED NOT NULL,
  `severity` VARCHAR(16) NOT NULL DEFAULT 'warning',
  `is_alert` TINYINT(1) NOT NULL DEFAULT 0,
  `derivation_hash` CHAR(64) NOT NULL,
  `payload_json` JSON NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spot_listing_announcement_candidates_event_symbol_unique`
    (`announcement_event_id`, `candidate_symbol`),
  KEY `spot_listing_announcement_candidates_event_order_index`
    (`announcement_event_id`, `ordinal`, `id`),
  KEY `spot_listing_announcement_candidates_symbol_index`
    (`candidate_symbol`, `id`),
  KEY `spot_listing_announcement_candidates_planned_start_index`
    (`announced_trading_start_at_ms`, `announcement_event_id`, `id`),
  CONSTRAINT `spot_listing_announcement_candidates_event_fk`
    FOREIGN KEY (`announcement_event_id`)
    REFERENCES `spot_listing_announcement_events` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `spot_listing_announcement_candidates_ordinal_check`
    CHECK (`ordinal` > 0),
  CONSTRAINT `spot_listing_announcement_candidates_kind_check`
    CHECK (`announcement_kind` IN (
      'spot_usdt_explicit',
      'listing_candidate'
    )),
  CONSTRAINT `spot_listing_announcement_candidates_pair_check`
    CHECK (
      CHAR_LENGTH(TRIM(`candidate_base`)) > 0
      AND `candidate_quote` = 'USDT'
      AND `candidate_symbol` = CONCAT(`candidate_base`, `candidate_quote`)
    ),
  CONSTRAINT `spot_listing_announcement_candidates_start_check`
    CHECK (
      `announced_trading_start_at_ms` IS NULL
      OR `announced_trading_start_at_ms` > 0
    ),
  CONSTRAINT `spot_listing_announcement_candidates_confidence_check`
    CHECK (`parse_confidence` <= 100),
  CONSTRAINT `spot_listing_announcement_candidates_severity_check`
    CHECK (`severity` IN ('info', 'warning', 'critical')),
  CONSTRAINT `spot_listing_announcement_candidates_alert_check`
    CHECK (`is_alert` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
