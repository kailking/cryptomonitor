-- Allow the isolated channel ledger to persist ordinary CEX spot candidates.
-- Apply this before starting a watcher that writes product_scope='cex_spot'.
--
-- Compatibility: this repository relies on enforced CHECK constraints and
-- therefore requires MySQL 8.0.16 or newer. DROP CHECK is supported throughout
-- that range. Keeping DROP and ADD in one ALTER avoids an unconstrained window;
-- rerunning the file safely replaces the same named constraint again.
SET SESSION `lock_wait_timeout` = 5;
SET SESSION `innodb_lock_wait_timeout` = 5;

ALTER TABLE `spot_listing_channel_items`
  DROP CHECK `spot_listing_channel_items_scope_check`,
  ADD CONSTRAINT `spot_listing_channel_items_scope_check`
    CHECK (`product_scope` IN (
      'cex_spot',
      'cex_special_orderbook',
      'managed_onchain',
      'pre_market_spot',
      'pre_market_otc',
      'pre_market_futures',
      'launchpad',
      'tokenized_security'
    ));
