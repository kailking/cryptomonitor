-- Destructive emergency rollback. Export the official ledger before use.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

DROP TABLE IF EXISTS `spot_listing_announcement_links`;
DROP TABLE IF EXISTS `spot_listing_announcement_events`;
DROP TABLE IF EXISTS `spot_listing_announcement_checkpoints`;
