-- Stage 10: per-announcement candidate-set metadata. Stop only the dedicated
-- announcement writer while applying this stage.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

CREATE TABLE `spot_listing_announcement_candidate_sets` (
  `announcement_event_id` BIGINT UNSIGNED NOT NULL,
  `source_content_hash` CHAR(64) NOT NULL,
  `candidate_set_hash` CHAR(64) NOT NULL,
  `candidates_authoritative` TINYINT(1) NOT NULL DEFAULT 0,
  `candidates_complete` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`announcement_event_id`),
  CONSTRAINT `spot_listing_announcement_candidate_sets_event_fk`
    FOREIGN KEY (`announcement_event_id`)
    REFERENCES `spot_listing_announcement_events` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `spot_listing_announcement_candidate_sets_authority_check`
    CHECK (
      `candidates_authoritative` IN (0, 1)
      AND `candidates_complete` IN (0, 1)
      AND (`candidates_complete` = 0 OR `candidates_authoritative` = 1)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
