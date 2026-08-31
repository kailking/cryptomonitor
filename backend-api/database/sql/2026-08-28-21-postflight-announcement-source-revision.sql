-- Postflight for announcement revision ordering. Both new columns and checks
-- must exist before the updated watcher is started.
SELECT `announcement_event_id`, `source_content_hash`,
       `source_revision_token`, `candidate_set_hash`,
       `candidates_authoritative`, `candidates_complete`,
       `projection_invalidated`, `created_at`, `updated_at`
FROM `spot_listing_announcement_candidate_sets`
LIMIT 0;

SELECT COUNT(*) AS `invalid_revision_rows`
FROM `spot_listing_announcement_candidate_sets`
WHERE (`source_revision_token` IS NOT NULL AND `source_revision_token` = 0)
   OR `projection_invalidated` NOT IN (0, 1);
