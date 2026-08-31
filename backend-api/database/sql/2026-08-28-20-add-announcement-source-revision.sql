-- Add fail-closed revision ordering to the official announcement projection.
-- Stop the discovery-only spot_listing_watcher while applying; ordinary market
-- and announcement polling share that process (there is no separate writer).
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

ALTER TABLE `spot_listing_announcement_candidate_sets`
  ADD COLUMN `source_revision_token` BIGINT UNSIGNED NULL
    AFTER `source_content_hash`,
  ADD COLUMN `projection_invalidated` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `candidates_complete`,
  ADD CONSTRAINT `spot_listing_announcement_candidate_sets_revision_check`
    CHECK (
      `source_revision_token` IS NULL
      OR `source_revision_token` > 0
    ),
  ADD CONSTRAINT `spot_listing_announcement_candidate_sets_projection_check`
    CHECK (`projection_invalidated` IN (0, 1));
