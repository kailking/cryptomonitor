-- Classify ordinary CEX spot pairs by the exchange trading zone without
-- changing their durable platform + symbol identity. Apply before deploying
-- the watcher that writes listing-channel metadata.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

ALTER TABLE `spot_listing_market_states`
  -- Legacy writers cannot prove that an unlabelled row is ordinary spot.
  -- The new watcher always writes either `standard` or an exact zone.
  ADD COLUMN `listing_channel` VARCHAR(64) NOT NULL DEFAULT 'special_unclassified'
    AFTER `quote_currency`,
  ADD COLUMN `listing_tags_json` JSON NULL
    AFTER `listing_channel`;

ALTER TABLE `spot_listing_instruments`
  ADD COLUMN `listing_channel` VARCHAR(64) NOT NULL DEFAULT 'special_unclassified'
    AFTER `quote_currency`,
  ADD COLUMN `listing_tags_json` JSON NULL
    AFTER `listing_channel`;
