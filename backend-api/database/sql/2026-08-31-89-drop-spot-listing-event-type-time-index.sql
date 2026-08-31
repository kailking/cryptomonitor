-- Isolated rollback for the lifecycle query index. Discovery history and
-- announcement projections remain intact.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

ALTER TABLE `spot_listing_events`
  DROP INDEX `spot_listing_events_instrument_type_time_index`;
