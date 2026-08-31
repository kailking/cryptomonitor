<?php

namespace Tests\Unit;

use Tests\TestCase;

class SpotListingDiscoverySqlTest extends TestCase
{
    public function test_forward_sql_contains_only_discovery_and_source_health_tables(): void
    {
        $paths = [
            '2026-08-16-01-create-spot-listings.sql',
            '2026-08-20-01-create-spot-listing-announcements.sql',
            '2026-08-21-01-create-spot-listing-announcement-localizations.sql',
            '2026-08-25-02-add-spot-listing-market-health.sql',
            '2026-08-26-05-add-spot-listing-announcement-localization-health.sql',
            '2026-08-26-08-stage-10-create-candidate-sets.sql',
            '2026-08-26-08-stage-20-create-candidates.sql',
            '2026-08-27-10-add-spot-listing-channels.sql',
            '2026-08-27-20-create-spot-listing-channel-markets.sql',
            '2026-08-28-20-add-announcement-source-revision.sql',
            '2026-08-28-30-add-spot-listing-missing-identity.sql',
            '2026-08-28-40-create-announcement-poll-health.sql',
            '2026-08-28-50-add-channel-identity-progress.sql',
            '2026-08-31-10-add-spot-listing-event-type-time-index.sql',
        ];
        $sql = '';
        foreach ($paths as $path) {
            $fullPath = database_path('sql/'.$path);
            $this->assertFileExists($fullPath);
            $sql .= "\n".file_get_contents($fullPath);
        }
        $normalized = strtolower($sql);

        foreach ([
            'spot_listing_market_states',
            'spot_listing_instruments',
            'spot_listing_events',
            'spot_listing_market_checkpoints',
            'spot_listing_announcement_checkpoints',
            'spot_listing_announcement_events',
            'spot_listing_announcement_links',
            'spot_listing_announcement_localizations',
            'spot_listing_announcement_localization_checkpoints',
            'spot_listing_announcement_poll_checkpoints',
            'spot_listing_announcement_candidate_sets',
            'spot_listing_announcement_candidates',
            'spot_listing_channel_checkpoints',
            'spot_listing_channel_items',
            'spot_listing_channel_events',
        ] as $requiredTable) {
            $this->assertStringContainsString(
                'create table `'.$requiredTable.'`',
                $normalized,
                $requiredTable
            );
        }

        foreach ([
            'spot_listing_outbox',
            'spot_listing_user_states',
            'cmd2',
            'command_dlq',
            'depth_confirmed',
            'depth_relay',
            'subscribe_failed',
            'subscription_',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $normalized);
        }

        $this->assertStringContainsString("'discovered'", $normalized);
        $this->assertStringContainsString("'trading_enabled'", $normalized);
        $this->assertStringContainsString("'trading_disabled'", $normalized);
        $this->assertStringContainsString("'metadata_changed'", $normalized);
        $this->assertStringContainsString(
            'spot_listing_announcement_events_detected_index',
            $normalized
        );
        $this->assertStringContainsString(
            'spot_listing_announcement_events_planned_start_index',
            $normalized
        );
        $this->assertStringContainsString(
            'spot_listing_announcement_candidates_planned_start_index',
            $normalized
        );
        $this->assertSame(2, substr_count(
            $normalized,
            'add column `listing_channel`'
        ));
        $this->assertSame(2, substr_count(
            $normalized,
            'add column `listing_tags_json`'
        ));
        $this->assertSame(2, substr_count(
            $normalized,
            "default 'special_unclassified'"
        ));
        $this->assertStringNotContainsString(
            "listing_channel` varchar(64) not null default 'standard'",
            $normalized
        );
        $channelSql = strtolower((string) file_get_contents(database_path(
            'sql/2026-08-27-20-create-spot-listing-channel-markets.sql'
        )));
        foreach (['spot_listing_channel_items', 'spot_listing_channel_events'] as $table) {
            $matches = [];
            $this->assertSame(1, preg_match(
                '/create table `'.preg_quote($table, '/').'`\s*\((.*?)\)\s*engine=/is',
                $channelSql,
                $matches
            ));
            $this->assertSame(
                1,
                preg_match(
                    '/`provider_item_id` varchar\(191\) character set utf8mb4\s+'.
                    'collate utf8mb4_bin not null/is',
                    $matches[1]
                ),
                $table.' provider identity must remain byte-sensitive'
            );
        }
        $this->assertStringContainsString(
            'add column `missing_identity_fingerprint`',
            $normalized
        );
        $this->assertStringContainsString(
            'add column `missing_identity_count`',
            $normalized
        );
        $this->assertStringContainsString(
            'add column `identity_candidate_fingerprint`',
            $normalized
        );
        $this->assertStringContainsString(
            'add column `identity_candidate_count`',
            $normalized
        );
        $this->assertStringContainsString(
            'add column `source_revision_token` bigint unsigned null',
            $normalized
        );
        $this->assertStringContainsString(
            'add column `projection_invalidated` tinyint(1) not null default 0',
            $normalized
        );
        $this->assertStringContainsString(
            'spot_listing_events_instrument_type_time_index',
            $normalized
        );
    }

    public function test_candidate_ddl_is_staged_and_does_not_scan_existing_events(): void
    {
        $setSql = file_get_contents(database_path(
            'sql/2026-08-26-08-stage-10-create-candidate-sets.sql'
        ));
        $candidateSql = file_get_contents(database_path(
            'sql/2026-08-26-08-stage-20-create-candidates.sql'
        ));
        $sql = strtolower($setSql."\n".$candidateSql);

        $this->assertStringContainsString('lock_wait_timeout` = 5', $sql);
        $this->assertStringContainsString('innodb_lock_wait_timeout` = 5', $sql);
        $this->assertStringNotContainsString('insert into', $sql);
        $this->assertStringNotContainsString('select *', $sql);
        $this->assertStringNotContainsString('alter table', $sql);
    }

    public function test_projection_recency_uses_timezone_neutral_utc_datetime_math(): void
    {
        $source = (string) file_get_contents(app_path(
            'Services/SpotListingDiscoveryService.php'
        ));

        $this->assertStringContainsString(
            'TIMESTAMPDIFF(MICROSECOND, ',
            $source
        );
        $this->assertStringContainsString(
            "'1970-01-01 00:00:00'",
            $source
        );
        $this->assertNotRegExp(
            '/UNIX_TIMESTAMP\s*\(/',
            $source
        );
    }

    public function test_missing_identity_postflight_is_read_only(): void
    {
        $path = database_path(
            'sql/2026-08-28-31-postflight-spot-listing-missing-identity.sql'
        );
        $this->assertFileExists($path);
        $sql = strtolower(file_get_contents($path));

        $this->assertStringContainsString(
            'invalid_missing_identity_checkpoints',
            $sql
        );
        $this->assertStringContainsString(
            'show create table `spot_listing_market_states`',
            $sql
        );
        foreach (['insert ', 'update ', 'delete ', 'alter ', 'drop ', 'truncate '] as $mutation) {
            $this->assertStringNotContainsString($mutation, $sql);
        }
    }

    public function test_announcement_revision_migration_has_postflight_and_scoped_rollback(): void
    {
        $postflight = strtolower((string) file_get_contents(database_path(
            'sql/2026-08-28-21-postflight-announcement-source-revision.sql'
        )));
        $rollback = strtolower((string) file_get_contents(database_path(
            'sql/2026-08-28-88-drop-announcement-source-revision.sql'
        )));

        $this->assertStringContainsString('source_revision_token', $postflight);
        $this->assertStringContainsString('projection_invalidated', $postflight);
        $this->assertStringContainsString('invalid_revision_rows', $postflight);
        $this->assertStringContainsString(
            'drop check `spot_listing_announcement_candidate_sets_revision_check`',
            $rollback
        );
        $this->assertStringContainsString(
            'drop check `spot_listing_announcement_candidate_sets_projection_check`',
            $rollback
        );
        $this->assertStringNotContainsString('drop table', $rollback);
    }

    public function test_announcement_poll_health_has_read_only_postflight_and_scoped_rollback(): void
    {
        $forward = strtolower((string) file_get_contents(database_path(
            'sql/2026-08-28-40-create-announcement-poll-health.sql'
        )));
        $postflight = strtolower((string) file_get_contents(database_path(
            'sql/2026-08-28-41-postflight-announcement-poll-health.sql'
        )));
        $rollback = strtolower((string) file_get_contents(database_path(
            'sql/2026-08-28-87-drop-announcement-poll-health.sql'
        )));

        $this->assertStringContainsString(
            'create table `spot_listing_announcement_poll_checkpoints`',
            $forward
        );
        foreach ([
            'last_attempt_at_ms', 'last_success_at_ms',
            'last_failure_at_ms', 'consecutive_failures',
            'poll_interval_ms', 'last_error',
        ] as $column) {
            $this->assertStringContainsString('`'.$column.'`', $forward);
        }
        $this->assertStringContainsString(
            'invalid_announcement_poll_health_rows',
            $postflight
        );
        $this->assertStringContainsString(
            'unexpected_announcement_poll_health_feeds',
            $postflight
        );
        foreach (['insert ', 'update ', 'delete ', 'alter ', 'drop ', 'truncate '] as $mutation) {
            $this->assertStringNotContainsString($mutation, $postflight);
        }
        $this->assertStringContainsString(
            'drop table if exists `spot_listing_announcement_poll_checkpoints`',
            $rollback
        );
        $this->assertStringNotContainsString('spot_listing_announcement_events', $rollback);
    }

    public function test_channel_identity_progress_has_read_only_postflight_and_scoped_rollback(): void
    {
        $postflight = strtolower((string) file_get_contents(database_path(
            'sql/2026-08-28-51-postflight-channel-identity-progress.sql'
        )));
        $rollback = strtolower((string) file_get_contents(database_path(
            'sql/2026-08-28-86-drop-channel-identity-progress.sql'
        )));

        $this->assertStringContainsString(
            'invalid_channel_identity_checkpoints',
            $postflight
        );
        $this->assertStringContainsString(
            'show create table `spot_listing_channel_checkpoints`',
            $postflight
        );
        foreach (['insert ', 'update ', 'delete ', 'alter ', 'drop ', 'truncate '] as $mutation) {
            $this->assertStringNotContainsString($mutation, $postflight);
        }
        $this->assertStringContainsString(
            'drop check `spot_listing_channel_checkpoints_identity_check`',
            $rollback
        );
        $this->assertStringContainsString(
            'drop column `identity_candidate_count`',
            $rollback
        );
        $this->assertStringNotContainsString('drop table', $rollback);
    }

    public function test_projection_integrity_has_read_only_postflight_and_scoped_rollback(): void
    {
        $forward = strtolower((string) file_get_contents(database_path(
            'sql/2026-08-31-10-add-spot-listing-event-type-time-index.sql'
        )));
        $postflight = strtolower((string) file_get_contents(database_path(
            'sql/2026-08-31-11-postflight-announcement-projection-integrity.sql'
        )));
        $rollback = strtolower((string) file_get_contents(database_path(
            'sql/2026-08-31-89-drop-spot-listing-event-type-time-index.sql'
        )));

        $this->assertStringContainsString(
            'add index `spot_listing_events_instrument_type_time_index`',
            $forward
        );
        $this->assertStringContainsString(
            'invalid_announcement_link_instrument_identity',
            $postflight
        );
        $this->assertStringContainsString(
            'invalid_announcement_link_parent_identity',
            $postflight
        );
        $this->assertStringContainsString(
            'invalid_orphan_announcement_candidate_rows',
            $postflight
        );
        foreach (['insert ', 'update ', 'delete ', 'alter ', 'drop ', 'truncate '] as $mutation) {
            $this->assertStringNotContainsString($mutation, $postflight);
        }
        $this->assertStringContainsString(
            'drop index `spot_listing_events_instrument_type_time_index`',
            $rollback
        );
        $this->assertStringNotContainsString('drop table', $rollback);
    }

    public function test_rollbacks_drop_only_discovery_tables_in_dependency_order(): void
    {
        $paths = [
            '2026-08-31-89-drop-spot-listing-event-type-time-index.sql',
            '2026-08-28-86-drop-channel-identity-progress.sql',
            '2026-08-28-87-drop-announcement-poll-health.sql',
            '2026-08-28-89-drop-spot-listing-missing-identity.sql',
            '2026-08-28-88-drop-announcement-source-revision.sql',
            '2026-08-27-80-drop-spot-listing-channel-markets.sql',
            '2026-08-27-90-drop-spot-listing-channels.sql',
            '2026-08-26-92-stage-10-drop-candidates.sql',
            '2026-08-26-92-stage-20-drop-candidate-sets.sql',
            '2026-08-26-95-drop-spot-listing-announcement-localization-health.sql',
            '2026-08-25-98-drop-spot-listing-market-health.sql',
            '2026-08-21-99-drop-spot-listing-announcement-localizations.sql',
            '2026-08-20-99-drop-spot-listing-announcements.sql',
            '2026-08-16-99-drop-spot-listings.sql',
        ];
        $sql = '';
        foreach ($paths as $path) {
            $fullPath = database_path('sql/'.$path);
            $this->assertFileExists($fullPath);
            $sql .= "\n".file_get_contents($fullPath);
        }
        $normalized = strtolower($sql);

        $this->assertStringNotContainsString('users', $normalized);
        $this->assertStringNotContainsString('currency_quotation', $normalized);
        $this->assertStringNotContainsString('market_depth', $normalized);
        $this->assertStringContainsString(
            'drop column `missing_identity_count`',
            $normalized
        );
        $this->assertStringContainsString(
            'drop column `missing_identity_fingerprint`',
            $normalized
        );
        $this->assertStringContainsString(
            'drop column `identity_candidate_fingerprint`',
            $normalized
        );
    }
}
