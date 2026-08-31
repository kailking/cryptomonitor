-- Read-only verification after applying the missing-identity checkpoint DDL.
SELECT COUNT(*) AS `invalid_missing_identity_checkpoints`
FROM `spot_listing_market_states`
WHERE NOT (
  (`missing_identity_count` = 0
    AND `missing_identity_fingerprint` IS NULL)
  OR (`missing_identity_count` BETWEEN 1 AND 12
    AND `missing_identity_fingerprint` IS NOT NULL
    AND `missing_identity_fingerprint` = LOWER(`missing_identity_fingerprint`)
    AND `missing_identity_fingerprint` NOT REGEXP '[^0-9a-f]')
);

SHOW CREATE TABLE `spot_listing_market_states`;
