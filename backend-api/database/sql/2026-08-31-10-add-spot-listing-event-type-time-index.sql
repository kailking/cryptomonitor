-- Bound the command-room lifecycle projection by instrument and event type.
-- Stop the discovery-only spot_listing_watcher while applying this index.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

ALTER TABLE `spot_listing_events`
  ADD INDEX `spot_listing_events_instrument_type_time_index`
    (`instrument_id`, `event_type`, `event_at_ms`, `id`);
