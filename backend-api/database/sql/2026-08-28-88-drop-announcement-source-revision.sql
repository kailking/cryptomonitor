-- Roll back only the announcement revision-ordering extension. Stop the
-- discovery-only spot_listing_watcher first; ordinary market and announcement
-- polling share that process. Historical candidates remain intact.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

ALTER TABLE `spot_listing_announcement_candidate_sets`
  DROP CHECK `spot_listing_announcement_candidate_sets_projection_check`,
  DROP CHECK `spot_listing_announcement_candidate_sets_revision_check`,
  DROP COLUMN `projection_invalidated`,
  DROP COLUMN `source_revision_token`;
