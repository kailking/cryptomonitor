-- Roll back only channel identity confirmation metadata. Stop the new channel
-- watcher before running this file and before starting an older binary.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

ALTER TABLE `spot_listing_channel_checkpoints`
  DROP CHECK `spot_listing_channel_checkpoints_identity_check`,
  DROP COLUMN `identity_candidate_count`,
  DROP COLUMN `identity_candidate_fingerprint`;
