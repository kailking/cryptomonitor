-- Read-only postflight. Both counts must be zero before the new watcher and
-- Laravel release are started.

SELECT COUNT(*) AS `invalid_announcement_poll_health_rows`
FROM `spot_listing_announcement_poll_checkpoints`
WHERE `platform_id` NOT IN (2, 3, 4, 5, 8)
   OR CHAR_LENGTH(TRIM(`feed_key`)) = 0
   OR `last_attempt_at_ms` = 0
   OR `poll_interval_ms` NOT BETWEEN 5000 AND 900000
   OR (`last_success_at_ms` IS NOT NULL
       AND `last_success_at_ms` > `last_attempt_at_ms`)
   OR (`last_failure_at_ms` IS NOT NULL
       AND `last_failure_at_ms` > `last_attempt_at_ms`)
   OR (`consecutive_failures` = 0 AND `last_error` IS NOT NULL)
   OR (`consecutive_failures` > 0 AND (
       `last_failure_at_ms` <> `last_attempt_at_ms`
       OR `last_error` IS NULL
       OR CHAR_LENGTH(TRIM(`last_error`)) = 0
   ));

SELECT COUNT(*) AS `unexpected_announcement_poll_health_feeds`
FROM `spot_listing_announcement_poll_checkpoints`
WHERE NOT (
  (`platform_id` = 2 AND `feed_key` = 'official:new-listings')
  OR (`platform_id` = 3 AND `feed_key` = 'official:new-listings:okx-help-ssr-v1')
  OR (`platform_id` IN (4, 5, 8) AND `feed_key` = 'official:new-listings')
);

SHOW CREATE TABLE `spot_listing_announcement_poll_checkpoints`;
