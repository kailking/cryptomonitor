-- Persist the platform-scoped confirmation progress for a reduced main-market
-- identity set. Existing checkpoint rows remain a valid zero-value baseline.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

ALTER TABLE `spot_listing_market_states`
  ADD COLUMN `missing_identity_fingerprint`
    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER `is_present`,
  ADD COLUMN `missing_identity_count` TINYINT UNSIGNED NOT NULL DEFAULT 0
    AFTER `missing_identity_fingerprint`,
  ADD CONSTRAINT `spot_listing_market_states_missing_identity_check`
    CHECK (
      (`missing_identity_count` = 0
        AND `missing_identity_fingerprint` IS NULL)
      OR (`missing_identity_count` BETWEEN 1 AND 12
        AND `missing_identity_fingerprint` IS NOT NULL)
    );
