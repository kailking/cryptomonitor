-- Read-only postflight for the bounded lifecycle index and announcement
-- identity projection. Every invalid_* count must be zero before release.
SHOW INDEX FROM `spot_listing_events`
WHERE `Key_name` = 'spot_listing_events_instrument_type_time_index';

SELECT COUNT(*) AS `invalid_announcement_link_instrument_identity`
FROM `spot_listing_announcement_links` AS `link_row`
LEFT JOIN `spot_listing_instruments` AS `instrument`
  ON `instrument`.`id` = `link_row`.`instrument_id`
WHERE `link_row`.`instrument_id` IS NOT NULL
  AND (
    `instrument`.`id` IS NULL
    OR `instrument`.`platform_id` <> `link_row`.`platform_id`
    OR BINARY `instrument`.`symbol` <> BINARY `link_row`.`symbol`
  );

SELECT COUNT(*) AS `invalid_announcement_link_parent_identity`
FROM `spot_listing_announcement_links` AS `link_row`
JOIN `spot_listing_announcement_events` AS `event_row`
  ON `event_row`.`id` = `link_row`.`announcement_event_id`
WHERE `event_row`.`platform_id` <> `link_row`.`platform_id`;

SELECT COUNT(*) AS `invalid_orphan_announcement_candidate_rows`
FROM `spot_listing_announcement_candidates` AS `candidate`
LEFT JOIN `spot_listing_announcement_candidate_sets` AS `candidate_set`
  ON `candidate_set`.`announcement_event_id` =
     `candidate`.`announcement_event_id`
WHERE `candidate_set`.`announcement_event_id` IS NULL;
