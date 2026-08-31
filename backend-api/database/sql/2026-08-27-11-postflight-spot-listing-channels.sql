-- Read-only postflight. Each table must expose both channel columns before the
-- updated discovery watcher starts.
SELECT `table_name`, `column_name`, `column_type`, `is_nullable`, `column_default`
FROM `information_schema`.`columns`
WHERE `table_schema` = DATABASE()
  AND `table_name` IN (
    'spot_listing_market_states',
    'spot_listing_instruments'
  )
  AND `column_name` IN ('listing_channel', 'listing_tags_json')
ORDER BY `table_name`, `ordinal_position`;

SHOW CREATE TABLE `spot_listing_market_states`;
SHOW CREATE TABLE `spot_listing_instruments`;
