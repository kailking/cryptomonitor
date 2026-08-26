-- Destructive emergency rollback. Export discovery history before use.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

DROP TABLE IF EXISTS `spot_listing_events`;
DROP TABLE IF EXISTS `spot_listing_instruments`;
DROP TABLE IF EXISTS `spot_listing_market_states`;
