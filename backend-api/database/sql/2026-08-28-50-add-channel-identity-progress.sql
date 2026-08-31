-- Persist only the identity confirmation progress for first baselines and
-- reduced runtime channel snapshots. Unconfirmed item rows stay outside the
-- durable item projection.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

ALTER TABLE `spot_listing_channel_checkpoints`
  ADD COLUMN `identity_candidate_fingerprint`
    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER `baseline_pending`,
  ADD COLUMN `identity_candidate_count` TINYINT UNSIGNED NOT NULL DEFAULT 0
    AFTER `identity_candidate_fingerprint`,
  ADD CONSTRAINT `spot_listing_channel_checkpoints_identity_check`
    CHECK (
      (`identity_candidate_count` = 0
        AND `identity_candidate_fingerprint` IS NULL)
      OR (`identity_candidate_count` BETWEEN 1 AND 2
        AND `identity_candidate_fingerprint` IS NOT NULL)
    );
