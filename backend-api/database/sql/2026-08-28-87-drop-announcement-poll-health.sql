-- Roll back only the independent canonical announcement-poll health table.
-- Stop the new spot_listing_watcher before executing this file.

SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

DROP TABLE IF EXISTS `spot_listing_announcement_poll_checkpoints`;
