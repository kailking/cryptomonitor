-- Read-only verification after widening the channel product scope.
-- Required result: allows_cex_spot=1, enforced=YES, invalid_scope_rows=0.
SELECT VERSION() AS `mysql_version`;

SELECT `tc`.`constraint_name` AS `constraint_name`,
       `tc`.`enforced` AS `enforced`,
       (LOCATE('cex_spot', `cc`.`check_clause`) > 0) AS `allows_cex_spot`,
       `cc`.`check_clause` AS `check_clause`
FROM `information_schema`.`table_constraints` AS `tc`
JOIN `information_schema`.`check_constraints` AS `cc`
  ON `cc`.`constraint_schema` = `tc`.`constraint_schema`
 AND `cc`.`constraint_name` = `tc`.`constraint_name`
WHERE `tc`.`table_schema` = DATABASE()
  AND `tc`.`table_name` = 'spot_listing_channel_items'
  AND `tc`.`constraint_type` = 'CHECK'
  AND `tc`.`constraint_name` = 'spot_listing_channel_items_scope_check';

SELECT COUNT(*) AS `invalid_scope_rows`
FROM `spot_listing_channel_items`
WHERE `product_scope` NOT IN (
  'cex_spot',
  'cex_special_orderbook',
  'managed_onchain',
  'pre_market_spot',
  'pre_market_otc',
  'pre_market_futures',
  'launchpad',
  'tokenized_security'
);

SELECT `product_scope`, COUNT(*) AS `row_count`
FROM `spot_listing_channel_items`
GROUP BY `product_scope`
ORDER BY `product_scope`;

SHOW CREATE TABLE `spot_listing_channel_items`;
