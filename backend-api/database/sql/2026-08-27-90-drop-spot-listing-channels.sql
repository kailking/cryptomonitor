-- Roll back only the optional channel metadata. The discovery ledger and all
-- existing spot-listing rows remain intact.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

ALTER TABLE `spot_listing_instruments`
  DROP COLUMN `listing_tags_json`,
  DROP COLUMN `listing_channel`;

ALTER TABLE `spot_listing_market_states`
  DROP COLUMN `listing_tags_json`,
  DROP COLUMN `listing_channel`;
