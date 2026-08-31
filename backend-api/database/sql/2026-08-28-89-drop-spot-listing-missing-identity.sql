-- Roll back only the restart-safe missing-identity confirmation metadata.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

ALTER TABLE `spot_listing_market_states`
  DROP CHECK `spot_listing_market_states_missing_identity_check`,
  DROP COLUMN `missing_identity_count`,
  DROP COLUMN `missing_identity_fingerprint`;
