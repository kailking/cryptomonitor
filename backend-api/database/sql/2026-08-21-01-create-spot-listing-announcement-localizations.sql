-- Official Chinese presentation copies for the immutable announcement ledger.
-- Apply after 2026-08-20-01-create-spot-listing-announcements.sql.

CREATE TABLE `spot_listing_announcement_localizations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `announcement_event_id` BIGINT UNSIGNED NOT NULL,
  `platform_id` SMALLINT UNSIGNED NOT NULL,
  `language` VARCHAR(16) NOT NULL,
  `source_external_id` VARCHAR(191) NOT NULL,
  `title` VARCHAR(500) NOT NULL,
  `description` TEXT NULL,
  `source_url` VARCHAR(2048) NOT NULL,
  `published_at_ms` BIGINT UNSIGNED NOT NULL,
  `content_hash` CHAR(64) NOT NULL,
  `payload_json` JSON NOT NULL,
  `match_method` VARCHAR(32) NOT NULL,
  `match_confidence` TINYINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spot_listing_announcement_localizations_event_language_unique`
    (`announcement_event_id`, `language`),
  UNIQUE KEY `spot_listing_announcement_localizations_source_unique`
    (`platform_id`, `language`, `source_external_id`),
  KEY `spot_listing_announcement_localizations_event_priority_index`
    (`announcement_event_id`, `language`, `match_confidence`, `id`),
  CONSTRAINT `spot_listing_announcement_localizations_event_fk`
    FOREIGN KEY (`announcement_event_id`)
    REFERENCES `spot_listing_announcement_events` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `spot_listing_announcement_localizations_platform_check`
    CHECK (`platform_id` IN (2, 3, 4, 5, 8)),
  CONSTRAINT `spot_listing_announcement_localizations_language_check`
    CHECK (`language` IN ('zh-CN', 'zh-HK', 'zh-TW')),
  CONSTRAINT `spot_listing_announcement_localizations_identity_check`
    CHECK (
      CHAR_LENGTH(TRIM(`source_external_id`)) > 0
      AND CHAR_LENGTH(TRIM(`title`)) > 0
      AND CHAR_LENGTH(TRIM(`source_url`)) > 0
      AND CHAR_LENGTH(TRIM(`match_method`)) > 0
    ),
  CONSTRAINT `spot_listing_announcement_localizations_confidence_check`
    CHECK (`match_confidence` <= 100),
  CONSTRAINT `spot_listing_announcement_localizations_published_check`
    CHECK (`published_at_ms` > 0),
  CONSTRAINT `spot_listing_announcement_localizations_match_method_check`
    CHECK (`match_method` IN ('source_identity', 'published_time_symbol'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
