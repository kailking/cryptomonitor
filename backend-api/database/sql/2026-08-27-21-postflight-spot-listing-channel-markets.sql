-- Read-only postflight for the isolated early-market ledger.
SELECT `table_name`, `engine`, `table_collation`
FROM `information_schema`.`tables`
WHERE `table_schema` = DATABASE()
  AND `table_name` IN (
    'spot_listing_channel_checkpoints',
    'spot_listing_channel_items',
    'spot_listing_channel_events'
  )
ORDER BY `table_name`;

SHOW CREATE TABLE `spot_listing_channel_checkpoints`;
SHOW CREATE TABLE `spot_listing_channel_items`;
SHOW CREATE TABLE `spot_listing_channel_events`;

-- Both rows must report utf8mb4_bin. Provider identities are case-sensitive;
-- a general_ci collation can silently collapse two official Gate Alpha IDs.
SELECT `table_name`, `column_name`, `collation_name`
FROM `information_schema`.`columns`
WHERE `table_schema` = DATABASE()
  AND `table_name` IN (
    'spot_listing_channel_items',
    'spot_listing_channel_events'
  )
  AND `column_name` = 'provider_item_id'
ORDER BY `table_name`;
