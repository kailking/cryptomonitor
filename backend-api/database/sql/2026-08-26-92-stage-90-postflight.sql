-- Read-only rollback postflight. The result must be zero.
SELECT COUNT(*) AS `remaining_candidate_tables`
FROM `information_schema`.`tables`
WHERE `table_schema` = DATABASE()
  AND `table_name` IN (
    'spot_listing_announcement_candidate_sets',
    'spot_listing_announcement_candidates'
  );
