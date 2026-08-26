-- Read-only postflight. Both rows must report InnoDB before the dedicated
-- announcement writer is enabled.
SELECT `table_name`, `engine`, `table_collation`
FROM `information_schema`.`tables`
WHERE `table_schema` = DATABASE()
  AND `table_name` IN (
    'spot_listing_announcement_candidate_sets',
    'spot_listing_announcement_candidates'
  )
ORDER BY `table_name`;

SHOW CREATE TABLE `spot_listing_announcement_candidate_sets`;
SHOW CREATE TABLE `spot_listing_announcement_candidates`;
