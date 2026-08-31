-- Isolated rollback; ordinary spot discovery tables are preserved.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

DROP TABLE IF EXISTS `spot_listing_channel_events`;
DROP TABLE IF EXISTS `spot_listing_channel_items`;
DROP TABLE IF EXISTS `spot_listing_channel_checkpoints`;
