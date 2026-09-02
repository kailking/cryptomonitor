-- Support bounded startup retention without full event-ledger scans.
-- Stop both spot-listing watcher processes while applying these indexes.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

ALTER TABLE `spot_listing_events`
  ADD INDEX `spot_listing_events_retention_time_index`
    (`event_at_ms`, `id`);

ALTER TABLE `spot_listing_channel_events`
  ADD INDEX `spot_listing_channel_events_retention_time_index`
    (`event_at_ms`, `id`);
