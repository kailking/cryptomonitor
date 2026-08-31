-- Read-only verification after applying durable channel identity progress.
SELECT COUNT(*) AS `invalid_channel_identity_checkpoints`
FROM `spot_listing_channel_checkpoints`
WHERE NOT (
  (`identity_candidate_count` = 0
    AND `identity_candidate_fingerprint` IS NULL)
  OR (`identity_candidate_count` BETWEEN 1 AND 2
    AND `identity_candidate_fingerprint` IS NOT NULL
    AND `identity_candidate_fingerprint` = LOWER(`identity_candidate_fingerprint`)
    AND `identity_candidate_fingerprint` NOT REGEXP '[^0-9a-f]')
);

SHOW CREATE TABLE `spot_listing_channel_checkpoints`;
