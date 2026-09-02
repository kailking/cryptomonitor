-- Roll back only the cex_spot scope extension. Stop every writer that can emit
-- product_scope='cex_spot' before running this file.
--
-- The first query must return zero. This rollback never deletes or rewrites
-- channel history. If cex_spot rows remain, the single atomic ALTER fails while
-- validating the narrower CHECK and preserves the widened constraint.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

SELECT COUNT(*) AS `rollback_blocking_cex_spot_rows`
FROM `spot_listing_channel_items`
WHERE `product_scope` = 'cex_spot';

ALTER TABLE `spot_listing_channel_items`
  DROP CHECK `spot_listing_channel_items_scope_check`,
  ADD CONSTRAINT `spot_listing_channel_items_scope_check`
    CHECK (`product_scope` IN (
      'cex_special_orderbook',
      'managed_onchain',
      'pre_market_spot',
      'pre_market_otc',
      'pre_market_futures',
      'launchpad',
      'tokenized_security'
    ));
