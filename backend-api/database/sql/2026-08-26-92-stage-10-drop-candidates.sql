-- Destructive rollback stage 10. Export derived candidate rows before use.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

DROP TABLE IF EXISTS `spot_listing_announcement_candidates`;
