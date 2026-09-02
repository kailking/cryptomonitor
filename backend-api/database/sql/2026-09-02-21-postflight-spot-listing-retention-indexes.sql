-- Read-only verification. Required result: both rows report column_list
-- exactly "event_at_ms,id".
SELECT `table_name`, `index_name`,
       GROUP_CONCAT(`column_name` ORDER BY `seq_in_index` SEPARATOR ',') AS `column_list`
FROM `information_schema`.`statistics`
WHERE `table_schema` = DATABASE()
  AND (
    (`table_name` = 'spot_listing_events'
      AND `index_name` = 'spot_listing_events_retention_time_index')
    OR
    (`table_name` = 'spot_listing_channel_events'
      AND `index_name` = 'spot_listing_channel_events_retention_time_index')
  )
GROUP BY `table_name`, `index_name`
ORDER BY `table_name`;
