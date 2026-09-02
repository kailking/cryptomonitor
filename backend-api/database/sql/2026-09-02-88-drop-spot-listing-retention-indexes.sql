-- Isolated rollback for startup-retention indexes. No radar data is removed.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

ALTER TABLE `spot_listing_channel_events`
  DROP INDEX `spot_listing_channel_events_retention_time_index`;

ALTER TABLE `spot_listing_events`
  DROP INDEX `spot_listing_events_retention_time_index`;
